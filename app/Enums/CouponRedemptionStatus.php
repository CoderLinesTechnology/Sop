<?php

namespace App\Enums;

/**
 * A coupon use is reserved when payment starts (so concurrent checkouts cannot
 * exceed usage limits), redeemed when payment is verified, and released when
 * the payment fails or expires.
 */
enum CouponRedemptionStatus: string
{
    case Reserved = 'reserved';
    case Redeemed = 'redeemed';
    case Released = 'released';
}
