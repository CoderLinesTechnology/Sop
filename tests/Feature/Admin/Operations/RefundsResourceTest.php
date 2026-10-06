<?php

use App\Domain\Payments\RefundService;
use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Resources\Refunds\RefundResource;
use App\Models\AuditLog;
use App\Models\Refund;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Admin\Operations\Fixtures;

it('restricts the refunds list to refund and payment roles', function () {
    actingAsAdmin(AdminRole::Content);
    $this->get(RefundResource::getUrl('index'))->assertForbidden();
});

it('shows refunds with their order, amount and status to finance', function () {
    actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    $refund = Fixtures::refund($order, 2500);

    Livewire::test(ListRefunds::class)
        ->assertCanSeeTableRecords([$refund])
        ->assertSee($order->reference)
        ->assertSee('$25')
        ->filterTable('status', [RefundStatus::Processed->value])
        ->assertCanNotSeeTableRecords([$refund]);
});

it('approves a requested refund through RefundService', function () {
    $admin = actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    $refund = Fixtures::refund($order, 2500);

    Http::fake(['*/refund' => Http::response(['status' => true, 'data' => ['id' => 991, 'status' => 'pending']])]);

    Livewire::test(ListRefunds::class)
        ->callAction(TestAction::make('approve')->table($refund))
        ->assertNotified('Refund approved');

    $refund->refresh();
    expect($refund->status)->toBe(RefundStatus::Processing)
        ->and($refund->approved_by_admin_id)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', 'refund.approved')->count())->toBe(1);
});

it('rejects a refund with a reason and marks failed refunds processed manually', function () {
    actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    $requested = Fixtures::refund($order, 2500);

    Livewire::test(ListRefunds::class)
        ->callAction(TestAction::make('reject')->table($requested), ['reason' => 'Already delivered'])
        ->assertHasNoActionErrors()
        ->assertNotified('Refund rejected');

    expect($requested->fresh()->status)->toBe(RefundStatus::Rejected)
        ->and($requested->fresh()->notes)->toBe('Already delivered');

    $failed = Fixtures::refund($order, 3000, RefundStatus::Failed);

    Livewire::test(ListRefunds::class)
        ->callAction(TestAction::make('markProcessed')->table($failed), ['external_reference' => 'BANK-123'])
        ->assertHasNoActionErrors()
        ->assertNotified('Refund marked as processed');

    expect($failed->fresh()->status)->toBe(RefundStatus::Processed)
        ->and($failed->fresh()->provider_refund_id)->toBe('BANK-123')
        ->and($order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('does not let operations approve refunds', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    $refund = Fixtures::refund($order, 2500);

    Livewire::test(ListRefunds::class)
        ->assertCanSeeTableRecords([$refund])
        ->assertActionHidden(TestAction::make('approve')->table($refund))
        ->assertActionHidden(TestAction::make('reject')->table($refund));
});

it('surfaces the duplicate-refund guard of RefundService', function () {
    $admin = actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    Fixtures::refund($order, 1000);

    expect(fn () => app(RefundService::class)->request($order, 500, 'Second refund', $admin))
        ->toThrow(RuntimeException::class, 'A refund for this payment is already in progress.');

    // The order page hides the action while a refund is open. If another administrator opens a
    // refund at the same moment, the service's guard message is shown and nothing is created.
    $order->refunds()->update(['status' => RefundStatus::Rejected->value]);
    $this->mock(RefundService::class)
        ->shouldReceive('request')->once()
        ->andThrow(new RuntimeException('A refund for this payment is already in progress.'));

    Livewire::test(ViewOrder::class, ['record' => $order->public_id])
        ->callAction('refund', ['amount' => 10, 'reason' => 'Racing request'])
        ->assertNotified(Notification::make()
            ->danger()
            ->title("Couldn't create the refund")
            ->body('A refund for this payment is already in progress.')
            ->persistent());

    expect(Refund::query()->where('order_id', $order->id)->count())->toBe(1);
});
