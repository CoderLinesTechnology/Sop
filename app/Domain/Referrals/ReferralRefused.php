<?php

namespace App\Domain\Referrals;

use RuntimeException;

/** A referral-programme operation (payout, application, payment details) that is not allowed; the message is safe to show to the referrer or administrator. */
final class ReferralRefused extends RuntimeException {}
