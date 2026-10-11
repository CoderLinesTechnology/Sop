<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A referral partner: someone who shares a link (or a code) and earns an
 * agreed commission on the income their referrals bring.
 *
 * The code is normalised exactly like a coupon code and may not collide with
 * one. The private dashboard link carries a random token: the token itself is
 * kept encrypted (so an administrator can copy and re-send the link) and its
 * SHA-256 hash is what the dashboard looks up. Payment details (mobile money
 * or bank) are encrypted; a change waits in pending_payment_details until the
 * referrer confirms it from their email. Token and hash, payment details, the
 * public id and the erasure stamp are not mass-assignable; App\Domain\Referrals
 * owns them.
 */
#[RouteKey('public_id')]
#[Fillable(['name', 'email', 'phone', 'code', 'commission_rate_bp', 'is_active', 'notes'])]
#[Hidden(['dashboard_token', 'dashboard_token_hash', 'payment_details', 'pending_payment_details', 'pending_details_token_hash'])]
class Referrer extends Model
{
    use SoftDeletes;

    /** 100% in basis points. */
    public const MAX_RATE_BP = 10000;

    protected $attributes = [
        'commission_rate_bp' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'commission_rate_bp' => 'integer',
            'is_active' => 'boolean',
            'dashboard_token' => 'encrypted',
            'erased_at' => 'datetime',
            'payment_details' => 'encrypted:array',
            'payment_details_changed_at' => 'datetime',
            'pending_payment_details' => 'encrypted:array',
            'pending_details_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Referrer $referrer) {
            $referrer->public_id ??= (string) Str::ulid();
            if ($referrer->dashboard_token === null && $referrer->erased_at === null) {
                $referrer->issueDashboardToken();
            }
        });

        static::saving(function (Referrer $referrer) {
            $referrer->code = self::normalizeCode((string) $referrer->code);
            $referrer->email = $referrer->email !== null ? (Str::lower(trim($referrer->email)) ?: null) : null;
            $referrer->commission_rate_bp = max(0, min(self::MAX_RATE_BP, (int) $referrer->commission_rate_bp));
        });
    }

    /** Same alphabet and casing as coupon codes, so the two can be compared directly. */
    public static function normalizeCode(string $code): string
    {
        return Coupon::normalizeCode($code);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Sets a fresh dashboard token (not saved); the previous link stops working once saved. */
    public function issueDashboardToken(): string
    {
        $token = Str::random(48);
        $this->dashboard_token = $token;
        $this->dashboard_token_hash = self::hashToken($token);

        return $token;
    }

    // ----------------------------------------------------------------- relations

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ReferralVisit::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(ReferralPayout::class);
    }

    // ------------------------------------------------------------------- scopes

    /** Referrers whose code may attribute new orders. */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('erased_at');
    }

    // ------------------------------------------------------------------ helpers

    public function isErased(): bool
    {
        return $this->erased_at !== null;
    }

    public function canAttribute(): bool
    {
        return $this->is_active && ! $this->isErased() && ! $this->trashed();
    }

    public function hasPaymentDetails(): bool
    {
        return is_array($this->payment_details) && $this->payment_details !== [];
    }

    public function hasDashboardLink(): bool
    {
        return $this->dashboard_token_hash !== null;
    }

    /** e.g. 1250 → "12.5%". */
    public function formattedRate(): string
    {
        return self::formatRate($this->commission_rate_bp);
    }

    public static function formatRate(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.').'%';
    }

    /** The link a referrer shares: the home page carrying their code. */
    public function shareUrl(): string
    {
        return route('home', ['ref' => $this->code]);
    }

    /** The private dashboard link, or null when it has been disabled. */
    public function dashboardUrl(): ?string
    {
        return $this->dashboard_token ? route('partner.dashboard', $this->dashboard_token) : null;
    }
}
