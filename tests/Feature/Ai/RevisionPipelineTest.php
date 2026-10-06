<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Orders\FulfillmentDenied;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\RevisionStatus;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\Revision;
use Tests\Feature\Ai\Support\PipelineFixtures;

uses(PipelineFixtures::class);

beforeEach(fn () => $this->setUpPipeline());

/** A delivered order (the first version is final) with an AI revision request. */
function deliveredOrderWithRevision(object $test, array $revision = []): Revision
{
    $order = $test->paidOrder();
    app(PipelineDispatcher::class)->startForOrder($order);
    DocumentVersion::query()->where('order_id', $order->id)->update(['status' => 'final']);
    app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::Delivered);

    return Revision::query()->create($revision + [
        'order_id' => $order->id,
        'number' => 1,
        'request_text' => 'Please make the opening more personal and mention that I volunteered at a coding club in 2022.',
        'status' => RevisionStatus::Processing,
        'mode' => 'ai',
        'fee_amount' => 0,
        'currency' => 'USD',
        'requested_at' => now(),
    ]);
}

it('revises the delivered document and delivers a new version for the revision', function () {
    $revision = deliveredOrderWithRevision($this);
    $order = $revision->order;
    FakeProvider::reset();

    $job = app(PipelineDispatcher::class)->startRevision($revision);

    $job->refresh();
    $stages = $job->steps()->get()->filter(fn ($s) => $s->status === StepStatus::Completed)->map(fn ($s) => $s->stage->value)->values()->all();
    $version = DocumentVersion::query()->where('order_id', $order->id)->where('revision_id', $revision->id)->sole();

    expect($job->kind)->toBe(AiJob::KIND_REVISION)
        ->and($job->dedupe_key)->toBe("revision:{$revision->id}")
        ->and($job->status)->toBe(AiJobStatus::Completed)
        ->and($stages)->toBe(['writing', 'fact_check', 'quality_review', 'limits', 'formatting', 'rendering', 'file_qa', 'delivery'])
        ->and($job->stageOutput(PipelineStage::Writing)['mode'])->toBe('revision')
        ->and($version->version_number)->toBe(2)
        ->and(end($this->engine['delivered']))->toBe(['order' => $order->id, 'version' => $version->id, 'revision' => $revision->id])
        ->and($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and($revision->fresh()->status)->toBe(RevisionStatus::Processing);

    // The request reaches the model only as untrusted data, together with the delivered text.
    $call = FakeProvider::calls('revision')[0];
    expect($call->userText())->toContain('coding club in 2022')
        ->and($call->userText())->toMatch('/<untrusted_data source="revision_request" boundary="[a-f0-9]{16}">/')
        ->and($call->userText())->toContain('crop disease classifier');
});

it('starts one job per revision', function () {
    $revision = deliveredOrderWithRevision($this);

    $first = app(PipelineDispatcher::class)->startRevision($revision);
    $second = app(PipelineDispatcher::class)->startRevision($revision->fresh());

    expect($second->id)->toBe($first->id)
        ->and(AiJob::query()->where('revision_id', $revision->id)->count())->toBe(1);
});

it('refuses revisions for refunded orders and unpaid revision fees', function () {
    $revision = deliveredOrderWithRevision($this);
    app(OrderStateMachine::class)->transition($revision->order, OrderStatus::Refunded);

    expect(fn () => app(PipelineDispatcher::class)->startRevision($revision))
        ->toThrow(fn (FulfillmentDenied $e) => expect($e->reason)->toBe('order_closed'));

    $paid = deliveredOrderWithRevision($this, ['fee_amount' => 1500]);
    expect(fn () => app(PipelineDispatcher::class)->startRevision($paid))
        ->toThrow(fn (FulfillmentDenied $e) => expect($e->reason)->toBe('revision_not_paid'));
});
