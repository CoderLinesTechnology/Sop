<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * First-party, cookieless analytics event. visitor_hash is a truncated hash
 * of IP + user agent + a daily rotating salt, so visitors cannot be tracked
 * across days or re-identified.
 */
#[Table(timestamps: false)]
#[Fillable(['event', 'path', 'service_id', 'order_id', 'visitor_hash', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'device', 'country_code', 'value', 'meta', 'created_at'])]
class AnalyticsEvent extends Model
{
    public const PAGE_VIEW = 'page_view';
    public const SERVICE_VIEW = 'service_view';
    public const FORM_START = 'form_start';
    public const FORM_COMPLETE = 'form_complete';
    public const CHECKOUT_START = 'checkout_start';
    public const PAYMENT_SUCCESS = 'payment_success';
    public const PAYMENT_FAILED = 'payment_failed';
    public const COUPON_APPLIED = 'coupon_applied';
    public const NEWSLETTER_SIGNUP = 'newsletter_signup';

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
