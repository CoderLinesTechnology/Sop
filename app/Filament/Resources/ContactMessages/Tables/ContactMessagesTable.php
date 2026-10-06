<?php

namespace App\Filament\Resources\ContactMessages\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\ContactMessage;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ContactMessagesTable
{
    public const STATUSES = [
        'new' => 'New',
        'open' => 'Open',
        'closed' => 'Handled',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order:id,public_id,reference', 'handledBy:id,name']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime(Format::DATETIME)
                    ->description(fn (ContactMessage $record): string => $record->created_at->diffForHumans())
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('name')
                    ->label('From')
                    ->weight(fn (ContactMessage $record): ?FontWeight => $record->status === 'new' ? FontWeight::SemiBold : null)
                    ->description(fn (ContactMessage $record): string => $record->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('subject')
                    ->label('Message')
                    ->state(fn (ContactMessage $record): string => $record->subject ?: Str::limit($record->message, 60))
                    ->description(fn (ContactMessage $record): ?string => $record->subject ? Str::limit($record->message, 90) : null)
                    ->wrap()
                    ->searchable(['subject', 'message']),
                TextColumn::make('order_label')
                    ->label('Order')
                    ->state(fn (ContactMessage $record): ?string => $record->order?->reference ?? $record->order_reference)
                    ->fontFamily(FontFamily::Mono)
                    ->color(fn (ContactMessage $record): ?string => $record->order ? 'primary' : 'gray')
                    ->url(fn (ContactMessage $record): ?string => $record->order && OrderResource::canView($record->order)
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->tooltip(fn (ContactMessage $record): ?string => ! $record->order && $record->order_reference ? 'No matching order' : null)
                    ->placeholder(Format::PLACEHOLDER),
                TextColumn::make('handledBy.name')
                    ->label('Handled by')
                    ->description(fn (ContactMessage $record): ?string => $record->handled_at ? Format::dateTime($record->handled_at) : null)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(self::STATUSES)
                    ->default(['new', 'open']),
                TernaryFilter::make('order_id')
                    ->label('Linked order')
                    ->nullable()
                    ->trueLabel('With an order')
                    ->falseLabel('Without an order'),
            ])
            ->recordActions([
                self::markHandled(),
                ActionGroup::make([
                    ViewAction::make(),
                    Action::make('reply')
                        ->label('Reply by email')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->url(fn (ContactMessage $record): string => 'mailto:'.rawurlencode($record->email)
                            .'?subject='.rawurlencode('Re: '.($record->subject ?: 'Your message to us'))),
                    self::markOpen(),
                    self::reopen(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading('Inbox zero')
            ->emptyStateDescription('No support messages match the filters.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'new' => 'warning',
            'open' => 'info',
            'closed' => 'success',
            default => 'gray',
        };
    }

    public static function markHandled(): Action
    {
        return Action::make('markHandled')
            ->label('Mark handled')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('handle')
            ->visible(fn (ContactMessage $record): bool => $record->status !== 'closed')
            ->action(fn (ContactMessage $record) => self::setStatus($record, 'closed', 'support.message_handled', 'Marked as handled'));
    }

    public static function markOpen(): Action
    {
        return Action::make('markOpen')
            ->label('Mark in progress')
            ->icon(Heroicon::OutlinedClock)
            ->authorize('handle')
            ->visible(fn (ContactMessage $record): bool => $record->status === 'new')
            ->action(fn (ContactMessage $record) => self::setStatus($record, 'open', 'support.message_opened', 'Marked as in progress'));
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Reopen')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->authorize('handle')
            ->visible(fn (ContactMessage $record): bool => $record->status === 'closed')
            ->action(fn (ContactMessage $record) => self::setStatus($record, 'open', 'support.message_reopened', 'Message reopened'));
    }

    private static function setStatus(ContactMessage $message, string $status, string $auditAction, string $title): void
    {
        $before = $message->status;
        $admin = AdminContext::require();

        $handled = $status === 'closed';

        $message->forceFill([
            'status' => $status,
            'handled_by_admin_id' => $handled ? $admin->id : null,
            'handled_at' => $handled ? now() : null,
        ])->save();

        Audit::log($auditAction, $message, ['status' => $before], ['status' => $status]);

        Notification::make()->success()->title($title)->send();
    }
}
