<?php

namespace App\Domain\Seo;

use App\Support\Runtime\AfterResponse;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tells IndexNow search engines (Bing — which also feeds ChatGPT search —,
 * Yandex, Seznam, Naver...) about pages that were added, changed or removed,
 * so they are recrawled within minutes instead of whenever the crawler next
 * passes by. Google does not use IndexNow; it reads the sitemap's lastmod.
 *
 * Changed URLs are recorded in search_pings (one row per URL), sent right
 * after the response, and retried by the heartbeat (seo.indexnow) when the
 * service is unavailable or the page is scheduled for later. The endpoint is
 * fixed and only this site's own URLs are ever sent.
 */
class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** URLs per request (IndexNow accepts up to 10,000). */
    private const BATCH = 500;

    private const MAX_ATTEMPTS = 8;

    /** A claim older than this was interrupted and is released. */
    private const STALE_CLAIM_MINUTES = 15;

    /**
     * The site's key: derived from APP_KEY, so it is stable without being
     * stored. It is public by design (served at /{key}.txt).
     */
    public function key(): string
    {
        return substr(hash_hmac('sha256', 'indexnow', (string) config('app.key')), 0, 32);
    }

    public function enabled(): bool
    {
        return (bool) Settings::get('seo.indexnow_enabled', true)
            && (app()->isProduction() || (bool) config('statementra.seo.indexnow_outside_production', false));
    }

    /**
     * Record that these pages changed. They are sent after the response, or
     * from $notBefore on (an article scheduled for later).
     *
     * @param  list<string>  $urls
     */
    public function queue(array $urls, ?CarbonInterface $notBefore = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $host = $this->host();
        $now = now();
        $rows = [];
        foreach (array_unique($urls) as $url) {
            if (parse_url($url, PHP_URL_HOST) !== $host || mb_strlen($url) > 2000) {
                continue; // only this site's own pages
            }
            $rows[] = [
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'status' => 'pending',
                'attempts' => 0,
                'claim' => null,
                'due_at' => $notBefore?->copy()->utc(),
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        // One row per URL: a URL changed again before it was sent is simply pending again.
        DB::table('search_pings')->upsert($rows, ['url_hash'], ['status', 'attempts', 'claim', 'due_at', 'last_error', 'updated_at']);

        if ($notBefore === null || $notBefore->isPast()) {
            AfterResponse::run('seo.indexnow', fn () => $this->sendDue(), timeLimitSeconds: 60);
        }
    }

    /** Send every due URL; returns how many search engines accepted. */
    public function sendDue(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        DB::table('search_pings')
            ->where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->update(['status' => 'pending', 'claim' => null, 'updated_at' => now()]);

        $sent = 0;
        for ($round = 0; $round < 5; $round++) {
            $claim = Str::random(32);
            $ids = DB::table('search_pings')
                ->where('status', 'pending')
                ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<=', now()))
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // Claim first: overlapping requests never send the same row twice.
            DB::table('search_pings')->whereIn('id', $ids)->where('status', 'pending')
                ->update(['status' => 'sending', 'claim' => $claim, 'updated_at' => now()]);
            $rows = DB::table('search_pings')->where('claim', $claim)->get(['id', 'url', 'attempts']);
            if ($rows->isEmpty()) {
                continue;
            }

            $sent += $this->send($rows->all());
        }

        return $sent;
    }

    /** @param list<object{id:int, url:string, attempts:int}> $rows */
    private function send(array $rows): int
    {
        $ids = array_map(fn ($row) => $row->id, $rows);
        $key = $this->key();

        try {
            $response = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->acceptJson()->asJson()
                ->post(self::ENDPOINT, [
                    'host' => $this->host(),
                    'key' => $key,
                    'keyLocation' => route('indexnow.key', ['key' => $key]),
                    'urlList' => array_map(fn ($row) => $row->url, $rows),
                ]);
        } catch (ConnectionException $e) {
            $this->retryLater($rows, 'connection: '.$e->getMessage());

            return 0;
        }

        if ($response->successful()) {
            DB::table('search_pings')->whereIn('id', $ids)
                ->update(['status' => 'sent', 'claim' => null, 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);

            return count($ids);
        }

        $error = 'HTTP '.$response->status().' '.mb_substr(trim($response->body()), 0, 180);
        if ($response->status() === 429 || $response->serverError()) {
            $this->retryLater($rows, $error);
        } else {
            // 400/403/422: the request itself is wrong (key not yet verifiable, URL refused): do not hammer.
            Log::warning('IndexNow refused URLs', ['status' => $response->status(), 'count' => count($ids)]);
            DB::table('search_pings')->whereIn('id', $ids)
                ->update(['status' => 'failed', 'claim' => null, 'last_error' => $error, 'updated_at' => now()]);
        }

        return 0;
    }

    /** @param list<object{id:int, attempts:int}> $rows */
    private function retryLater(array $rows, string $error): void
    {
        foreach ($rows as $row) {
            $attempts = $row->attempts + 1;
            DB::table('search_pings')->where('id', $row->id)->update([
                'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                'attempts' => $attempts,
                'claim' => null,
                'due_at' => now()->addMinutes(15 * 2 ** min($attempts - 1, 5)),
                'last_error' => mb_substr($error, 0, 250),
                'updated_at' => now(),
            ]);
        }
    }

    private function host(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }
}
