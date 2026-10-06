<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Documents\DocumentAdminOperations;
use App\Domain\Documents\DocumentModel;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\AdminRole;
use App\Enums\EmailTemplateKey;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\RefundStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AiJob;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\InformationRequest;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Refund;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Admin\Operations\Fixtures;

function auditCount(string $action, ?Order $order = null): int
{
    return AuditLog::query()
        ->where('action', $action)
        ->when($order, fn ($q) => $q->where('target_type', 'Order')->where('target_id', (string) $order->id))
        ->count();
}

function viewOrder(Order $order): Testable
{
    return Livewire::test(ViewOrder::class, ['record' => $order->public_id]);
}

it('lets operations change the status to an allowed transition, with the reason recorded', function () {
    $admin = actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Writing);

    viewOrder($order)
        ->callAction('changeStatus', ['status' => OrderStatus::ManualReview->value, 'reason' => 'Customer called about the deadline'])
        ->assertHasNoActionErrors()
        ->assertNotified('Status changed to Manual review');

    expect($order->fresh()->status)->toBe(OrderStatus::ManualReview);

    $history = OrderStatusHistory::query()->where('order_id', $order->id)->latest('id')->first();
    expect($history->admin_user_id)->toBe($admin->id)
        ->and($history->reason)->toBe('Customer called about the deadline')
        ->and(auditCount('order.status_changed', $order))->toBe(1);
});

it('refuses a status the state machine does not allow unless overridden', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Writing);

    viewOrder($order)
        ->callAction('changeStatus', ['status' => OrderStatus::Delivered->value, 'reason' => 'Skip ahead'])
        ->assertHasActionErrors(['status']);

    expect($order->fresh()->status)->toBe(OrderStatus::Writing);
});

it('lets administrators with orders.override force any status', function () {
    actingAsAdmin(AdminRole::SuperAdmin);
    $order = Fixtures::paidOrder(OrderStatus::Writing);

    viewOrder($order)
        ->callAction('changeStatus', ['force' => true, 'status' => OrderStatus::Delivered->value, 'reason' => 'Delivered manually by email'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and(auditCount('order.status_forced', $order))->toBe(1);
});

it('does not let finance change an order status or control processing', function () {
    actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::richOrder(OrderStatus::ManualReview);

    viewOrder($order)
        ->assertOk()
        ->assertActionHidden('changeStatus')
        ->assertActionHidden('retryProcessing')
        ->assertActionHidden('requestInformation')
        ->assertActionHidden('rotateCustomerLink')
        ->assertActionHidden('uploadCorrectedDocument')
        ->assertActionVisible('refund')
        ->assertActionVisible('addNote');
});

it('lets AI administrators control the pipeline and documents but not the order status or refunds', function () {
    actingAsAdmin(AdminRole::Ai);
    $order = Fixtures::richOrder(OrderStatus::ManualReview);

    viewOrder($order)
        ->assertActionVisible('retryProcessing')
        ->assertActionVisible('regenerateDocument')
        ->assertActionVisible('editDocumentText')
        ->assertActionHidden('changeStatus')
        ->assertActionHidden('requestInformation')
        ->assertActionHidden('resendDocumentEmail')
        ->assertActionHidden('refund');
});

it('adds an internal note', function () {
    $admin = actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder();

    viewOrder($order)
        ->callAction('addNote', ['body' => 'Customer asked for a call back.'])
        ->assertHasNoActionErrors()
        ->assertNotified('Note added');

    expect($order->notes()->first())
        ->body->toBe('Customer asked for a call back.')
        ->admin_user_id->toBe($admin->id)
        ->and(auditCount('order.note_added', $order))->toBe(1);
});

it('asks the customer for more information', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Writing);
    app(OrderStateMachine::class)->transition($order, OrderStatus::ManualReview);

    viewOrder($order)
        ->callAction('requestInformation', ['questions' => [
            ['question' => 'Which year will you graduate?', 'why' => 'The programme asks for it'],
            ['question' => 'Do you have research experience?', 'why' => null],
        ]])
        ->assertHasNoActionErrors()
        ->assertNotified('Information requested');

    $request = InformationRequest::query()->where('order_id', $order->id)->firstOrFail();

    expect($request->source)->toBe('admin')
        ->and($request->questions)->toHaveCount(2)
        ->and($order->fresh()->status)->toBe(OrderStatus::NeedsInformation)
        ->and(auditCount('order.information_requested', $order))->toBe(1);
});

