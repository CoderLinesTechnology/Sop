<?php

use App\Models\SystemTask;
use App\Support\Runtime\Heartbeat;
use App\Support\Settings;

/** Counts invocations; used as a stand-in heartbeat task. */
class CountingTask
{
    public static int $runs = 0;

    public function __invoke(): void
    {
        self::$runs++;
    }
}

class FailingTask
{
    public function __invoke(): void
    {
        throw new RuntimeException('Mail server unreachable');
    }
}

class SlowTask
{
    public function __invoke(): void
    {
        usleep(1_100_000);
    }
}

beforeEach(function () {
    CountingTask::$runs = 0;
    config()->set('statementra.runtime.tasks', [
        'test.counting' => [600, CountingTask::class],
        'test.failing' => [60, FailingTask::class],
    ]);
});

it('runs each task at most once per interval', function () {
    $heartbeat = app(Heartbeat::class);

    expect($heartbeat->beat()['test.counting'])->toBe('ran')
        ->and($heartbeat->beat()['test.counting'])->toBe('skipped')
        ->and(CountingTask::$runs)->toBe(1);

    $this->travel(11)->minutes();

    expect($heartbeat->beat()['test.counting'])->toBe('ran')
        ->and(CountingTask::$runs)->toBe(2)
        ->and(SystemTask::query()->where('name', 'test.counting')->value('run_count'))->toBe(2);
});

it('records a failing task without stopping the others', function () {
    $outcomes = app(Heartbeat::class)->beat();

    expect($outcomes)->toBe(['test.counting' => 'ran', 'test.failing' => 'failed']);

    $row = SystemTask::query()->where('name', 'test.failing')->sole();
    expect($row->last_status)->toBe('failed')
        ->and($row->last_error)->toContain('Mail server unreachable')
        ->and($row->failure_count)->toBe(1);
});

it('stops starting tasks once its time budget is spent', function () {
    config()->set('statementra.runtime.tasks', [
        'test.slow' => [60, SlowTask::class],
        'test.counting' => [60, CountingTask::class],
    ]);

    $outcomes = app(Heartbeat::class)->beat(budgetSeconds: 1);

    expect($outcomes)->toBe(['test.slow' => 'ran', 'test.counting' => 'deferred'])
        ->and(CountingTask::$runs)->toBe(0);
});

it('runs due tasks after page views without making visitors wait', function () {
    @unlink(storage_path('framework/heartbeat.stamp'));

    $this->get('/')->assertOk();

    expect(CountingTask::$runs)->toBe(1); // console runs after-response work inline

    $this->get('/')->assertOk();
    expect(CountingTask::$runs)->toBe(1); // at most one beat per minute
});

it('runs maintenance when the secret ping URL is called', function () {
    $this->get('/system/heartbeat/'.str_repeat('x', 40))->assertNotFound();
    expect(CountingTask::$runs)->toBe(0);

    $this->get('/system/heartbeat/'.Heartbeat::token())->assertStatus(202);
    expect(CountingTask::$runs)->toBe(1)
        ->and(Settings::get('system.heartbeat_token'))->toBe(Heartbeat::token());
});
