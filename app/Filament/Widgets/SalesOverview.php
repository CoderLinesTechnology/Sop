<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Concerns\ReadsMetricsRange;
use App\Filament\Support\Operations\Format;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Revenue, paid orders, order value, pending payments, refunds and coupon usage. */
class SalesOverview extends StatsOverviewWidget
{
    use ReadsMetricsRange;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Sales';

    protected int|array|null $columns = ['@sm' => 2, '@xl' => 4];

    public static function canView(): bool
    {
        return AdminContext::can(Permission::DashboardView);
    }

    protected function getDescription(): ?string
    {
        return 'Last '.$this->rangeLabel().'. Money in '.$this->metrics()->currency.'.';
    }

    protected function getStats(): array
    {
        $metrics = $this->metrics();
        $sales = $metrics->sales();
        $orders = $metrics->orders();
        $refunds = $metrics->refunds();
        $coupons = $metrics->coupons();
        $trend = $metrics->dailyTrend();
        $aov = $metrics->averageOrderValue();

        $otherCurrencies = collect($sales['other_currencies'])
            ->map(fn (int $total, string $currency) => Money::format($total, $currency))
            ->implode(', ');

        return [
            Stat::make('Revenue', $metrics->money($sales['revenue_period']))
                ->description('All time: '.$metrics->money($sales['revenue_total']).($otherCurrencies ? ' · also '.$otherCurrencies : ''))
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->chart($trend['revenue'])
                ->color('success'),
            Stat::make('Orders', number_format($orders['submitted_period']))
                ->description('Reached checkout (unpaid drafts excluded)')
                ->descriptionIcon(Heroicon::OutlinedShoppingCart),
            Stat::make('Paid orders', number_format($sales['paid_orders_period']))
                ->description(number_format($sales['paid_orders_total']).' all time')
                ->descriptionIcon(Heroicon::OutlinedShoppingBag)
                ->chart($trend['orders'])
                ->color('primary'),
            Stat::make('Average order value', $aov === null ? Format::PLACEHOLDER : $metrics->money($aov))
                ->description('Captured payments in '.$metrics->currency)
                ->descriptionIcon(Heroicon::OutlinedCalculator),
            Stat::make('Pending payments', number_format($orders['pending_payments']))
                ->description('Orders waiting for payment confirmation now')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($orders['pending_payments'] > 0 ? 'warning' : 'gray'),
            Stat::make('Refunds', $metrics->money($refunds['processed_amount']))
                ->description($refunds['processed_count'].' processed · '.$refunds['open_count'].' open')
                ->descriptionIcon(Heroicon::OutlinedReceiptRefund)
                ->color($refunds['open_count'] > 0 ? 'warning' : 'gray'),
            Stat::make('Coupon redemptions', number_format($coupons['redemptions']))
                ->description($metrics->money($coupons['discount']).' discounted'
                    .($coupons['orders_with_coupon_rate'] !== null ? ' · '.Format::percent($coupons['orders_with_coupon_rate'], 0).' of paid orders' : ''))
                ->descriptionIcon(Heroicon::OutlinedTicket),
        ];
    }
}
