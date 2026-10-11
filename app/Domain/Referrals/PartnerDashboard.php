<?php

namespace App\Domain\Referrals;

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\ReferralPayout;
use App\Models\ReferralVisit;
use App\Models\Referrer;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind a referrer's private dashboard (/partner/{token}).
 *
 * Returns plain arrays of counts and money only: no customer names, emails,
 * order references or ids ever leave this class. Every figure comes from a
 * handful of aggregate queries (three for the summary, one for a page of
 * orders with correlated sums), whatever the referrer's volume.
 */
final class PartnerDashboard
{
    public function __construct(private readonly ReferralBalances $balances) {}

    public const PER_PAGE = 20;

    /** Tokens are 48 random letters and digits (Referrer::issueDashboardToken()). */
    public const TOKEN_PATTERN = '[A-Za-z0-9]{48}';

    /** The active referrer whose dashboard link this is, or null (unknown, replaced, disabled or deactivated). */
    public function findByToken(string $token): ?Referrer
    {
        if (! preg_match('/^'.self::TOKEN_PATTERN.'$/', $token)) {
            return null;
        }

        $hash = Referrer::hashToken($token);
        $referrer = Referrer::query()->where('dashboard_token_hash', $hash)->first();

        // The lookup is by hash (an attacker cannot steer it prefix by prefix); the match is
        // confirmed in constant time without decrypting the stored token.
        if (! $referrer || ! hash_equals((string) $referrer->dashboard_token_hash, $hash) || ! $referrer->canAttribute()) {
            return null;
        }

        return $referrer;
    }

    /**
     * @return array{
     *     visits_30_days:int, visits_total:int, orders_started:int, paid_orders:int,
     *     money: list<array{currency:string, revenue:string, earned:string, pending:string, available:string, requested:string, paid:string, due:string, next_available:?string}>
     * }
     */
    public function summary(Referrer $referrer): array
    {
        $date = DB::getQueryGrammar()->wrap('date');
        $visits = ReferralVisit::query()
            ->where('referrer_id', $referrer->id)
            ->selectRaw("COALESCE(SUM(visits), 0) AS total, COALESCE(SUM(CASE WHEN {$date} >= ? THEN visits ELSE 0 END), 0) AS recent", [now()->subDays(29)->toDateString()])
            ->toBase()
            ->first();

        $notStarted = [OrderStatus::New->value, OrderStatus::FormSubmitted->value];
        $paid = [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value];
        $orders = Order::query()
            ->where('referrer_id', $referrer->id)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status NOT IN (?, ?) THEN 1 ELSE 0 END), 0) AS started, '
                .'COALESCE(SUM(CASE WHEN payment_status IN (?, ?) THEN 1 ELSE 0 END), 0) AS paid',
                [...$notStarted, ...$paid],
            )
            ->toBase()
            ->first();

        $money = array_map(fn (array $row): array => [
            'currency' => $row['currency'],
            'revenue' => Money::format($row['revenue'], $row['currency']),
            'earned' => Money::format($row['earned'], $row['currency']),
            'pending' => Money::format($row['pending'], $row['currency']),
            'available' => Money::format($row['available'], $row['currency']),
            'requested' => Money::format($row['requested'], $row['currency']),
            'paid' => Money::format($row['paid'], $row['currency']),
            'due' => Money::format($row['earned'] - $row['paid'], $row['currency']),
            'next_available' => $row['next_available_at']?->format('j M Y'),
        ], $this->balances->forReferrer($referrer));

        return [
            'visits_30_days' => (int) ($visits->recent ?? 0),
            'visits_total' => (int) ($visits->total ?? 0),
            'orders_started' => (int) ($orders->started ?? 0),
            'paid_orders' => (int) ($orders->paid ?? 0),
            'money' => $money,
        ];
    }

    /**
     * Paid (or later refunded) orders, newest first: date, service, amount
     * kept after refunds, commission and status. Nothing that identifies the
     * customer or the order.
     *
     * @return LengthAwarePaginator<int, array{date:string, service:string, amount:string, commission:string, status:string}>
     */
    public function orders(Referrer $referrer): LengthAwarePaginator
    {
        $moneyStatuses = [PaymentRecordStatus::Success->value, PaymentRecordStatus::PartiallyRefunded->value, PaymentRecordStatus::Refunded->value];

        return Order::query()
            ->where('referrer_id', $referrer->id)
            ->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
            ->select(['id', 'created_at', 'service_snapshot', 'payment_status', 'currency'])
            ->withSum(['payments as paid_total' => fn ($query) => $query->whereIn('status', $moneyStatuses)], 'amount')
            ->withSum(['refunds as refunded_total' => fn ($query) => $query->where('status', RefundStatus::Processed->value)], 'amount')
            ->withSum(['referralCommissions as commission_total' => fn ($query) => $query->where('referrer_id', $referrer->id)], 'amount')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (Order $order): array => [
                'date' => $order->created_at->format('j M Y'),
                'service' => (string) (data_get($order->service_snapshot, 'name') ?: 'Application document'),
                'amount' => Money::format(max(0, (int) $order->paid_total - (int) $order->refunded_total), $order->currency),
                'commission' => Money::format((int) $order->commission_total, $order->currency),
                'status' => match ($order->payment_status) {
                    PaymentStatus::Refunded => 'Refunded',
                    PaymentStatus::PartiallyRefunded => 'Partially refunded',
                    default => 'Paid',
                },
            ]);
    }

    /**
     * The referrer's latest payout requests (bounded), newest first. The public
     * id only addresses "cancel" on the referrer's own page.
     *
     * @return list<array{id:string, date:string, amount:string, status:string, reference:?string, reason:?string, open:bool}>
     */
    public function payouts(Referrer $referrer, int $limit = 24): array
    {
        return ReferralPayout::query()
            ->where('referrer_id', $referrer->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'public_id', 'amount', 'currency', 'status', 'requested_at', 'created_at', 'payout_reference', 'rejection_reason'])
            ->map(fn (ReferralPayout $payout): array => [
                'id' => $payout->public_id,
                'date' => ($payout->requested_at ?? $payout->created_at)->format('j M Y'),
                'amount' => Money::format($payout->amount, $payout->currency, true),
                'status' => $payout->statusLabel(),
                'reference' => $payout->payout_reference,
                'reason' => $payout->rejection_reason,
                'open' => $payout->isOpen(),
            ])
            ->all();
    }
}
