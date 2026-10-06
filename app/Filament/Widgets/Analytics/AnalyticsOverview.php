<?php

namespace App\Filament\Widgets\Analytics;

use App\Enums\Permission;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Concerns\ReadsMetricsRange;
use App\Filament\Support\Operations\Format;
use App\Models\AnalyticsEvent;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Headline business metrics for the Analytics page. */
class AnalyticsOverview extends StatsOverviewWidget
{
    use ReadsMetricsRange;

    /** Shown on the Analytics page only, not on the dashboard. */
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Performance';

    protected int|array|null $columns = ['@sm' => 2, '@xl' => 4];

    public static function canView(): bool
    {
        return AdminContext::can(Permission::AnalyticsView);
    }

    protected function getDescription(): ?string
    {
        return 'Last '.$this->rangeLabel().'. Money in '.$this->metrics()->currency.'.';
    }

    protected function getStats(): array
    {
        $metrics = $this->metrics();
        $funnel = collect($metrics->funnel())->keyBy('event');
        $sales = $metrics->sales();
        $coupons = $metrics->coupons();
        $time = $metrics->processingTime();
        $revisions = $metrics->revisionRate();
        $satisfaction = $metrics->satisfaction();
        $aov = $metrics->averageOrderValue();

        $visitors = (int) ($funnel[AnalyticsEvent::PAGE_VIEW]['count'] ?? 0);
        $paid = (int) ($funnel[AnalyticsEvent::PAYMENT_SUCCESS]['count'] ?? 0);

        return [
            Stat::make('Visitors', number_format($visitors))
                ->description(number_format((int) ($funnel[AnalyticsEvent::SERVICE_VIEW]['count'] ?? 0)).' viewed a service')
                ->descriptionIcon(Heroicon::OutlinedUsers),
            Stat::make('Conversion rate', $visitors > 0 ? Format::percent($paid / $visitors, 2) : Format::PLACEHOLDER)
                ->description($paid.' paid '.str('order')->plural($paid).' from tracked visits')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->color('success'),
            Stat::make('Revenue', $metrics->money($sales['revenue_period']))
                ->description($sales['paid_orders_period'].' paid '.str('order')->plural($sales['paid_orders_period']))
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make('Average order value', $aov === null ? Format::PLACEHOLDER : $metrics->money($aov))
                ->descriptionIcon(Heroicon::OutlinedCalculator),
            Stat::make('Coupon usage', $coupons['orders_with_coupon_rate'] === null ? Format::PLACEHOLDER : Format::percent($coupons['orders_with_coupon_rate'], 0))
                ->description($coupons['redemptions'].' '.str('redemption')->plural($coupons['redemptions']).' · '.$metrics->money($coupons['discount']).' discounted')
                ->descriptionIcon(Heroicon::OutlinedTicket),
            Stat::make('Average processing time', Format::minutes($time['average_minutes']))
                ->description('Payment to delivery')
                ->descriptionIcon(Heroicon::OutlinedClock),
            Stat::make('Delivery success', Format::percent($metrics->deliverySuccessRate()))
                ->description($metrics->failures()['delivery_failures'].' failed '.str('delivery')->plural($metrics->failures()['delivery_failures']))
                ->descriptionIcon(Heroicon::OutlinedEnvelope),
            Stat::make('Revision rate', Format::percent($revisions['rate']))
                ->description('Satisfaction '.($satisfaction['average'] === null ? Format::PLACEHOLDER : number_format($satisfaction['average'], 2).' / 5').' ('.$satisfaction['count'].')')
                ->descriptionIcon(Heroicon::OutlinedFaceSmile),
        ];
    }
}
