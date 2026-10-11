<?php

namespace App\Domain\Referrals;

use App\Models\ReferralVisit;
use App\Models\Referrer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counts visits that arrive through a referral link: one per browser session,
 * referrer and day, in a daily aggregate (no IP address, user agent or other
 * visitor data is stored). Crawlers and link previews are not counted.
 */
final class ReferralVisits
{
    private const SESSION_KEY = 'referral_visits';

    public function record(Referrer $referrer, Request $request): void
    {
        if (ReferralProgramme::isBot($request)) {
            return;
        }

        $today = now()->toDateString();

        if ($request->hasSession()) {
            $counted = (array) $request->session()->get(self::SESSION_KEY, []);
            if (($counted[$referrer->code] ?? null) === $today) {
                return;
            }

            unset($counted[$referrer->code]);
            $counted[$referrer->code] = $today;
            $request->session()->put(self::SESSION_KEY, array_slice($counted, -10, null, true));
        }

        try {
            ReferralVisit::query()->upsert(
                [['referrer_id' => $referrer->id, 'date' => $today, 'visits' => 1]],
                ['referrer_id', 'date'],
                ['visits' => DB::raw('visits + 1')],
            );
        } catch (Throwable $e) {
            // A lost visit count must never break the page the visitor asked for.
            Log::warning('Referral visit not recorded', ['error' => $e->getMessage()]);
        }
    }
}
