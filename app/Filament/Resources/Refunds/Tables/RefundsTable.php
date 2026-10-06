<?php

namespace App\Filament\Resources\Refunds\Tables;

use App\Domain\Payments\RefundService;
use App\Enums\RefundStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\Refund;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RefundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'order:id,public_id,reference,email,customer_name,currency',
                'requestedBy:id,name',
                'approvedBy:id,name',
            ]))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->fontFamily(FontFamily::Mono)
                    ->description(fn (Refund $record): ?string => $record->order?->email)
                    ->url(fn (Refund $record): ?string => $record->order && OrderResource::canView($record->order)
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->searchable(['reference', 'email']),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Refund $record): string => Format::money((int) $state, $record->currency))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->description(fn (Refund $record): ?string => $record->status === RefundStatus::Failed ? $record->failure_reason : null)
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(60)
                    ->tooltip(fn (Refund $record): string => $record->reason)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('requested_by_label')
                    ->label('Requested by')
                    ->state(fn (Refund $record): string => $record->requestedBy?->name ?? ucfirst((string) $record->requested_by)),
                TextColumn::make('approvedBy.name')
                    ->label('Approved by')
                    ->placeholder(Format::PLACEHOLDER),
                TextColumn::make('provider_refund_id')
                    ->label('Provider reference')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(RefundStatus::class),
                SelectFilter::make('requested_by')
                    ->label('Requested by')
                    ->options(['admin' => 'Administrator', 'customer' => 'Customer', 'system' => 'System']),
                Filter::make('created_at')
                    ->label('Requested')
                    ->schema([
                        DatePicker::make('from')->label('Requested from'),
                        DatePicker::make('until')->label('Requested until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay())))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        ($data['from'] ?? null) ? Indicator::make('From '.Carbon::parse($data['from'])->format(Format::DATE))->removeField('from') : null,
                        ($data['until'] ?? null) ? Indicator::make('Until '.Carbon::parse($data['until'])->format(Format::DATE))->removeField('until') : null,
                    ]))),
            ])
            ->recordActions([
                self::approve(),
                ActionGroup::make([
                    ViewAction::make(),
                    self::reject(),
                    self::markProcessed(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedReceiptRefund)
            ->emptyStateHeading('No refunds')
            ->emptyStateDescription('Refunds requested from an order page appear here for approval.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->visible(fn (Refund $record): bool => $record->status === RefundStatus::Requested)
            ->requiresConfirmation()
            ->modalHeading('Approve this refund?')
            ->modalDescription(fn (Refund $record): string => Format::money($record->amount, $record->currency)
                .' for order '.$record->order?->reference.' is submitted to Paystack immediately.')
            ->modalSubmitActionLabel('Approve and submit')
            ->action(fn (Refund $record) => ActionRunner::run(
                fn () => app(RefundService::class)->approve($record, AdminContext::require()),
                'Refund approved',
                "Couldn't approve the refund",
                fn (Refund $refund): string => match ($refund->status) {
                    RefundStatus::Processed => 'Paystack processed the refund.',
                    RefundStatus::Failed => 'Paystack rejected the refund: '.($refund->failure_reason ?: 'no reason given').'. Mark it processed manually once paid by other means.',
                    default => 'Submitted to Paystack; the status updates when Paystack confirms.',
                },
            ));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('approve')
            ->visible(fn (Refund $record): bool => $record->status === RefundStatus::Requested)
            ->modalHeading('Reject this refund')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason for rejecting')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3),
            ])
            ->modalSubmitActionLabel('Reject refund')
            ->action(fn (array $data, Refund $record, Action $action) => ActionRunner::run(
                fn () => app(RefundService::class)->reject($record, AdminContext::require(), trim($data['reason'])),
                'Refund rejected',
                "Couldn't reject the refund",
                keepOpen: $action,
            ));
    }

    public static function markProcessed(): Action
    {
        return Action::make('markProcessed')
            ->label('Mark processed manually')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->authorize('approve')
            ->visible(fn (Refund $record): bool => in_array($record->status, [RefundStatus::Approved, RefundStatus::Processing, RefundStatus::Failed], true))
            ->modalHeading('Mark as processed manually')
            ->modalDescription('Use when the money was returned outside Paystack (for example by bank transfer). The order and payment are updated and the customer is emailed.')
            ->schema([
                TextInput::make('external_reference')
                    ->label('External reference')
                    ->helperText('Bank transfer or other payment reference.')
                    ->required()
                    ->maxLength(64),
            ])
            ->modalSubmitActionLabel('Mark processed')
            ->action(fn (array $data, Refund $record, Action $action) => ActionRunner::run(
                fn () => app(RefundService::class)->markProcessedManually($record, AdminContext::require(), trim($data['external_reference'])),
                'Refund marked as processed',
                "Couldn't update the refund",
                keepOpen: $action,
            ));
    }
}
