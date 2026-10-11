<?php

namespace App\Domain\Referrals;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Referrer;
use App\Support\SecurityLog;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Records which referrer an order came from (orders.referrer_id,
 * referral_code, referral_source, referred_at). Nothing else writes these
 * columns, and none of it changes the price.
 *
 *  - The referral link (this session's link first, then the cookie the
 *    visitor agreed to) attributes the draft when it is created, and again at
 *    pay time if the order still has no referrer.
 *  - A code the customer types on the payment page overrides the link.
 *  - Attribution is written with a compare-and-set that only matches an
 *    unpaid, pre-payment order, so it never changes once the order is paid.
 *  - A customer who refers themselves is still recorded; the commission
 *    ledger simply earns nothing on that order.
 */
final class ReferralAttribution
{
    public const SOURCE_LINK = 'link';

    public const SOURCE_CODE = 'code';

    /** Attribute an order that has no referrer yet to the remembered link. Never throws. */
    public function fromLink(Order $order, Request $request): void
    {
        if ($order->referrer_id !== null || ! ReferralProgramme::enabled()) {
            return;
        }

        try {
            $referrer = ReferralProgramme::referrerForRequest($request);
            if ($referrer) {
                $this->attach($order, $referrer, self::SOURCE_LINK, override: false);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error('Referral link attribution failed', ['order' => $order->reference, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Pay time. An empty box keeps the link attribution; a known code
     * overrides it; an unknown code returns a friendly message for the form
     * (failures are rate-limited like coupon guesses).
     */
    public function atCheckout(Order $order, mixed $typedCode, Request $request): ?string
    {
        if (! ReferralProgramme::enabled()) {
            return null;
        }

        $code = is_string($typedCode) ? Referrer::normalizeCode($typedCode) : '';
        if ($code === '') {
            $this->fromLink($order, $request);

            return null;
        }

        $key = 'referral-failures:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, (int) Settings::get('security.rate_limit_coupon_attempts_per_hour', 15))) {
            return 'Too many referral code attempts. Please try again later, or leave the box empty.';
        }

        $referrer = ReferralProgramme::activeReferrer($code);
        if (! $referrer) {
            RateLimiter::hit($key, 3600);
            if (RateLimiter::attempts($key) === 10) {
                SecurityLog::record('referral_code_bruteforce', 'medium', ['attempts' => 10]);
            }

            return 'We couldn’t find that referral code. Check it with the person who referred you, or leave the box empty.';
        }

        if ($order->referrer_id === $referrer->id) {
            return null; // the prefilled code, unchanged
        }

        // The box is prefilled from the link: submitting it unchanged is still the link.
        $source = in_array($referrer->code, [ReferralProgramme::sessionCode($request), ReferralProgramme::cookieCode($request)], true) ? self::SOURCE_LINK : self::SOURCE_CODE;
        $this->attach($order, $referrer, $source, override: true);

        return null;
    }

    /** True when the order is past payment and its attribution is therefore fixed. */
    public static function isLocked(Order $order): bool
    {
        return ! $order->status->isPrePayment() || in_array($order->payment_status, self::paidStatuses(), true);
    }

    private function attach(Order $order, Referrer $referrer, string $source, bool $override): bool
    {
        $values = [
            'referrer_id' => $referrer->id,
            'referral_code' => $referrer->code,
            'referral_source' => $source,
            'referred_at' => now(),
        ];

        $query = Order::query()
            ->whereKey($order->getKey())
            ->whereIn('status', array_map(fn (OrderStatus $status) => $status->value, self::prePaymentStatuses()))
            ->whereNotIn('payment_status', array_map(fn (PaymentStatus $status) => $status->value, self::paidStatuses()));

        if (! $override) {
            $query->whereNull('referrer_id');
        }

        if ($query->update($values) !== 1) {
            return false;
        }

        $order->forceFill($values)->syncOriginalAttributes(array_keys($values));

        return true;
    }

    /** @return list<OrderStatus> */
    private static function prePaymentStatuses(): array
    {
        return array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->isPrePayment()));
    }

    /** @return list<PaymentStatus> */
    private static function paidStatuses(): array
    {
        return [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded];
    }
}
