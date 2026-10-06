<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('admin:id,name'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('j M Y H:i:s')
                    ->description(fn (AuditLog $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'deleted'), str_contains($state, 'reset'), str_contains($state, 'archived') => 'danger',
                        str_contains($state, 'activated'), str_contains($state, 'created') => 'success',
                        default => 'gray',
                    })
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),
                TextColumn::make('actor')
                    ->label('By')
                    ->state(fn (AuditLog $record): string => $record->admin?->name ?? $record->actor_label ?? ucfirst((string) $record->actor_type))
                    ->description(fn (AuditLog $record): ?string => $record->admin ? null : ($record->actor_type !== 'admin' ? $record->actor_type : null)),
                TextColumn::make('target')
                    ->label('Target')
                    ->state(fn (AuditLog $record): ?string => $record->target_type
                        ? $record->target_type.($record->target_id ? ' #'.$record->target_id : '')
                        : null)
                    ->description(fn (AuditLog $record): ?string => $record->target_label)
                    ->placeholder('—'),
                TextColumn::make('changed')
                    ->label('Changed')
                    ->state(fn (AuditLog $record): ?string => ($keys = array_keys((array) ($record->after ?? $record->before ?? []))) !== []
                        ? implode(', ', array_slice($keys, 0, 4)).(count($keys) > 4 ? ' +'.(count($keys) - 4) : '')
                        : null)
                    ->placeholder('—')
                    ->color('gray')
                    ->wrap(),
                TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('admin_user_id')
                    ->label('Administrator')
                    ->relationship('admin', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('target_type')
                    ->label('Target type')
                    ->options(fn (): array => AuditLog::query()->whereNotNull('target_type')->distinct()->orderBy('target_type')->pluck('target_type', 'target_type')->all()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay())))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'From '.Carbon::parse($data['from'])->format('j M Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until '.Carbon::parse($data['until'])->format('j M Y');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Nothing audited yet');
    }
}