it('only asks for information where the order can wait for the customer', function () {
    actingAsAdmin(AdminRole::Operations);

    viewOrder(Fixtures::paidOrder(OrderStatus::Writing))->assertActionHidden('requestInformation');
    viewOrder(Fixtures::paidOrder(OrderStatus::Researching))->assertActionVisible('requestInformation');
});

it('allows at most five questions in an information request', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Researching);

    viewOrder($order)
        ->callAction('requestInformation', ['questions' => array_fill(0, 6, ['question' => 'Why?', 'why' => null])])
        ->assertHasActionErrors(['questions']);

    expect(InformationRequest::query()->count())->toBe(0);
});

it('resends the order link and can rotate customer links', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Writing);

    viewOrder($order)->callAction('resendOrderLink')->assertNotified('Order link sent');

    expect(EmailMessage::query()->where('order_id', $order->id)->where('template_key', EmailTemplateKey::OrderLink->value)->count())->toBe(1)
        ->and(auditCount('order.link_resent', $order))->toBe(1);

    viewOrder($order)->callAction('rotateCustomerLink', ['send_new_link' => false])->assertNotified('Customer link rotated');

    expect($order->fresh()->access_version)->toBe(2)
        ->and(auditCount('order.access_links_rotated', $order))->toBe(1);
});

it('reports pipeline operations the dispatcher cannot perform instead of failing', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::richOrder(OrderStatus::ManualReview);

    $this->mock(PipelineDispatcher::class)
        ->shouldReceive('retry')->once()->andThrow(new LogicException('Not implemented yet.'));

    viewOrder($order)
        ->callAction('retryProcessing')
        ->assertNotified("Couldn't retry processing");

    expect(auditCount('order.processing_retried', $order))->toBe(0);
});

it('runs pipeline controls through the dispatcher and audits them once', function () {
    $admin = actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::richOrder(OrderStatus::ManualReview);

    $dispatcher = $this->mock(PipelineDispatcher::class);
    $dispatcher->shouldReceive('retry')->once()->withArgs(fn ($o, $a) => $o->is($order) && $a->is($admin));
    $dispatcher->shouldReceive('skipStage')->once()->withArgs(fn ($o, $stage, $a, $reason) => $stage === PipelineStage::QualityReview && $reason === 'Reviewed by hand');
    $dispatcher->shouldReceive('cancel')->once()->withArgs(fn ($o, $a, $reason) => $reason === 'Customer cancelled');

    viewOrder($order)->callAction('retryProcessing')->assertNotified('Processing retried');
    viewOrder($order)
        ->callAction('skipFailedStep', ['stage' => PipelineStage::QualityReview->value, 'reason' => 'Reviewed by hand'])
        ->assertHasNoActionErrors()
        ->assertNotified('Quality & prompt review skipped');
    viewOrder($order)->callAction('cancelProcessing', ['reason' => 'Customer cancelled'])->assertNotified('Processing cancelled');

    expect(auditCount('order.processing_retried', $order))->toBe(1)
        ->and(auditCount('order.processing_step_skipped', $order))->toBe(1)
        ->and(auditCount('order.processing_cancelled', $order))->toBe(1);
});

it('offers manual start only for a paid order that has not started processing', function () {
    actingAsAdmin(AdminRole::Operations);
    $paid = Fixtures::paidOrder(OrderStatus::PaymentConfirmed);
    $started = Fixtures::richOrder(OrderStatus::Writing);
    $unpaid = Order::factory()->create(['status' => OrderStatus::PaymentPending->value]);

    $this->mock(PipelineDispatcher::class)
        ->shouldReceive('startForOrder')->once()->andReturn(new AiJob);

    viewOrder($started)->assertActionHidden('startProcessing');
    viewOrder($unpaid)->assertActionHidden('startProcessing');
    viewOrder($paid)->callAction('startProcessing')->assertNotified('Processing started');

    expect(auditCount('order.processing_started', $paid))->toBe(1);
});

it('creates a refund defaulting to the refundable balance', function () {
    $admin = actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);

    viewOrder($order)
        ->mountAction('refund')
        ->assertActionDataSet(['amount' => 89])
        ->setActionData(['reason' => 'Late delivery'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Refund created');

    $refund = Refund::query()->where('order_id', $order->id)->sole();

    expect($refund->amount)->toBe(8900)
        ->and($refund->status)->toBe(RefundStatus::Requested)
        ->and($refund->requested_by_admin_id)->toBe($admin->id)
        ->and(auditCount('refund.requested', $order))->toBe(1);
});

it('never refunds more than the refundable balance', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    Fixtures::refund($order, 5000, RefundStatus::Processed);

    viewOrder($order)
        ->mountAction('refund')
        ->assertActionDataSet(['amount' => 39])
        ->setActionData(['amount' => 50, 'reason' => 'Too much'])
        ->callMountedAction()
        ->assertHasActionErrors(['amount']);

    expect(Refund::query()->where('order_id', $order->id)->count())->toBe(1);

    viewOrder($order)
        ->callAction('refund', ['amount' => 39, 'reason' => 'Rest of the payment'])
        ->assertHasNoActionErrors();

    expect(Refund::query()->where('order_id', $order->id)->where('status', RefundStatus::Requested->value)->value('amount'))->toBe(3900);
});

