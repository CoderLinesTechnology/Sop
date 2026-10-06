<?php

namespace App\Filament\Resources\PaymentEvents\Tables;

use App\Filament\Support\Operations\Format;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PaymentEventsTable
{
    private const STATUSES = [
        'pending' => 'Pending',
        'processed' => 'Processed',
        'ignored' => 'Ignored',
        'failed' => 'Failed',
        'rejected' => 'Rejected (bad signature)',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Event')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder(Format::PLACEHOLDER)
                    ->searchable(),
                IconColumn::make('signature_valid')
                    ->label('Signature')
                    ->boolean(),
                TextColumn::make('processing_status')
                    ->label('Processing')
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('source_ip')
                    ->label('Source IP')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('signature_valid')
                    ->label('Signature')
                    ->trueLabel('Valid signature')
                    ->falseLabel('Invalid signature'),
                SelectFilter::make('processing_status')
                    ->label('Processing status')
                    ->multiple()
                    ->options(self::STATUSES),
                SelectFilter::make('event_type')
                    ->label('Event')
                    ->options(fn (): array => \App\Models\PaymentEvent::query()->distinct()->orderBy('event_type')->pluck('event_type', 'event_type')->all()),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('4xl'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBolt)
            ->emptyStateHeading('No webhook deliveries yet')
            ->striped()
            ->defaultPaginationPageOption(25);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'processed' => 'success',
            'pending' => 'warning',
            'failed', 'rejected' => 'danger',
            default => 'gray',
        };
    }
}
