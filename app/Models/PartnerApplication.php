<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A "Become a partner" application. It counts (status "submitted") only
 * after the applicant confirmed their email; administrators then approve it
 * (which creates the Referrer) or reject it. Status, token, decision and
 * referrer columns are written by App\Domain\Referrals\PartnerApplications only.
 */
#[RouteKey('public_id')]
#[Fillable([
    'full_name', 'email', 'phone', 'city', 'country_code', 'promotion_channels', 'promotion_details',
    'audience_size', 'profile_links', 'motivation', 'preferred_code',
])]
#[Hidden(['confirm_token_hash'])]
class PartnerApplication extends Model
{
    public const STATUS_UNCONFIRMED = 'unconfirmed';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const CHANNELS = [
        'whatsapp' => 'WhatsApp groups',
        'social_media' => 'Social media',
        'campus' => 'Campus or school',
        'community' => 'Church or community groups',
        'blog' => 'Blog or website',
        'other' => 'Other',
    ];

    public const AUDIENCE_SIZES = [
        'under_100' => 'Fewer than 100 people',
        '100_500' => '100 – 500 people',
        '500_2000' => '500 – 2,000 people',
        '2000_10000' => '2,000 – 10,000 people',
        'over_10000' => 'More than 10,000 people',
    ];

    protected $attributes = [
        'status' => self::STATUS_UNCONFIRMED,
    ];

    protected function casts(): array
    {
        return [
            'promotion_channels' => 'array',
            'profile_links' => 'array',
            'consented_at' => 'datetime',
            'confirm_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PartnerApplication $application) => $application->public_id ??= (string) Str::ulid());
        static::saving(function (PartnerApplication $application) {
            $application->email = Str::lower(trim((string) $application->email));
        });
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class)->withTrashed();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? ucfirst((string) $this->status);
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_UNCONFIRMED => 'Email not confirmed',
            self::STATUS_SUBMITTED => 'Waiting for review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
        ];
    }

    /** @return list<string> */
    public function channelLabels(): array
    {
        return array_values(array_map(fn (string $key) => self::CHANNELS[$key] ?? $key, (array) $this->promotion_channels));
    }
}
