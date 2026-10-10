<?php

use App\Domain\Orders\OrderStateMachine;
use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\Dashboard;
use App\Filament\Support\Operations\Metrics;
use App\Filament\Widgets\Analytics\AnalyticsOverview;
use App\Filament\Widgets\Analytics\ConversionFunnel;
use App\Filament\Widgets\Analytics\ServicePerformance;
use App\Filament\Widgets\FulfilmentOverview;
use App\Filament\Widgets\OrdersByStatusChart;
use App\Filament\Widgets\OrdersNeedingAttention;
use App\Filament\Widgets\PopularServices;
use App\Filament\Widgets\RevenueTrendChart;
use App\Filament\Widgets\SalesOverview;
use App\Models\AnalyticsEvent;
use App\Models\Feedback;
use App\Models\Order;
use App\Models\Revision;
use App\Models\Service;
use App\Support\Settings;
use Livewire\Livewire;
use Tests\Feature\Admin\Operations\Fixtures;

/** Two delivered orders, one failure, a refund, feedback, a revision and funnel events. */
function seedBusiness(): array
{
    $service = Service::factory()->create(['name' => 'Personal Statement Pro']);

    $delivered = Fixtures::paidOrder(OrderStatus::Writing, ['service_id' => $service->id]);
    $delivered->payments()->update(['paid_at' => now()->subHours(3)]);
    app(OrderStateMachine::class)->transition($delivered, OrderStatus::Delivered, force: true);
    $delivered->forceFill(['delivered_at' => now()->subHours(2)])->save();

    $second = Fixtures::paidOrder(OrderStatus::Writing, ['service_id' => $service->id, 'total_amount' => 11100]);
    $second->payments()->update(['amount' => 11100, 'paid_at' => now()->subHours(5)]);
    app(OrderStateMachine::class)->transition($second, OrderStatus::Delivered, force: true);
    $second->forceFill(['delivered_at' => now()->subHours(4)])->save();

    $failed = Fixtures::paidOrder(OrderStatus::Writing);
    app(OrderStateMachine::class)->transition($failed, OrderStatus::ProcessingFailed);

    Order::factory()->create(['status' => OrderStatus::PaymentPending->value, 'service_id' => $service->id]);

    $refund = Fixtures::refund($delivered, 1000, RefundStatus::Processed);
    $refund->forceFill(['processed_at' => now()])->save();

    Feedback::query()->create(['order_id' => $delivered->id, 'service_id' => $service->id, 'rating' => 5]);
    Feedback::query()->create(['order_id' => $second->id, 'service_id' => $service->id, 'rating' => 3]);

    Revision::query()->create(['order_id' => $second->id, 'number' => 1, 'request_text' => 'Shorter please', 'status' => 'requested', 'mode' => 'ai', 'fee_amount' => 0, 'currency' => 'USD', 'requested_at' => now()]);

    foreach (['a', 'b', 'c', 'd'] as $visitor) {
        AnalyticsEvent::query()->create(['event' => AnalyticsEvent::PAGE_VIEW, 'visitor_hash' => str_pad($visitor, 16, '0'), 'created_at' => now()]);
    }
    AnalyticsEvent::query()->create(['event' => AnalyticsEvent::PAGE_VIEW, 'visitor_hash' => str_pad('a', 16, '0'), 'created_at' => now()]);
    AnalyticsEvent::query()->create(['event' => AnalyticsEvent::SERVICE_VIEW, 'service_id' => $service->id, 'visitor_hash' => str_pad('a', 16, '0'), 'created_at' => now()]);
    AnalyticsEvent::query()->create(['event' => AnalyticsEvent::SERVICE_VIEW, 'service_id' => $service->id, 'visitor_hash' => str_pad('b', 16, '0'), 'created_at' => now()]);
    AnalyticsEvent::query()->create(['event' => AnalyticsEvent::PAYMENT_SUCCESS, 'order_id' => $delivered->id, 'created_at' => now()]);
    AnalyticsEvent::query()->create(['event' => AnalyticsEvent::PAYMENT_SUCCESS, 'order_id' => $delivered->id, 'created_at' => now()]);
    AnalyticsEvent::query()->create(['event' => AnalyticsEvent::PAGE_VIEW, 'visitor_hash' => str_pad('z', 16, '0'), 'created_at' => now()->subDays(40)]);

    return compact('service', 'delivered', 'second', 'failed');
}

