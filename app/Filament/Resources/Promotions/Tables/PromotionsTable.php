<?php

namespace App\Filament\Resources\Promotions\Tables;

use App\Filament\Support\Operations\AuditSnapshot;
use App\Filament\Support\Operations\DiscountFields;
use App\Filament\Support\Operations\Format;
use App\Models\Promotion;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PromotionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('services'))
            ->columns([
                TextColumn::make('name')
                    ->label('Promotion')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Promotion $record): ?string => $record->label)
                    ->searchable(['name', 'label'])
                    ->sortable(),
                TextColumn::make('discount')
                    ->label('Discount')
                    ->state(fn (Promotion $record): string => DiscountFields::describe($record)),
                TextColumn::make('applies')
                    ->label('Services')
                    ->state(fn (Promotion $record): string => $record->applies_to_all_services
                        ? 'All services'
                        : $record->services_count.' '.str('service')->plural((int) $record->services_count)),
                TextColumn::make('running')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Promotion $record): string => self::state($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Running now' => 'success',
                        'Scheduled' => 'info',
                        'Ended' => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): ?Heroicon => $state === 'Running now' ? Heroicon::OutlinedBolt : null),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime(Format::DATETIME)
                    ->placeholder('Immediately')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime(Format::DATETIME)
                    ->placeholder('No end')
                    ->description(fn (Promotion $record): ?string => $record->ends_at && $record->ends_at->isFuture() ? 'in '.$record->ends_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE) : null)
                    ->sortable(),
                IconColumn::make('show_countdown')
                    ->label('Countdown')
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('show_banner')
                    ->label('Banner')
                    ->boolean()
                    ->tooltip(fn (Promotion $record): ?string => $record->banner_text)
                    ->toggleable(),
                TextColumn::make('priority')
                    ->label('Priority')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('schedule')
                    ->label('Status')
                    ->options([
                        'running' => 'Running now',
                        'scheduled' => 'Scheduled',
                        'ended' => 'Ended',
                        'inactive' => 'Switched off',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'running' => $query->running(),
                        'scheduled' => $query->where('is_active', true)->where('starts_at', '>', now()),
                        'ended' => $query->where('ends_at', '<=', now()),
                        'inactive' => $query->where('is_active', false),
                        default => $query,
                    }),
                TrashedFilter::make()->label('Archived'),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
                self::restoreAction(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedMegaphone)
            ->emptyStateHeading('No promotions yet')
            ->emptyStateDescription('Create a limited-time offer, an early-bird discount or a seasonal sale.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Archive')
            ->modalHeading('Archive promotion')
            ->modalDescription('The promotion stops applying immediately. Orders that already received it are not affected, and it can be restored.')
            ->modalSubmitActionLabel('Archive')
            ->successNotificationTitle('Promotion archived')
            ->after(fn (Promotion $record) => Audit::log('promotion.deleted', $record, before: AuditSnapshot::of($record, ['services'])));
    }

    public static function restoreAction(): RestoreAction
    {
        return RestoreAction::make()
            ->successNotificationTitle('Promotion restored')
            ->after(fn (Promotion $record) => Audit::log('promotion.restored', $record, after: AuditSnapshot::of($record, ['services'])));
    }

    public static function state(Promotion $promotion): string
    {
        return match (true) {
            $promotion->trashed() => 'Archived',
            ! $promotion->is_active => 'Off',
            $promotion->isRunning() => 'Running now',
            $promotion->starts_at !== null && $promotion->starts_at->isFuture() => 'Scheduled',
            default => 'Ended',
        };
    }
}
