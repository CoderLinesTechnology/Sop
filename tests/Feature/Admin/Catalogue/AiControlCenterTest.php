<?php

use App\Enums\AdminRole;
use App\Enums\AiJobStatus;
use App\Filament\Pages\AiControlCenter;
use App\Filament\Support\Catalogue\AiMetrics;
use App\Models\AiJob;
use App\Models\AiUsage;
use App\Models\AiWorkflow;
use App\Models\Feedback;
use App\Models\Order;
use App\Models\PromptVersion;
use App\Models\QualityReview;
use App\Support\AdminUrls;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Cache::forget('admin:ai-control-center:metrics');
});

function aiJob(Order $order, AiJobStatus $status, array $attributes = []): AiJob
{
    return AiJob::query()->create($attributes + [
        'order_id' => $order->id,
        'kind' => AiJob::KIND_ORDER,
        'dedupe_key' => 'order:'.$order->id.':'.uniqid(),
        'status' => $status,
        'workflow_snapshot' => [],
        'provider' => 'fake',
    ]);
}

it('shows configuration, metrics, ratings and failures to AI administrators', function () {
    config(['statementra.ai.openai_api_key' => 'sk-super-secret-value', 'statementra.ai.provider' => 'openai']);
    actingAsAdmin(AdminRole::Ai);

    $workflow = AiWorkflow::query()->create(['name' => 'Standard pipeline', 'slug' => 'standard', 'is_default' => true, 'is_active' => true, 'config' => ['quality' => ['threshold' => 8.5]]]);
    $writing = PromptVersion::query()->create(['prompt_key' => 'writing', 'version' => 4, 'status' => 'active', 'label' => 'Warm openings', 'system_prompt' => 'Write.']);

    $orderA = Order::factory()->create();
    $orderB = Order::factory()->create();
    $done = aiJob($orderA, AiJobStatus::Completed, ['ai_workflow_id' => $workflow->id, 'started_at' => now()->subMinutes(30), 'finished_at' => now()->subMinutes(6)]);
    aiJob($orderB, AiJobStatus::Failed, ['current_stage' => 'research', 'last_error_code' => 'provider_timeout', 'last_error_message' => 'The model did not answer in time.', 'failure_count' => 3]);

    foreach ([[$orderA, 1.25], [$orderA, 0.75], [$orderB, 0.5]] as [$order, $cost]) {
        AiUsage::query()->create([
            'ai_job_id' => $done->id, 'order_id' => $order->id, 'provider' => 'openai', 'model' => 'gpt-6.1-sol', 'stage' => 'writing',
            'input_tokens' => 1000, 'cached_input_tokens' => 200, 'output_tokens' => 500, 'reasoning_tokens' => 100, 'search_calls' => 2,
            'estimated_cost_usd' => $cost, 'status' => 'ok', 'created_at' => now(),
        ]);
    }

    QualityReview::query()->create(['ai_job_id' => $done->id, 'order_id' => $orderA->id, 'round' => 1, 'scores' => [], 'overall_score' => 7.0, 'threshold' => 8.0, 'passed' => false]);
    QualityReview::query()->create(['ai_job_id' => $done->id, 'order_id' => $orderA->id, 'round' => 2, 'scores' => [], 'overall_score' => 8.6, 'threshold' => 8.0, 'passed' => true]);

    Feedback::query()->create(['order_id' => $orderA->id, 'ai_job_id' => $done->id, 'ai_workflow_id' => $workflow->id, 'prompt_versions' => ['writing' => $writing->id], 'rating' => 5]);
    Feedback::query()->create(['order_id' => $orderB->id, 'ai_workflow_id' => $workflow->id, 'prompt_versions' => ['writing' => ['id' => $writing->id, 'version' => 4]], 'rating' => 4]);

    $response = $this->get(AiControlCenter::getUrl())->assertOk();

    $response->assertSee('Standard pipeline')
        ->assertSee('Configured')
        ->assertDontSee('sk-super-secret-value')
        ->assertSee('provider_timeout')
        ->assertSee(AdminUrls::order($orderB), false)
        ->assertSee($orderB->reference)
        ->assertSee('Warm openings')
        ->assertSee('8.5')
        ->assertSee('4.50')      // average rating of writing v4
        ->assertSee('$1.250')    // average cost per order: (2.00 + 0.50) / 2
        ->assertSee('24 min')    // average generation time
        ->assertSee('8.60 / 10') // final review round only
        ->assertSee('50%');      // 1 failed of 2 finished jobs
});

it('reads prompt versions recorded as ids, version numbers or objects', function () {
    $writing = PromptVersion::query()->create(['prompt_key' => 'writing', 'version' => 2, 'status' => 'active', 'system_prompt' => 'x']);
    PromptVersion::query()->create(['prompt_key' => 'strategy', 'version' => 7, 'status' => 'draft', 'system_prompt' => 'x']);
    $order = fn () => Order::factory()->create();

    Feedback::query()->create(['order_id' => $order()->id, 'prompt_versions' => ['writing' => $writing->id, 'strategy' => 7], 'rating' => 5]);
    Feedback::query()->create(['order_id' => $order()->id, 'prompt_versions' => ['writing' => ['version' => 2]], 'rating' => 3]);
    Feedback::query()->create(['order_id' => $order()->id, 'prompt_versions' => [$writing->id], 'rating' => 4]);

    $ratings = collect(AiMetrics::lastDays()->ratingsByPromptVersion())->keyBy(fn ($row) => $row['prompt_key'].'#'.$row['version']);

    expect($ratings['writing#2']['count'])->toBe(3)
        ->and($ratings['writing#2']['average'])->toEqual(4.0)
        ->and($ratings['strategy#7']['count'])->toBe(1);
});

it('is only available with ai.manage', function (AdminRole $role) {
    actingAsAdmin($role);

    $this->withoutVite()->get(AiControlCenter::getUrl())->assertForbidden();
})->with([AdminRole::Content, AdminRole::Finance, AdminRole::Operations]);
