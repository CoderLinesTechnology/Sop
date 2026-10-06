<?php

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Filament\Resources\Feedback\Widgets\FeedbackStats;
use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use App\Filament\Resources\NewsletterSubscribers\Pages\ListNewsletterSubscribers;
use App\Filament\Resources\PaymentEvents\PaymentEventResource;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\SecurityEvents\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEvents\SecurityEventResource;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\Feedback;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\SecurityEvent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Feature\Admin\Operations\Fixtures;

it('lists feedback with rating statistics and filters by rating', function () {
    actingAsAdmin(AdminRole::Content);
    $five = Feedback::query()->create(['order_id' => Fixtures::paidOrder(OrderStatus::Delivered)->id, 'rating' => 5, 'liked' => 'Fast and personal', 'prompt_versions' => ['writing' => 3]]);
    $two = Feedback::query()->create(['order_id' => Fixtures::paidOrder(OrderStatus::Delivered)->id, 'rating' => 2, 'improve' => 'Too formal']);

    Livewire::test(ListFeedback::class)
        ->assertCanSeeTableRecords([$five, $two])
        ->assertSee('★★★★★')
        ->filterTable('rating', 5)
        ->assertCanSeeTableRecords([$five])
        ->assertCanNotSeeTableRecords([$two]);

    Livewire::test(FeedbackStats::class, ['tableFilters' => []])
        ->assertSee('3.50 / 5');
});

it('hides feedback from finance', function () {
    actingAsAdmin(AdminRole::Finance);
    $this->get(FeedbackResource::getUrl('index'))->assertForbidden();
});

it('works the support inbox: open messages first, linked orders, mark handled', function () {
    $admin = actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder();
    $message = ContactMessage::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'order_reference' => $order->reference, 'order_id' => $order->id, 'subject' => 'Where is my document?', 'message' => 'Hello', 'status' => 'new']);
    $closed = ContactMessage::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'message' => 'Thanks', 'status' => 'closed']);

    expect(ContactMessageResource::getNavigationBadge())->toBe('1');

    Livewire::test(ListContactMessages::class)
        ->assertCanSeeTableRecords([$message])
        ->assertCanNotSeeTableRecords([$closed])
        ->assertSee($order->reference)
        ->callAction(TestAction::make('markHandled')->table($message))
        ->assertNotified('Marked as handled');

    $message->refresh();
    expect($message->status)->toBe('closed')
        ->and($message->handled_by_admin_id)->toBe($admin->id)
        ->and($message->handled_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'support.message_handled')->count())->toBe(1);
});

it('keeps the support inbox for support staff', function () {
    actingAsAdmin(AdminRole::Finance);
    $this->get(ContactMessageResource::getUrl('index'))->assertForbidden();
});

it('lists newsletter subscribers by status with an export link', function () {
    actingAsAdmin(AdminRole::Content);
    $confirmed = NewsletterSubscriber::query()->create(['email' => 'ada@example.com', 'status' => 'confirmed']);
    $pending = NewsletterSubscriber::query()->create(['email' => 'bob@example.com', 'status' => 'pending']);

    Livewire::test(ListNewsletterSubscribers::class)
        ->filterTable('status', 'confirmed')
        ->assertCanSeeTableRecords([$confirmed])
        ->assertCanNotSeeTableRecords([$pending])
        ->assertActionHasUrl('export', route('admin.support.exports.newsletter', ['status' => 'confirmed']));

    $this->flushSession();
    actingAsAdmin(AdminRole::Operations);
    $this->get(NewsletterSubscriberResource::getUrl('index'))->assertForbidden();
});

it('shows customer accounts with orders matched by account or email', function () {
    actingAsAdmin(AdminRole::Operations);
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $byAccount = Fixtures::paidOrder(OrderStatus::Delivered, ['user_id' => $user->id, 'email' => 'other@example.com']);
    $byEmail = Fixtures::paidOrder(OrderStatus::Writing, ['email' => 'ada@example.com']);
    $someoneElse = Fixtures::paidOrder();
    Order::factory()->create(['email' => 'ada@example.com']);

    Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$user])->assertSee('2');

    Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])
        ->assertOk()
        ->assertSee($byAccount->reference)
        ->assertSee($byEmail->reference)
        ->assertDontSee($someoneElse->reference);

    expect(AuditLog::query()->where('action', 'customer.viewed')->count())->toBe(1);

    $this->flushSession();
    actingAsAdmin(AdminRole::Finance);
    $this->get(CustomerResource::getUrl('index'))->assertForbidden();
});

it('shows security events to super administrators only', function () {
    actingAsAdmin(AdminRole::SuperAdmin);
    $high = SecurityEvent::query()->create(['type' => 'webhook_bad_signature', 'severity' => 'high', 'ip_address' => '203.0.113.9', 'details' => ['note' => 'bad'], 'created_at' => now()]);
    $low = SecurityEvent::query()->create(['type' => 'rate_limited', 'severity' => 'low', 'created_at' => now()]);

    Livewire::test(ListSecurityEvents::class)
        ->filterTable('severity', ['high'])
        ->assertCanSeeTableRecords([$high])
        ->assertCanNotSeeTableRecords([$low]);

    $this->flushSession();
    actingAsAdmin(AdminRole::Operations);
    $this->get(SecurityEventResource::getUrl('index'))->assertForbidden();
});

it('shows payments and webhook deliveries read-only to finance', function () {
    actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    $payment = $order->payments()->firstOrFail();
    PaymentEvent::query()->create(['provider' => 'paystack', 'event_type' => 'charge.success', 'reference' => $payment->reference, 'payload_hash' => hash('sha256', 'x'), 'signature_valid' => true, 'payload' => ['event' => 'charge.success'], 'processing_status' => 'processed']);

    $this->get(PaymentResource::getUrl('index'))->assertOk()->assertSee($payment->reference);
    $this->get(PaymentEventResource::getUrl('index'))->assertOk()->assertSee('charge.success');

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertOk()
        ->assertSee('$89')
        ->assertActionVisible('openOrder');

    expect(PaymentResource::canCreate())->toBeFalse()
        ->and(PaymentResource::canEdit($payment))->toBeFalse()
        ->and(PaymentResource::canDelete($payment))->toBeFalse();

    $this->flushSession();
    actingAsAdmin(AdminRole::Operations);
    $this->get(PaymentResource::getUrl('index'))->assertForbidden();
    $this->get(PaymentEventResource::getUrl('index'))->assertForbidden();
});
