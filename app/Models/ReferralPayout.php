<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * A referrer's payout request: their whole available balance at the time,
 * with the ledger entries it covers linked through referral_commissions.payout_id
 * and an encrypted snapshot of the payment details it is to be paid to.
 * Status changes only through App\Domain\Referrals\ReferralPayouts; payouts
 * are money records and are never deleted. Nothing is mass-assignable: the
 * domain service writes every column with forceFill().
 */
#[Guarded(['*'])]
#[RouteKey('public_id')]
#[Hidden(['payment_details'])]
class ReferralPayout extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'payment_details' => 'encrypted:array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (ReferralPayout $payout) => $payout->public_id ??= (string) Str::ulid());
        static::deleting(fn () => throw new LogicException('Payout requests are never deleted.'));
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class)->withTrashed();
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class, 'payout_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? ucfirst((string) $this->status);
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_REQUESTED => 'Requested',
            self::STATUS_PAID => 'Paid',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }
}
