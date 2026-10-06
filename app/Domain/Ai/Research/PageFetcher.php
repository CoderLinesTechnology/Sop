<?php

namespace App\Domain\Ai\Research;

use App\Support\SafeHttp\SafeHttpClient;
use App\Support\SafeHttp\SafeHttpException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Fetches research source pages through the SSRF-hardened SafeHttpClient and
 * returns their visible text (HTML or PDF). Hosts under reserved test TLDs
 * (.example, .test, .invalid, .localhost) are never fetched.
 */
class PageFetcher
{
    private const RESERVED_TLDS = ['example', 'test', 'invalid', 'localhost'];

    /** @var array<string, array> per-instance cache (one verification stage) */
    private array $cache = [];

    public function __construct(private readonly SafeHttpClient $http) {}

    /**
     * Status is one of: ok, http_error, blocked, failed, skipped.
     *
     * @return array{status:string, http_status:?int, text:?string, content_hash:?string, error:?string}
     */
    public function fetch(string $url): array
    {
        return $this->cache[$url] ??= $this->doFetch($url);
    }

    private function doFetch(string $url): array
    {
        $host = UrlNormalizer::host($url) ?? '';
        $tld = substr((string) strrchr($host, '.'), 1);
        if ($host === '' || in_array($tld, self::RESERVED_TLDS, true)) {
            return $this->result('skipped', error: 'Reserved or invalid host.');
        }

        // Only https is fetched; try the https form of an http URL.
        if (str_starts_with(strtolower($url), 'http://')) {
            $url = 'https://'.substr($url, 7);
        }

        try {
            $response = $this->http->get($url);
        } catch (SafeHttpException $e) {
            $blocked = str_contains($e->getMessage(), 'not allowed') || str_contains($e->getMessage(), 'non-public');

            return $this->result($blocked ? 'blocked' : 'failed', error: mb_substr($e->getMessage(), 0, 200));
        } catch (Throwable $e) {
            return $this->result('failed', error: mb_substr($e->getMessage(), 0, 200));
        }

        if (! $response->ok()) {
            return $this->result('http_error', $response->status, error: 'HTTP '.$response->status);
        }

        try {
            $text = $response->contentType === 'application/pdf'
                ? trim(preg_replace('/\s+/u', ' ', (new PdfParser)->parseContent($response->body)->getText()) ?? '')
                : $response->text();
        } catch (Throwable $e) {
            return $this->result('failed', $response->status, error: 'Could not read page text: '.mb_substr($e->getMessage(), 0, 150));
        }

        return $this->result('ok', $response->status, $text, hash('sha256', $response->body));
    }

    private function result(string $status, ?int $httpStatus = null, ?string $text = null, ?string $hash = null, ?string $error = null): array
    {
        return ['status' => $status, 'http_status' => $httpStatus, 'text' => $text, 'content_hash' => $hash, 'error' => $error];
    }
}
