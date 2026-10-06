<?php

namespace App\Filament\Resources\SecurityEvents\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\Format;
use App\Models\SecurityEvent;
use Carbon\Carbon;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SecurityEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order:id,public_id,reference'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('severity')
                    ->label('Severity')
                    ->badge()
                    ->color(fn (?string $state): string => self::severityColor($state))
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label('IP address')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder(Format::PLACEHOLDER)
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder(Format::PLACEHOLDER)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->fontFamily(FontFamily::Mono)
                    ->url(fn (SecurityEvent $record): ?string => $record->order && OrderResource::canView($record->order)
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
                TextColumn::make('path')
                    ->label('Path')
                    ->fontFamily(FontFamily::Mono)
                    ->limit(40)
                    ->tooltip(fn (SecurityEvent $record): ?string => $record->path)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('severity')
                    ->label('Severity')
                    ->multiple()
                    ->options(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']),
                SelectFilter::make('type')
                    ->label('Type')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => SecurityEvent::query()->distinct()->orderBy('type')->pluck('type', 'type')->all()),
                Filter::make('created_at')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
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
                ViewAction::make()->modalWidth('3xl'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('No security events')
            ->emptyStateDescription('Nothing suspicious has been recorded.')
            ->striped()
            ->defaultPaginationPageOption(50);
    }

    public static function severityColor(?string $severity): string
    {
        return match ($severity) {
            'high' => 'danger',
            'medium' => 'warning',
            default => 'gray',
        };
    }
}
