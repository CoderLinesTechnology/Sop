<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Support\Operations\Format;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\Order;
use App\Models\OrderRequirement;
use App\Models\QualityReview;
use App\Models\ResearchClaim;
use App\Models\ResearchSource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * What the AI pipeline did: the research dossier, the requirement
 * verification log and every pipeline run with its steps, models, prompt
 * versions, tokens, costs, errors and quality reviews. These tabs are loaded
 * on demand because they can be large.
 */
class OrderPipelineTabs
{
    /** @return list<Tab> */
    public static function make(): array
    {
        return [self::research(), self::requirements(), self::processing()];
    }

    private static function research(): Tab
    {
        return Tab::make('Research')
            ->key('research')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'researchClaims'))
            ->schema(Schema::make()->deferLoading()->components([
                Section::make('Research dossier')
                    ->description('Claims gathered about the institution and programme, with their sources and verification result. Only claims marked safe to use may appear in the document.')
                    ->schema([
                        RepeatableEntry::make('researchClaims')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Claim'),
                                TableColumn::make('Category'),
                                TableColumn::make('Source'),
                                TableColumn::make('Verification'),
                                TableColumn::make('Confidence'),
                                TableColumn::make('Safe to use'),
                                TableColumn::make('Used'),
                            ])
                            ->schema([
                                TextEntry::make('claim')
                                    ->formatStateUsing(fn (?string $state, ResearchClaim $record): HtmlString => new HtmlString(
                                        '<span style="font-family:ui-monospace,monospace;font-size:11px;opacity:.6">'.e($record->claim_key).'</span> '.e((string) $state)
                                    ))
                                    ->helperText(fn (ResearchClaim $record): ?string => $record->supporting_quote ? '“'.str($record->supporting_quote)->limit(240).'”' : null),
                                TextEntry::make('category')
                                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->ucfirst()->toString()),
                                TextEntry::make('source_label')
                                    ->state(fn (ResearchClaim $record): ?string => $record->source ? ($record->source->title ?: $record->source->domain) : null)
                                    ->url(fn (ResearchClaim $record): ?string => Format::safeUrl($record->source?->url))
                                    ->openUrlInNewTab()
                                    ->helperText(fn (ResearchClaim $record): ?string => $record->source?->source_type?->getLabel())
                                    ->placeholder('No source'),
                                TextEntry::make('verification_status')
                                    ->badge()
                                    ->tooltip(fn (ResearchClaim $record): ?string => collect([$record->verification_method, $record->verification_notes])->filter()->implode(' — ') ?: null)
                                    ->helperText(fn (ResearchClaim $record): ?string => $record->conflict_group ? 'Conflict group '.$record->conflict_group : null),
                                TextEntry::make('confidence')
                                    ->formatStateUsing(fn ($state): string => $state === null ? Format::PLACEHOLDER : Format::percent((float) $state, 0))
                                    ->placeholder(Format::PLACEHOLDER),
                                IconEntry::make('safe_to_use')->boolean(),
                                IconEntry::make('used_in_document')->boolean(),
                            ])
                            ->visible(fn (Order $record): bool => $record->researchClaims->isNotEmpty()),
                        EmptyState::make('No research claims')
                            ->description('Research runs after payment, during the first pipeline stages.')
                            ->icon(Heroicon::OutlinedMagnifyingGlass)
                            ->contained(false)
                            ->visible(fn (Order $record): bool => $record->researchClaims->isEmpty()),
                    ]),
                Section::make('Sources')
                    ->description('Pages retrieved during research, ranked by authority (official sources first).')
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('researchSources')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Source'),
                                TableColumn::make('Type'),
                                TableColumn::make('Official'),
                                TableColumn::make('Rank'),
                                TableColumn::make('Fetch'),
                                TableColumn::make('Retrieved'),
                            ])
                            ->schema([
                                TextEntry::make('title')
                                    ->state(fn (ResearchSource $record): string => $record->title ?: $record->domain)
                                    ->url(fn (ResearchSource $record): ?string => Format::safeUrl($record->url))
                                    ->openUrlInNewTab()
                                    ->helperText(fn (ResearchSource $record): string => $record->domain),
                                TextEntry::make('source_type')->badge()->color('gray'),
                                IconEntry::make('is_official')->boolean(),
                                TextEntry::make('authority_rank'),
                                TextEntry::make('fetch_status')
                                    ->state(fn (ResearchSource $record): string => trim(($record->fetch_status ?? '').($record->http_status ? ' ('.$record->http_status.')' : '')) ?: Format::PLACEHOLDER),
                                TextEntry::make('retrieved_at')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                            ])
                            ->visible(fn (Order $record): bool => $record->researchSources->isNotEmpty()),
                        EmptyState::make('No sources retrieved')
                            ->icon(Heroicon::OutlinedGlobeAlt)
                            ->contained(false)
                            ->visible(fn (Order $record): bool => $record->researchSources->isEmpty()),
                    ]),
            ]));
    }

    private static function requirements(): Tab
    {
        return Tab::make('Requirements')
            ->key('requirements')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->schema(Schema::make()->deferLoading()->components([
                RepeatableEntry::make('requirementLogs')
                    ->hiddenLabel()
                    ->schema([
                        Grid::make(['default' => 2, 'md' => 4])->schema([
                            TextEntry::make('last_verified_at')->label('Verified')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                            TextEntry::make('template.name')->label('Template')->placeholder('Default'),
                            TextEntry::make('language_variant')->label('Language')->placeholder('Default'),
                            TextEntry::make('limits')
                                ->label('Limits')
                                ->state(fn (OrderRequirement $record): string => collect([
                                    $record->max_words ? number_format($record->max_words).' words' : null,
                                    $record->max_characters ? number_format($record->max_characters).' characters' : null,
                                    $record->max_pages ? $record->max_pages.' '.str('page')->plural($record->max_pages) : null,
                                ])->filter()->implode(' · ') ?: 'None'),
                        ]),
                        TextEntry::make('resolved')
                            ->label('Resolved requirements')
                            ->state(fn (OrderRequirement $record): HtmlString => Format::structured($record->resolved)),
                        TextEntry::make('conflicts')
                            ->label('Conflicts')
                            ->state(fn (OrderRequirement $record): HtmlString => Format::structured($record->conflicts))
                            ->color('warning')
                            ->visible(fn (OrderRequirement $record): bool => filled($record->conflicts)),
                        TextEntry::make('sources')
                            ->label('Sources checked')
                            ->state(fn (OrderRequirement $record): HtmlString => Format::structured($record->sources)),
                        TextEntry::make('applied_rule_ids')
                            ->label('Admin rules applied')
                            ->state(fn (OrderRequirement $record): array => array_map(fn ($id) => '#'.$id, (array) $record->applied_rule_ids))
                            ->badge()
                            ->color('gray')
                            ->placeholder('None'),
                    ])
                    ->visible(fn (Order $record): bool => $record->requirementLogs->isNotEmpty()),
                EmptyState::make('No requirement verification yet')
                    ->description('Requirements are resolved after research. The log shows what was applied and which sources were checked.')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->visible(fn (Order $record): bool => $record->requirementLogs->isEmpty()),
            ]));
    }

    private static function processing(): Tab
    {
        return Tab::make('AI processing')
            ->key('processing')
            ->icon(Heroicon::OutlinedCpuChip)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'aiJobs'))
            ->schema(Schema::make()->deferLoading()->components([
                RepeatableEntry::make('aiJobs')
                    ->hiddenLabel()
                    ->state(fn (Order $record) => $record->aiJobs->sortByDesc('id')->values())
                    ->schema([
                        Grid::make(['default' => 2, 'md' => 4])->schema([
                            TextEntry::make('kind')
                                ->label('Run')
                                ->formatStateUsing(fn (?string $state): string => match ($state) {
                                    AiJob::KIND_REVISION => 'Revision',
                                    AiJob::KIND_REGENERATION => 'Regeneration',
                                    default => 'Order pipeline',
                                })
                                ->helperText(fn (AiJob $record): string => 'Created '.Format::dateTime($record->created_at))
                                ->weight(FontWeight::SemiBold),
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('current_stage')
                                ->label('Current stage')
                                ->formatStateUsing(fn ($state) => $state?->getLabel())
                                ->placeholder(Format::PLACEHOLDER),
                            TextEntry::make('provider')
                                ->label('Provider')
                                ->helperText(fn (AiJob $record): ?string => $record->used_fallback ? 'Fallback workflow used' : null)
                                ->placeholder(Format::PLACEHOLDER),
                            TextEntry::make('timing')
                                ->label('Duration')
                                ->state(fn (AiJob $record): string => $record->started_at ? Format::minutes($record->durationMinutes()) : Format::PLACEHOLDER)
                                ->helperText(fn (AiJob $record): ?string => $record->started_at
                                    ? Format::dateTime($record->started_at).' → '.($record->finished_at ? Format::dateTime($record->finished_at) : 'running')
                                    : null),
                            TextEntry::make('tokens')
                                ->label('Tokens')
                                ->state(fn (AiJob $record): string => number_format((int) $record->total_input_tokens).' in · '.number_format((int) $record->total_output_tokens).' out')
                                ->helperText(fn (AiJob $record): string => number_format((int) $record->total_cached_tokens).' cached · '.number_format((int) $record->total_reasoning_tokens).' reasoning'),
                            TextEntry::make('calls')
                                ->label('Calls')
                                ->state(fn (AiJob $record): string => (int) $record->llm_calls.' model · '.(int) $record->search_calls.' search')
                                ->helperText(fn (AiJob $record): string => (int) $record->refinement_rounds.' refinement '.str('round')->plural((int) $record->refinement_rounds).' · '.(int) $record->failure_count.' '.str('failure')->plural((int) $record->failure_count)),
                            TextEntry::make('total_cost_usd')
                                ->label('Cost')
                                ->formatStateUsing(fn ($state): string => Format::usd($state))
                                ->helperText(fn (AiJob $record): ?string => $record->workflow ? 'Workflow: '.$record->workflow->name : null),
                        ]),
                        TextEntry::make('last_error')
                            ->label('Last error')
                            ->state(fn (AiJob $record): ?string => $record->last_error_message
                                ? trim(($record->last_error_code ? '['.$record->last_error_code.'] ' : '').$record->last_error_message)
                                : null)
                            ->color('danger')
                            ->fontFamily(FontFamily::Mono)
                            ->size(TextSize::Small)
                            ->visible(fn (AiJob $record): bool => filled($record->last_error_message)),
                        TextEntry::make('prompt_versions')
                            ->label('Prompt versions')
                            ->state(fn (AiJob $record): array => collect((array) $record->prompt_versions)
                                ->map(fn ($version, $key) => is_array($version)
                                    ? $key.' v'.($version['version'] ?? '?')
                                    : $key.' '.(is_numeric($version) ? 'v'.$version : $version))
                                ->values()
                                ->all())
                            ->badge()
                            ->color('gray')
                            ->placeholder(Format::PLACEHOLDER),
                        RepeatableEntry::make('steps')
                            ->label('Steps')
                            ->table([
                                TableColumn::make('#')->width('3rem'),
                                TableColumn::make('Stage'),
                                TableColumn::make('Status'),
                                TableColumn::make('Attempt'),
                                TableColumn::make('Model / prompt'),
                                TableColumn::make('Tokens'),
                                TableColumn::make('Cost'),
                                TableColumn::make('Duration'),
                                TableColumn::make('Error'),
                            ])
                            ->schema([
                                TextEntry::make('sequence'),
                                TextEntry::make('stage')->formatStateUsing(fn ($state) => $state?->getLabel()),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('attempt'),
                                TextEntry::make('model')
                                    ->fontFamily(FontFamily::Mono)
                                    ->size(TextSize::Small)
                                    ->helperText(fn (AiJobStep $record): ?string => $record->promptVersion
                                        ? $record->promptVersion->prompt_key.' v'.$record->promptVersion->version
                                        : null)
                                    ->placeholder('No model'),
                                TextEntry::make('step_tokens')
                                    ->state(fn (AiJobStep $record): string => number_format((int) $record->input_tokens).' / '.number_format((int) $record->output_tokens)),
                                TextEntry::make('cost_usd')->formatStateUsing(fn ($state): string => Format::usd($state)),
                                TextEntry::make('duration_ms')->formatStateUsing(fn ($state): string => Format::milliseconds($state === null ? null : (int) $state))->placeholder(Format::PLACEHOLDER),
                                TextEntry::make('error')
                                    ->state(fn (AiJobStep $record): ?string => $record->error_message
                                        ? trim(($record->error_code ? '['.$record->error_code.'] ' : '').str($record->error_message)->limit(160))
                                        : null)
                                    ->tooltip(fn (AiJobStep $record): ?string => $record->error_message)
                                    ->color('danger')
                                    ->size(TextSize::Small)
                                    ->placeholder(Format::PLACEHOLDER),
                            ])
                            ->visible(fn (AiJob $record): bool => $record->steps->isNotEmpty()),
                        RepeatableEntry::make('qualityReviews')
                            ->label('Quality reviews')
                            ->table([
                                TableColumn::make('Round')->width('4rem'),
                                TableColumn::make('Overall'),
                                TableColumn::make('Passed'),
                                TableColumn::make('Answers prompt'),
                                TableColumn::make('Scores'),
                                TableColumn::make('Issues'),
                                TableColumn::make('Reviewer'),
                            ])
                            ->schema([
                                TextEntry::make('round'),
                                TextEntry::make('overall_score')
                                    ->formatStateUsing(fn ($state, QualityReview $record): string => number_format((float) $state, 2).' / threshold '.number_format((float) $record->threshold, 2))
                                    ->color(fn (QualityReview $record): string => $record->passed ? 'success' : 'danger')
                                    ->weight(FontWeight::SemiBold),
                                IconEntry::make('passed')->boolean(),
                                IconEntry::make('answers_prompt')->boolean()->placeholder(Format::PLACEHOLDER),
                                TextEntry::make('scores')
                                    ->state(fn (QualityReview $record): array => collect((array) $record->scores)
                                        ->map(fn ($score, $key) => (QualityReview::CATEGORIES[$key] ?? Format::humanKey($key)).' '.(is_numeric($score) ? number_format((float) $score, 1) : (is_array($score) ? number_format((float) ($score['score'] ?? 0), 1) : $score)))
                                        ->values()
                                        ->all())
                                    ->listWithLineBreaks()
                                    ->size(TextSize::Small),
                                TextEntry::make('issues')
                                    ->state(fn (QualityReview $record): array => collect((array) $record->issues)
                                        ->map(fn ($issue) => is_array($issue) ? (string) ($issue['issue'] ?? $issue['description'] ?? $issue['message'] ?? json_encode($issue)) : (string) $issue)
                                        ->map(fn (string $issue) => str($issue)->limit(160)->toString())
                                        ->values()
                                        ->all())
                                    ->bulleted()
                                    ->size(TextSize::Small)
                                    ->limitList(5)
                                    ->expandableLimitedList()
                                    ->placeholder('None'),
                                TextEntry::make('reviewer')
                                    ->formatStateUsing(fn ($state): string => str((string) $state)->ucfirst()->toString())
                                    ->helperText(fn (QualityReview $record): ?string => $record->model),
                            ])
                            ->visible(fn (AiJob $record): bool => $record->qualityReviews->isNotEmpty()),
                    ])
                    ->visible(fn (Order $record): bool => $record->aiJobs->isNotEmpty()),
                EmptyState::make('AI processing has not started')
                    ->description('A pipeline run starts automatically once payment is verified. Use “Start processing” in the Processing menu if a paid order has not started.')
                    ->icon(Heroicon::OutlinedCpuChip)
                    ->visible(fn (Order $record): bool => $record->aiJobs->isEmpty()),
            ]));
    }
}