it('lets finance approve a refund immediately when creating it', function () {
    actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);

    Http::fake([
        '*/refund' => Http::response(['status' => true, 'message' => 'Refund queued', 'data' => ['id' => 7788, 'status' => 'processed']]),
    ]);

    viewOrder($order)
        ->callAction('refund', ['amount' => 20, 'reason' => 'Goodwill', 'approve_now' => true])
        ->assertHasNoActionErrors()
        ->assertNotified('Refund created');

    $refund = Refund::query()->where('order_id', $order->id)->sole();

    expect($refund->status)->toBe(RefundStatus::Processed)
        ->and($refund->amount)->toBe(2000)
        ->and($refund->provider_refund_id)->toBe('7788')
        ->and($order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('hides the refund action while a refund is open', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    Fixtures::refund($order, 1000, RefundStatus::Requested);

    viewOrder($order)->assertActionHidden('refund');
});

it('resends the document email through DocumentDelivery and audits it once', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    Fixtures::documentVersion($order);

    viewOrder($order)->callAction('resendDocumentEmail')->assertNotified('Sending the document email');

    expect(EmailMessage::query()->where('order_id', $order->id)->where('template_key', EmailTemplateKey::DocumentReady->value)->count())->toBe(1)
        ->and(auditCount('order.document_resent', $order))->toBe(1);
});

it('delivers a validated document version', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Writing);
    app(OrderStateMachine::class)->transition($order, OrderStatus::ManualReview);
    $draft = Fixtures::documentVersion($order, 1, 'failed');
    $final = Fixtures::documentVersion($order, 2);

    viewOrder($order)
        ->mountAction('deliverVersion')
        ->assertActionDataSet(['version_id' => $final->id])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Sending the delivery email');

    $email = EmailMessage::query()->where('order_id', $order->id)->where('template_key', EmailTemplateKey::DocumentReady->value)->sole();

    expect(data_get($email->meta, 'document_version_id'))->toBe($final->id)
        ->and(auditCount('order.document_delivered', $order))->toBe(1);

    viewOrder($order)
        ->callAction('deliverVersion', ['version_id' => $draft->id])
        ->assertHasActionErrors(['version_id']);
});

it('edits document text through DocumentAdminOperations', function () {
    $admin = actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::ManualReview);
    $version = Fixtures::documentVersion($order);

    $this->mock(DocumentAdminOperations::class)
        ->shouldReceive('editText')
        ->once()
        ->withArgs(fn ($v, DocumentModel $model, $a) => $v->is($version)
            && $model->title === 'My Statement'
            && $model->blocks[0]['text'] === 'A better opening.'
            && $a->is($admin))
        ->andReturnUsing(fn () => Fixtures::documentVersion($order, 2));

    viewOrder($order)
        ->mountAction('editDocumentText')
        ->assertActionDataSet(['version_id' => $version->id, 'title' => 'Personal Statement'])
        ->setActionData([
            'title' => 'My Statement',
            'blocks' => [['type' => 'paragraph', 'text' => 'A better opening.']],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('New document version created');

    expect(auditCount('document.text_edited', $order))->toBe(1);
});

it('reports document operation failures without breaking the page', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::ManualReview);
    $version = Fixtures::documentVersion($order);

    $this->mock(DocumentAdminOperations::class)
        ->shouldReceive('rerender')->once()->andThrow(new RuntimeException('Rendering engine unavailable.'));

    viewOrder($order)
        ->callAction('rerenderDocument', ['version_id' => $version->id])
        ->assertNotified("Couldn't create the new version");

    expect($order->documentVersions()->count())->toBe(1);
});

it('lets the notes tab add a note from its own header action', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder();

    viewOrder($order)
        ->callAction(TestAction::make('addNoteFromNotesTab')->schemaComponent('orderTabs.notes'), ['body' => 'From the tab'])
        ->assertHasNoActionErrors();

    expect($order->notes()->value('body'))->toBe('From the tab');
});
