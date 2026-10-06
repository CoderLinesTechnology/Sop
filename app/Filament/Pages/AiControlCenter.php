<?php

namespace App\Filament\Pages;

use App\Enums\AiJobStatus;
use App\Enums\Permission;
use App\Enums\PipelineStage;
use App\Filament\Resources\AiWorkflows\AiWorkflowResource;
use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Filament\Support\Catalogue\AiMetrics;
use App\Models\AiJob;
use App\Models\AiWorkflow;
use App\Models\PromptVersion;
use App\Support\AdminUrls;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * One screen to see how the AI pipeline is configured and how it is doing:
 * the default workflow, active prompts, provider status and the last 30 days
 * of jobs, cost, quality and customer ratings.
 */
class AiControlCenter extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'AI control centre';

    protected static ?string $navigationLabel = 'Control centre';

    protected string $view = 'filament.pages.ai-control-center';

    public const WINDOW_DAYS = 30;

    private const CACHE_KEY = 'admin:ai-control-center:metrics';

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Permission::AiManage);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh figures')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => Cache::forget(self::CACHE_KEY)),
            Action::make('workflows')
                ->label('Workflows')
                ->icon(Heroicon::OutlinedCpuChip)
                ->color('gray')
                ->url(fn (): string => AiWorkflowResource::getUrl('index'))
                ->visible(fn (): bool => AiWorkflowResource::canViewAny()),
            Action::make('prompts')
                ->label('Prompt versions')
                ->icon(Heroicon::OutlinedCommandLine)
                ->color('gray')
                ->url(fn (): string => PromptVersionResource::getUrl('index'))
                ->visible(fn (): bool => PromptVersionResource::canViewAny()),
        ];
    }

    protected function getViewData(): array
    {
        $workflow = AiWorkflow::default();
        $config = $workflow?->effectiveConfig() ?? AiWorkflow::defaultConfig();
        $activePrompts = PromptVersion::query()->active()->with('activatedBy:id,name')->orderBy('prompt_key')->get()->keyBy('prompt_key');

        $metrics = Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function (): array {
            $window = AiMetrics::lastDays(self::WINDOW_DAYS);
            $byStatus = $window->jobsByStatus();

            return [
                'by_status' => $byStatus,
                'jobs' => array_sum($byStatus),
                'failure_rate' => $window->failureRate($byStatus),
                'avg_minutes' => $window->averageGenerationMinutes(),
                'cost' => $window->costPerOrder(),
                'usage' => $window->usage(),
                'today' => $window->usage(now()->startOfDay()),
                'quality' => $window->averageQualityScore(),
                'ratings_by_prompt' => $window->ratingsByPromptVersion(),
                'ratings_by_workflow' => $window->ratingsByWorkflow(),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        $stages = [];
        foreach (PipelineStage::ordered() as $stage) {
            $stageConfig = (array) ($config['stages'][$stage->value] ?? []);
            $promptKey = $stage->usesModel() ? (string) ($stageConfig['prompt_key'] ?? $stage->value) : null;
            $prompt = $promptKey ? $activePrompts->get($promptKey) : null;

            $stages[] = [
                'label' => $stage->getLabel(),
                'required' => $stage->isRequired(),
                'enabled' => $stage->isRequired() || (bool) ($stageConfig['enabled'] ?? true),
                'uses_model' => $stage->usesModel(),
                // Same precedence as the LLM gateway: the workflow stage first, then the prompt version.
                'model' => ($stageConfig['model'] ?? null) ?: ($prompt?->model ?: config('statementra.ai.default_model')),
                'prompt_key' => $promptKey,
                'prompt' => $prompt,
                'reasoning' => ($stageConfig['reasoning_effort'] ?? null) ?: $prompt?->reasoning_effort,
                'max_output_tokens' => $stageConfig['max_output_tokens'] ?? null,
            ];
        }

        return [
            'workflow' => $workflow,
            'config' => $config,
            'stages' => $stages,
            'activePrompts' => $activePrompts,
            'provider' => [
                'name' => (string) config('statementra.ai.provider'),
                'key_configured' => filled(config('statementra.ai.openai_api_key')),
                'default_model' => (string) config('statementra.ai.default_model'),
                'writing_model' => (string) config('statementra.ai.writing_model'),
                'daily_budget' => (float) Settings::get('ai.daily_budget_usd', 250),
            ],
            'metrics' => $metrics,
            'statuses' => AiJobStatus::cases(),
            'failures' => (new AiMetrics(now()->subDays(self::WINDOW_DAYS)))->recentFailures(),
            'orderUrl' => fn (AiJob $job): ?string => $job->order ? AdminUrls::order($job->order) : null,
            'workflowUrl' => $workflow && AiWorkflowResource::canViewAny() ? AiWorkflowResource::getUrl('edit', ['record' => $workflow]) : null,
            'promptUrl' => fn (PromptVersion $version): ?string => PromptVersionResource::canViewAny() ? PromptVersionResource::getUrl('view', ['record' => $version]) : null,
            'limit' => fn (?string $text, int $length = 140): string => Str::limit((string) $text, $length),
        ];
    }
}
