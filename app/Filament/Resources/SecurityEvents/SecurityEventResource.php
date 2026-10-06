<?php

namespace App\Filament\Resources\SecurityEvents;

use App\Filament\Resources\SecurityEvents\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEvents\Tables\SecurityEventsTable;
use App\Filament\Support\Operations\Format;
use App\Models\SecurityEvent;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Suspicious activity recorded by App\Support\SecurityLog (bad webhook
 * signatures, payment mismatches, link enumeration, coupon brute force,
 * upload abuse, ...). Read-only (audit.view).
 */
class SecurityEventResource extends Resource
{
    protected static ?string $model = SecurityEvent::class;

    protected static ?string $slug = 'security-events';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 90;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('created_at')->label('When')->dateTime(Format::DATETIME),
                TextEntry::make('type')->label('Type')->fontFamily(FontFamily::Mono),
                TextEntry::make('severity')
                    ->label('Severity')
                    ->badge()
                    ->color(fn (?string $state): string => SecurityEventsTable::severityColor($state))
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                TextEntry::make('ip_address')->label('IP address')->fontFamily(FontFamily::Mono)->placeholder(Format::PLACEHOLDER),
                TextEntry::make('email')->label('Email')->placeholder(Format::PLACEHOLDER),
                TextEntry::make('order.reference')->label('Order')->placeholder(Format::PLACEHOLDER),
                TextEntry::make('path')->label('Path')->fontFamily(FontFamily::Mono)->placeholder(Format::PLACEHOLDER)->columnSpanFull(),
                TextEntry::make('details')
                    ->label('Details')
                    ->state(fn (SecurityEvent $record) => Format::json($record->details))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return SecurityEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecurityEvents::route('/'),
        ];
    }
}
