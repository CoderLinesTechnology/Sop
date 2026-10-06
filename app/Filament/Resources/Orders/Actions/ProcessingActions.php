<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Domain\Ai\PipelineDispatcher;
use App\Enums\PipelineStage;
use App\Filament\Resources\Orders\OrderInsights;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\OperationsAudit;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * AI pipeline controls (orders.manage). Every call goes through
 * PipelineDispatcher; failures — including operations the pipeline does not
 * support yet — are reported as notifications instead of breaking the page.
 */
final class ProcessingActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::start(),
            self::retry(),
            self::pause(),
            self::resume(),
            self::skipFailedStep(),
            self::cancel(),
            self::regenerate(),
        ];
    }

    public static function start(): Action
    {
        return Action::make('startProcessing')
            ->label('Start processing')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::canStartProcessing($record))
            ->requiresConfirmation()
            ->modalHeading('Start processing this order?')
            ->modalDescription('The order is paid but the AI pipeline has not started. Starting is idempotent and re-checks the verified payment.')
            ->modalSubmitActionLabel('Start processing')
            ->action(fn (Order $record) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.processing_started', $record,
                    fn () => app(PipelineDispatcher::class)->startForOrder($record)),
                'Processing started',
                "Couldn't start processing",
            ));
    }

    public static function retry(): Action
    {
        return Action::make('retryProcessing')
            ->label('Retry processing')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::canRetry($record))
            ->requiresConfirmation()
            ->modalHeading('Retry processing?')
            ->modalDescription('Retries the failed stage of the latest pipeline run, with its attempt counter reset. Completed stages are not repeated.')
            ->modalSubmitActionLabel('Retry')
            ->action(fn (Order $record) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.processing_retried', $record,
                    fn () => app(PipelineDispatcher::class)->retry($record, AdminContext::require())),
                'Processing retried',
                "Couldn't retry processing",
            ));
    }

    public static function pause(): Action
    {
        return Action::make('pauseProcessing')
            ->label('Pause processing')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::canPause($record))
            ->modalHeading('Pause processing')
            ->modalDescription('The pipeline stops after the stage that is currently running. The customer is not notified.')
            ->schema([self::reasonField('Why are you pausing this order?')])
            ->modalSubmitActionLabel('Pause')
            ->action(fn (array $data, Order $record, Action $action) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.processing_paused', $record,
                    fn () => app(PipelineDispatcher::class)->pause($record, AdminContext::require(), $data['reason']),
                    meta: ['reason' => $data['reason']]),
                'Processing paused',
                "Couldn't pause processing",
                keepOpen: $action,
            ));
    }

    public static function resume(): Action
    {
        return Action::make('resumeProcessing')
            ->label('Resume processing')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::canResume($record))
            ->modalHeading('Resume processing')
            ->modalDescription('Continues the pipeline from where it stopped. If the order is waiting for the customer, it continues with the information available.')
            ->schema([self::reasonField('Why are you resuming?')])
            ->modalSubmitActionLabel('Resume')
            ->action(fn (array $data, Order $record, Action $action) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.processing_resumed', $record,
                    fn () => app(PipelineDispatcher::class)->resume($record, $data['reason'], AdminContext::require()),
                    meta: ['reason' => $data['reason']]),
                'Processing resumed',
                "Couldn't resume processing",
                keepOpen: $action,
            ));
    }

    public static function skipFailedStep(): Action
    {
        return Action::make('skipFailedStep')
            ->label('Skip failed step')
            ->icon(Heroicon::OutlinedForward)
            ->color('warning')
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::failedStages($record) !== [])
            ->modalHeading('Skip a failed step')
            ->modalDescription('Marks the stage as skipped and continues with the next one. Required stages usually cannot be skipped; prefer retrying them.')
            ->schema(fn (Order $record): array => [
                Select::make('stage')
                    ->label('Failed stage')
                    ->options(OrderInsights::failedStages($record))
                    ->default(array_key_first(OrderInsights::failedStages($record)))
                    ->required()
                    ->native(false),
                self::reasonField('Why is it safe to skip this stage?'),
            ])
            ->modalSubmitActionLabel('Skip step')
            ->action(function (array $data, Order $record, Action $action): void {
                $stage = PipelineStage::from($data['stage']);

                ActionRunner::run(
                    fn () => OperationsAudit::ensure('order.processing_step_skipped', $record,
                        fn () => app(PipelineDispatcher::class)->skipStage($record, $stage, AdminContext::require(), $data['reason']),
                        meta: ['stage' => $stage->value, 'reason' => $data['reason']]),
                    $stage->getLabel().' skipped',
                    "Couldn't skip the step",
                    keepOpen: $action,
                );
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelProcessing')
            ->label('Cancel processing')
            ->icon(Heroicon::OutlinedStop)
            ->color('danger')
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::hasActiveJob($record))
            ->requiresConfirmation()
            ->modalHeading('Cancel processing?')
            ->modalDescription('Stops the current pipeline run. This does not refund the customer; use Refund or Change status afterwards if needed.')
            ->schema([self::reasonField('Why are you cancelling processing?')])
            ->modalSubmitActionLabel('Cancel processing')
            ->action(fn (array $data, Order $record, Action $action) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.processing_cancelled', $record,
                    fn () => app(PipelineDispatcher::class)->cancel($record, AdminContext::require(), $data['reason']),
                    meta: ['reason' => $data['reason']]),
                'Processing cancelled',
                "Couldn't cancel processing",
                keepOpen: $action,
            ));
    }

    public static function regenerate(): Action
    {
        return Action::make('regenerateDocument')
            ->label('Regenerate document')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::canRegenerate($record))
            ->requiresConfirmation()
            ->modalHeading('Regenerate the document?')
            ->modalDescription('Starts a completely new generation (research, writing and review) as a new pipeline run. Existing versions remain available. AI costs apply again.')
            ->modalSubmitActionLabel('Regenerate')
            ->action(fn (Order $record) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.regeneration_started', $record,
                    fn () => app(PipelineDispatcher::class)->regenerate($record, AdminContext::require())),
                'Regeneration started',
                "Couldn't start the regeneration",
            ));
    }

    private static function reasonField(string $placeholder): Textarea
    {
        return Textarea::make('reason')
            ->label('Reason')
            ->placeholder($placeholder)
            ->helperText('Recorded in the audit log.')
            ->required()
            ->maxLength(255)
            ->rows(2);
    }
}
