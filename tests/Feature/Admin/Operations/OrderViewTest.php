<?php

use App\Domain\Orders\OrderStateMachine;
use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Service;
use App\Support\AdminUrls;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Admin\Operations\Fixtures;

it('hides orders and refunds from administrators without order permissions', function () {
    actingAsAdmin(AdminRole::Content);
    $order = Fixtures::paidOrder();

    $this->get(OrderResource::getUrl('index'))->assertForbidden();
    $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertForbidden();
    $this->get('/admin/refunds')->assertForbidden();
});

it('lists submitted orders newest first and hides unpaid drafts by default', function () {
    actingAsAdmin(AdminRole::Operations);

    $older = Fixtures::paidOrder(OrderStatus::Writing, ['created_at' => now()->subDays(2)]);
    $newer = Fixtures::paidOrder(OrderStatus::Delivered);
    $draft = Order::factory()->create(['status' => OrderStatus::FormSubmitted->value]);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertCanNotSeeTableRecords([$draft])
        ->filterTable('drafts', ['include_drafts' => true])
        ->assertCanSeeTableRecords([$newer, $older, $draft]);

    Livewire::test(ListOrders::class)
        ->resetTableFilters()
        ->filterTable('status', [OrderStatus::FormSubmitted->value])
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$newer, $older]);
});

it('searches orders by reference, email, name and institution', function () {
    actingAsAdmin(AdminRole::Operations);

    $match = Fixtures::paidOrder(OrderStatus::Writing, ['institution' => 'Imperial College London', 'customer_name' => 'Grace Hopper']);
    $other = Fixtures::paidOrder(OrderStatus::Writing, ['institution' => 'University of Lagos']);

    foreach ([$match->reference, $match->email, 'Grace Hopper', 'Imperial'] as $term) {
        Livewire::test(ListOrders::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

it('filters orders that need attention and by status', function () {
    actingAsAdmin(AdminRole::Operations);

    $failed = Fixtures::paidOrder(OrderStatus::Writing);
    app(OrderStateMachine::class)->transition($failed, OrderStatus::ProcessingFailed);
    $healthy = Fixtures::paidOrder(OrderStatus::Writing);

    Livewire::test(ListOrders::class)
        ->filterTable('needs_attention')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$healthy]);

    Livewire::test(ListOrders::class)
        ->resetTableFilters()
        ->filterTable('status', [OrderStatus::Writing->value])
        ->assertCanSeeTableRecords([$healthy])
        ->assertCanNotSeeTableRecords([$failed]);
});

it('shows a needs-attention navigation badge', function () {
    actingAsAdmin(AdminRole::Operations);

    $order = Fixtures::paidOrder(OrderStatus::Writing);
    app(OrderStateMachine::class)->transition($order, OrderStatus::ManualReview);

    expect(OrderResource::getNavigationBadge())->toBe('1');
});

it('loads the order list without N+1 queries', function () {
    actingAsAdmin(AdminRole::Operations);
    $service = Service::factory()->create();
    Order::factory()->paid(OrderStatus::Writing)->count(3)->create(['service_id' => $service->id]);

    Livewire::test(ListOrders::class); // warm up permission and settings caches

    DB::enableQueryLog();
    Livewire::test(ListOrders::class);
    $few = count(DB::getQueryLog());
    DB::flushQueryLog();

    Order::factory()->paid(OrderStatus::Writing)->count(12)->create(['service_id' => Service::factory()->create()->id]);

    DB::flushQueryLog();
    Livewire::test(ListOrders::class);
    $many = count(DB::getQueryLog());

    expect($many)->toBe($few);
});

it('addresses orders by public id and renders every tab', function () {
    $admin = actingAsAdmin(AdminRole::SuperAdmin);
    $order = Fixtures::richOrder(OrderStatus::ManualReview, $admin);

    expect(OrderResource::getUrl('view', ['record' => $order]))->toEndWith('/admin/orders/'.$order->public_id)
        ->and(AdminUrls::order($order))->toEndWith('/admin/orders/'.$order->public_id);

    $page = Livewire::test(ViewOrder::class, ['record' => $order->public_id])
        ->assertOk()
        ->assertSee($order->reference)
        ->assertSee('Manual review')
        ->assertSee('University of Oxford')
        ->assertSee('+234 800 000 0000')
        ->assertSee('Line one')
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('Ada "CV".pdf')
        ->assertSee('Exact graduation year')
        ->assertSee('Personal Statement')
        ->assertSee('SMTP connection refused')
        ->assertSee('Please mention my internship.')
        ->assertSee('When do you graduate?')
        ->assertSee('Checked the CV');

    foreach (['infolist.orderTabs.research', 'infolist.orderTabs.requirements', 'infolist.orderTabs.processing', 'infolist.orderTabs.audit'] as $key) {
        $page->call('loadDeferredSchema', $key);
    }

    // Deferred tabs render as partials; a full render shows everything that has been loaded.
    $page->call('$refresh');

    $page->assertSee('The MSc includes a research project.')
        ->assertSee('Graduate admissions')
        ->assertDontSee('href="javascript:alert(1)"', escape: false)
        ->assertSee('Quality score 6.1 is below the threshold 8.0.')
        ->assertSee('The model timed out.')
        ->assertSee('Too generic opening')
        ->assertSee('order.viewed');
});

it('does not expose customer answers, files or the profile without customers.view', function () {
    actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::richOrder();

    Livewire::test(ViewOrder::class, ['record' => $order->public_id])
        ->assertOk()
        ->assertSee($order->reference)
        ->assertDontSee('Line one')
        ->assertDontSee('Ada "CV".pdf')
        ->assertDontSee('Exact graduation year')
        ->assertDontSee('+234 800 000 0000');
});

it('audits viewing an order at most once per administrator per hour', function () {
    $admin = actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder();

    Livewire::test(ViewOrder::class, ['record' => $order->public_id]);
    Livewire::test(ViewOrder::class, ['record' => $order->public_id]);

    $views = AuditLog::query()->where('action', 'order.viewed')->where('target_id', (string) $order->id);
    expect((clone $views)->count())->toBe(1)
        ->and((clone $views)->first()->admin_user_id)->toBe($admin->id);

    $this->travel(61)->minutes();
    Livewire::test(ViewOrder::class, ['record' => $order->public_id]);

    expect((clone $views)->count())->toBe(2);
});