it('renders the dashboard for every administrator role', function (AdminRole $role) {
    actingAsAdmin($role);

    $this->get(Dashboard::getUrl())->assertOk();
})->with([AdminRole::SuperAdmin, AdminRole::Operations, AdminRole::Content, AdminRole::Finance, AdminRole::Ai]);

it('renders every dashboard widget with data', function () {
    actingAsAdmin(AdminRole::SuperAdmin);
    seedBusiness();
    Settings::set('general.currency', 'USD'); // revenue is reported in the site currency; the fixtures are USD

    Livewire::test(SalesOverview::class, ['pageFilters' => ['range' => 30]])
        ->assertOk()
        ->assertSee('Revenue')
        ->assertSee('$289')
        ->assertSee('Pending payments');
    Livewire::test(FulfilmentOverview::class, ['pageFilters' => ['range' => 30]])
        ->assertOk()
        ->assertSee('Failed or in manual review')
        ->assertSee('4.00 / 5');
    Livewire::test(RevenueTrendChart::class, ['pageFilters' => ['range' => 7]])->assertOk();
    Livewire::test(OrdersByStatusChart::class, ['pageFilters' => ['range' => 30]])->assertOk();
    Livewire::test(OrdersNeedingAttention::class)->assertOk()->assertSee('Processing failed');
    Livewire::test(PopularServices::class, ['pageFilters' => ['range' => 30]])->assertOk()->assertSee('Personal Statement Pro');
});

it('computes dashboard metrics with aggregate queries', function () {
    actingAsAdmin(AdminRole::SuperAdmin);
    seedBusiness();

    $metrics = new Metrics(30, 'USD');

    expect($metrics->sales())->toMatchArray([
        'revenue_period' => 8900 + 11100 + 8900,
        'paid_orders_period' => 3,
    ])
        ->and($metrics->orders())->toMatchArray([
            'pending_payments' => 1,
            'failed' => 1,
            'delivered_period' => 2,
        ])
        ->and($metrics->refunds())->toMatchArray(['processed_count' => 1, 'processed_amount' => 1000])
        ->and($metrics->processingTime()['average_minutes'])->toBe(60.0)
        ->and($metrics->satisfaction()['average'])->toBe(4.0)
        ->and($metrics->revisionRate())->toMatchArray(['rate' => 0.5, 'delivered' => 2, 'with_revisions' => 1])
        ->and($metrics->failures()['processing_failures'])->toBe(1)
        ->and($metrics->ordersByStatus())->toMatchArray([OrderStatus::Delivered->value => 2]);
});

it('builds the conversion funnel from analytics events', function () {
    actingAsAdmin(AdminRole::Finance);
    seedBusiness();

    $funnel = collect((new Metrics(30))->funnel())->keyBy('event');

    expect($funnel[AnalyticsEvent::PAGE_VIEW]['count'])->toBe(4)
        ->and($funnel[AnalyticsEvent::SERVICE_VIEW]['count'])->toBe(2)
        ->and($funnel[AnalyticsEvent::SERVICE_VIEW]['step_rate'])->toBe(0.5)
        ->and($funnel[AnalyticsEvent::PAYMENT_SUCCESS]['count'])->toBe(1)
        ->and($funnel[AnalyticsEvent::PAYMENT_SUCCESS]['overall_rate'])->toBe(0.25);

    $services = (new Metrics(30))->popularServices();
    expect($services[0])->toMatchArray(['name' => 'Personal Statement Pro', 'orders' => 2, 'views' => 2]);
});

it('gives the analytics page to administrators with analytics.view only', function () {
    actingAsAdmin(AdminRole::Operations);
    $this->get(Analytics::getUrl())->assertForbidden();

    $this->flushSession();
    actingAsAdmin(AdminRole::Finance);
    $this->get(Analytics::getUrl())->assertOk()->assertSee('Analytics');

    seedBusiness();
    Livewire::test(AnalyticsOverview::class, ['pageFilters' => ['range' => 30]])->assertOk()->assertSee('Conversion rate')->assertSee('25.00%');
    Livewire::test(ConversionFunnel::class, ['pageFilters' => ['range' => 30]])->assertOk()->assertSee('Viewed a service');
    Livewire::test(ServicePerformance::class, ['pageFilters' => ['range' => 30]])->assertOk()->assertSee('Personal Statement Pro');
});

it('accepts only the supported reporting windows', function () {
    expect(Metrics::range('7'))->toBe(7)
        ->and(Metrics::range(90))->toBe(90)
        ->and(Metrics::range('9999'))->toBe(30)
        ->and(Metrics::range('30; DROP TABLE orders'))->toBe(30);
});
