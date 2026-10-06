<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Concerns\ReadsMetricsRange;
use App\Filament\Support\Operations\Format;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Pipeline load, delivery, failures, speed and customer satisfaction. */
class FulfilmentOverview extends StatsOverviewWidget
{
    use ReadsMetricsRange;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Fulfilment and quality';

    protected int|array|null $columns = ['@sm' => 2, '@xl' => 4];

    public static function canView(): bool
    {
        return AdminContext::can(Permission::DashboardView);
    }

    protected function getDescription(): ?string
    {
        return 'Live counts, and the last '.$this->rangeLabel().' for rates and totals.';
    }

    protected function getStats(): array
    {
        $metrics = $this->metrics();
        $orders = $metrics->orders();
        $failures = $metrics->failures();
        $time = $metrics->processingTime();
        $satisfaction = $metrics->satisfaction();
        $revisions = $metrics->revisionRate();
        $deliveryRate = $metrics->deliverySuccessRate();
        $canSeeOrders = OrderResource::canViewAny();

        return [
            Stat::make('Processing now', number_format($orders['processing']))
                ->description('Paid orders moving through the AI pipeline')
                ->descriptionIcon(Heroicon::OutlinedCpuChip)
                ->color('info'),
            Stat::make('Delivered', number_format($orders['delivered_period']))
                ->description(number_format($orders['delivered_total']).' all time')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success'),
            Stat::make('Failed or in manual review', number_format($orders['failed']))
                ->description('Processing or delivery failed, or handed to a person · '.$orders['needs_information'].' waiting for the customer')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($orders['failed'] > 0 ? 'danger' : 'gray')
                ->url($canSeeOrders ? OrderResource::getUrl('index', ['filters' => ['needs_attention' => ['isActive' => true]]]) : null),
            Stat::make('Average processing time', Format::minutes($time['average_minutes']))
                ->description('Payment to delivery · '.$time['delivered'].' '.str('order')->plural($time['delivered']))
                ->descriptionIcon(Heroicon::OutlinedClock),
            Stat::make('AI processing failures', number_format($failures['processing_failures']))
                ->description($failures['failed_steps'].' failed '.str('step')->plural($failures['failed_steps']).' (including retried)')
                ->descriptionIcon(Heroicon::OutlinedBugAnt)
                ->color($failures['processing_failures'] > 0 ? 'danger' : 'gray'),
            Stat::make('Delivery failures', number_format($failures['delivery_failures']))
                ->description('Delivery success '.Format::percent($deliveryRate))
                ->descriptionIcon(Heroicon::OutlinedEnvelope)
                ->color($failures['delivery_failures'] > 0 ? 'danger' : 'gray'),
            Stat::make('Customer satisfaction', $satisfaction['average'] === null ? Format::PLACEHOLDER : number_format($satisfaction['average'], 2).' / 5')
                ->description($satisfaction['count'].' '.str('rating')->plural($satisfaction['count'])
                    .($satisfaction['positive_rate'] !== null ? ' · '.Format::percent($satisfaction['positive_rate'], 0).' 4–5 stars' : ''))
                ->descriptionIcon(Heroicon::OutlinedFaceSmile)
                ->color($satisfaction['average'] === null ? 'gray' : ($satisfaction['average'] >= 4 ? 'success' : 'warning')),
            Stat::make('Revision rate', Format::percent($revisions['rate']))
                ->description($revisions['with_revisions'].' of '.$revisions['delivered'].' delivered orders')
                ->descriptionIcon(Heroicon::OutlinedArrowPath),
        ];
    }
}
