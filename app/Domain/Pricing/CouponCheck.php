<?php

namespace App\Domain\Pricing;

use App\Models\Coupon;

/** Result of validating a coupon code against an order context. */
final class CouponCheck
{
    public const OK = 'ok';

    public const NOT_FOUND = 'not_found';

    public const INACTIVE = 'inactive';

    public const NOT_STARTED = 'not_started';

    public const EXPIRED = 'expired';

    public const SERVICE_NOT_ELIGIBLE = 'service_not_eligible';

    public const MIN_AMOUNT = 'min_amount';

    public const MAX_USES = 'max_uses';

    public const PER_EMAIL_LIMIT = 'per_email_limit';

    public const FIRST_TIME_ONLY = 'first_time_only';

    public const CUSTOMER_RESTRICTED = 'customer_restricted';

    public const CURRENCY_MISMATCH = 'currency_mismatch';

    public function __construct(
        public readonly string $status,
        public readonly ?Coupon $coupon = null,
        public readonly string $message = '',
    ) {}

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    public static function fail(string $status, string $message, ?Coupon $coupon = null): self
    {
        return new self($status, $coupon, $message);
    }
}
