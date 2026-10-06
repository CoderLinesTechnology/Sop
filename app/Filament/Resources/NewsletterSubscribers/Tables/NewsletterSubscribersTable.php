<?php

namespace App\Filament\Resources\NewsletterSubscribers\Tables;

use App\Filament\Support\Operations\Format;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NewsletterSubscribersTable
{
    public const STATUSES = [
        'pending' => 'Pending confirmation',
        'confirmed' => 'Confirmed',
        'unsubscribed' => 'Unsubscribed',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('source')
                    ->label('Source')
                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace(['_', '-'], ' ')->ucfirst()->toString())
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Subscribed')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('confirmed_at')
                    ->label('Confirmed')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->sortable(),
                TextColumn::make('unsubscribed_at')
                    ->label('Unsubscribed')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
                TextColumn::make('consented_at')
                    ->label('Consent recorded')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::STATUSES),
            ])
            ->emptyStateIcon(Heroicon::OutlinedEnvelopeOpen)
            ->emptyStateHeading('No subscribers yet')
            ->striped()
            ->defaultPaginationPageOption(50);
    }
}
