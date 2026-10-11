<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a referrer's commission ledger, in integer minor units.
 *
 * Kinds: "payment" (an order's main payment), "revision_payment" (a paid
 * revision fee) and "refund_reversal" (negative, proportional to a processed
 * refund). Entries are written only by App\Domain\Referrals\ReferralCommissions
 * and are never deleted; the only later changes are the link to a payout
 * request (payout_id, cleared again if the request is rejected or cancelled)
 * and the payout stamp (paid_at, payout_reference, paid_by_admin_id).
 */
#[Fillable([
    'referrer_id', 'order_id', 'kind', 'payment_id', 'refund_id', 'base_amount', 'currency', 'rate_bp', 'amount',
])]
class ReferralCommission extends Model
{
    public const KIND_PAYMENT = 'payment';

    public const KIND_REVISION_PAYMENT = 'revision_payment';

    public const KIND_REFUND_REVERSAL = 'refund_reversal';

    protected function casts(): array
    {
        return [
            'base_amount' => 'integer',
            'rate_bp' => 'integer',
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Commission ledger entries are never deleted.'));
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(ReferralPayout::class, 'payout_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'paid_by_admin_id');
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? $this->kind;
    }

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return [
            self::KIND_PAYMENT => 'Order payment',
            self::KIND_REVISION_PAYMENT => 'Revision fee',
            self::KIND_REFUND_REVERSAL => 'Refund reversal',
        ];
    }
}
