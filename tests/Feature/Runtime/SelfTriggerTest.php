<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Models\AiJob;
use App\Models\Order;
use App\Models\SecurityEvent;
use App\Support\Runtime\AfterResponse;
use App\Support\Runtime\SelfTrigger;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function aiJobFor(Order $order): AiJob
{
    return AiJob::query()->create([
        'order_id' => $order->id,
        'kind' => AiJob::KIND_ORDER,
        'dedupe_key' => 'test:'.$order->id,
        'status' => 'queued',
        'workflow_snapshot' => [],
        'provider' => 'fake',
    ]);
}

it('signs loopback requests so the internal route accepts them', function () {
    $job = aiJobFor(Order::factory()->paid()->create());
    $captured = null;
    Http::fake(function (HttpRequest $request) use (&$captured) {
        $captured = $request;

        return Http::response('', 202);
    });

    expect(SelfTrigger::fire('internal.pipeline.continue', ['aiJob' => $job->uuid]))->toBeTrue();

    $this->mock(PipelineDispatcher::class)->shouldReceive('kick')->once();
    $this->call('POST', parse_url($captured->url(), PHP_URL_PATH), server: [
        'HTTP_X_STATEMENTRA_TRIGGER' => $captured->header(SelfTrigger::HEADER)[0],
    ])->assertStatus(202);
});

it('rejects unsigned, forged, stale and re-targeted loopback requests', function () {
    $job = aiJobFor(Order::factory()->paid()->create());
    $other = aiJobFor(Order::factory()->paid()->create());
    $this->mock(PipelineDispatcher::class)->shouldNotReceive('kick');

    $captured = null;
    Http::fake(function (HttpRequest $request) use (&$captured) {
        $captured = $request;

        return Http::response('', 202);
    });
    SelfTrigger::fire('internal.pipeline.continue', ['aiJob' => $job->uuid]);
    $valid = $captured->header(SelfTrigger::HEADER)[0];

    $this->post('/internal/pipeline/'.$job->uuid)->assertForbidden();
    $this->call('POST', '/internal/pipeline/'.$job->uuid, server: ['HTTP_X_STATEMENTRA_TRIGGER' => time().'.'.str_repeat('0', 64)])->assertForbidden();
    // A valid signature is bound to its path: it cannot be reused for another job.
    $this->call('POST', '/internal/pipeline/'.$other->uuid, server: ['HTTP_X_STATEMENTRA_TRIGGER' => $valid])->assertForbidden();

    $this->travel(10)->minutes();
    $this->call('POST', '/internal/pipeline/'.$job->uuid, server: ['HTTP_X_STATEMENTRA_TRIGGER' => $valid])->assertForbidden();

    expect(SecurityEvent::query()->where('type', 'invalid_runtime_trigger')->count())->toBe(4);
});

it('waits for the surrounding transaction to commit before running deferred work', function () {
    $ran = false;

    DB::transaction(function () use (&$ran) {
        AfterResponse::run('test', function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeFalse();
    });

    expect($ran)->toBeTrue();
});

it('drops deferred work when the surrounding transaction rolls back', function () {
    $ran = false;

    try {
        DB::transaction(function () use (&$ran) {
            AfterResponse::run('test', function () use (&$ran) {
                $ran = true;
            });

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($ran)->toBeFalse();
});

it('never lets a failing deferred task break the request', function () {
    AfterResponse::run('test', fn () => throw new RuntimeException('boom'));

    expect(true)->toBeTrue();
});
