<?php

namespace App\Filament\Support\Catalogue;

use App\Domain\Ai\Samples\WritingSampleSelector;
use App\Enums\PipelineStage;
use App\Models\AiWorkflow;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

/**
 * Structured editor for AiWorkflow::$config. The form always shows the
 * effective configuration (stored config merged over
 * AiWorkflow::defaultConfig()); saving stores the full normalised
 * configuration and keeps keys this editor does not know about.
 */
final class WorkflowConfigForm
{
    /** @return list<Tab> */
    public static function tabs(): array
    {
        return [
            Tab::make('Stages')
                ->icon(Heroicon::OutlinedQueueList)
                ->schema([
                    Text::make('Required stages always run. Model, prompt key and reasoning effort apply to stages that call a language model; prompt keys select the active prompt version (AI → Prompt versions).')
                        ->color('gray'),
                    ...array_map(self::stage(...), PipelineStage::ordered()),
                ]),
            Tab::make('Research')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->schema([
                    Section::make()
                        ->schema([
                            TextInput::make('config.research.max_search_calls')->label('Web searches per research stage')->integer()->minValue(0)->maxValue(100)->required(),
                            Select::make('config.research.search_context_size')->label('Search context size')->options(AiOptions::SEARCH_CONTEXT_SIZES)->required()->native(false),
                            Toggle::make('config.research.official_first')->label('Search official sources first'),
                            Toggle::make('config.research.allow_secondary_sources')->label('Allow secondary sources')
                                ->helperText('Official sources always win when information conflicts.'),
                            Toggle::make('config.research.verify_quotes')->label('Verify supporting quotes against the source'),
                        ])
                        ->columns(2),
                ]),
            Tab::make('Quality')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->schema([
                    Section::make()
                        ->schema([
                            TextInput::make('config.quality.threshold')->label('Pass threshold (overall score)')->numeric()->minValue(0)->maxValue(10)->step(0.1)->required()->suffix('/ 10'),
                            TextInput::make('config.quality.min_category_score')->label('Minimum score in every category')->numeric()->minValue(0)->maxValue(10)->step(0.1)->required()->suffix('/ 10'),
                            TextInput::make('config.quality.max_refinement_rounds')->label('Maximum refinement rounds')->integer()->minValue(0)->maxValue(5)->required()
                                ->helperText('How many times a document below the threshold is sent back for refinement.'),
                        ])
                        ->columns(3),
                    Section::make('Writing samples')
                        ->description('Example documents from AI → Writing samples that the planning, writing and editing stages study for structure, tone and specificity. Wording copied from a sample is removed by the factual review.')
                        ->schema([
                            Toggle::make('config.writing_samples.enabled')->label('Show writing samples to the writer'),
                            TextInput::make('config.writing_samples.max_samples')->label('Samples per order')->integer()->minValue(1)->maxValue(WritingSampleSelector::MAX_SAMPLES)->required()
                                ->helperText('Each sample adds roughly 2,000 input tokens to every writing-stage call.'),
                        ])
                        ->columns(2),
                ]),
            Tab::make('Limits & retries')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->schema([
                    Section::make('Per-order limits')
                        ->schema([
                            TextInput::make('config.limits.max_cost_usd')->label('Maximum estimated cost')->numeric()->minValue(0.01)->maxValue(1000)->step(0.01)->prefix('$')->required(),
                            TextInput::make('config.limits.max_llm_calls')->label('Maximum model calls')->integer()->minValue(1)->maxValue(500)->required(),
                            TextInput::make('config.limits.max_search_calls')->label('Maximum web searches')->integer()->minValue(0)->maxValue(500)->required(),
                            TextInput::make('config.limits.max_duration_minutes')->label('Maximum duration')->integer()->minValue(5)->maxValue(1440)->suffix('minutes')->required(),
                            TextInput::make('config.limits.max_stage_attempts')->label('Attempts per stage')->integer()->minValue(1)->maxValue(10)->required()
                                ->helperText('Retries of a failing stage before the order needs attention.'),
                            TextInput::make('config.limits.max_length_revisions')->label('Length-fix passes')->integer()->minValue(0)->maxValue(10)->required(),
                            Select::make('config.on_budget_exceeded')->label('When a limit is reached')->options(AiOptions::BUDGET_EXCEEDED)->required()->native(false)->columnSpanFull(),
                        ])
                        ->columns(3),
                    Section::make('Asking the customer for missing information')
                        ->schema([
                            Toggle::make('config.needs_information.enabled')->label('Allow follow-up questions to the customer'),
                            TextInput::make('config.needs_information.max_questions')->label('Maximum questions')->integer()->minValue(1)->maxValue(10)->required(),
                        ])
                        ->columns(2),
                ]),
        ];
    }

    private static function stage(PipelineStage $stage): Fieldset
    {
        $path = "config.stages.{$stage->value}";
        $children = [
            Toggle::make("{$path}.enabled")
                ->label($stage->isRequired() ? 'Required' : 'Enabled')
                ->disabled($stage->isRequired())
                ->default(true)
                ->inline(false),
        ];

        if ($stage->usesModel()) {
            $children = [
                ...$children,
                TextInput::make("{$path}.model")->label('Model')->required()->maxLength(80)->datalist(fn (): array => AiOptions::models()),
                TextInput::make("{$path}.prompt_key")->label('Prompt key')->required()->maxLength(60)->regex(AiOptions::PROMPT_KEY_PATTERN)
                    ->datalist(fn (): array => AiOptions::promptKeys()),
                Select::make("{$path}.reasoning_effort")->label('Reasoning')->options(AiOptions::REASONING_EFFORTS)->required()->native(false),
                TextInput::make("{$path}.max_output_tokens")->label('Max output tokens')->integer()->minValue(256)->maxValue(128000)->required(),
            ];
        } else {
            $children[] = Text::make('No language model is used at this stage.')->color('gray')->columnSpan(4);
        }

        return Fieldset::make($stage->getLabel())
            ->schema($children)
            ->columns(5);
    }

    /**
     * Normalised configuration to store: the edited values cast to the types of
     * the defaults, required stages forced on, unknown stored keys preserved.
     *
     * @param  array<string, mixed>  $edited
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function normalise(array $edited, ?array $stored = null): array
    {
        $defaults = AiWorkflow::defaultConfig();
        $config = array_replace_recursive($defaults, $stored ?? [], $edited);

        foreach (Arr::dot($defaults) as $key => $default) {
            $value = data_get($config, $key);
            data_set($config, $key, match (true) {
                is_bool($default) => (bool) $value,
                is_int($default) => is_numeric($value) ? (int) $value : $default,
                is_float($default) => is_numeric($value) ? round((float) $value, 4) : $default,
                default => blank($value) ? $default : (is_string($value) ? trim($value) : $value),
            });
        }

        foreach (PipelineStage::cases() as $stage) {
            if ($stage->isRequired()) {
                data_set($config, "stages.{$stage->value}.enabled", true);
            }
        }

        if (! array_key_exists((string) data_get($config, 'on_budget_exceeded'), AiOptions::BUDGET_EXCEEDED)) {
            $config['on_budget_exceeded'] = $defaults['on_budget_exceeded'];
        }

        return $config;
    }

    /**
     * Changed configuration values as dotted keys, for compact audit entries.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function changes(?array $before, ?array $after): array
    {
        return AuditDiff::changes(Arr::dot($before ?? []), Arr::dot($after ?? []));
    }
}
