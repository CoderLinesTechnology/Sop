<?php

namespace App\Filament\Resources\PaymentEvents;

use App\Filament\Resources\PaymentEvents\Pages\ListPaymentEvents;
use App\Filament\Resources\PaymentEvents\Tables\PaymentEventsTable;
use App\Filament\Support\Operations\Format;
use App\Models\PaymentEvent;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Raw webhook deliveries from the payment provider, including rejected ones
 * (invalid signature). Read-only, for reconciliation and forensics.
 */
class PaymentEventResource extends Resource
{
    protected static ?string $model = PaymentEvent::class;

    protected static ?string $slug = 'payment-events';

    protected static ?string $modelLabel = 'webhook delivery';

    protected static ?string $pluralModelLabel = 'webhook deliveries';

    protected static ?string $navigationLabel = 'Webhook deliveries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 4;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('created_at')->label('Received')->dateTime(Format::DATETIME),
                TextEntry::make('event_type')->label('Event')->fontFamily(FontFamily::Mono),
                TextEntry::make('provider')->label('Provider')->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                TextEntry::make('reference')->label('Reference')->fontFamily(FontFamily::Mono)->placeholder(Format::PLACEHOLDER),
                IconEntry::make('signature_valid')->label('Signature valid')->boolean(),
                TextEntry::make('processing_status')
                    ->label('Processing')
                    ->badge()
                    ->color(fn (?string $state): string => PaymentEventsTable::statusColor($state)),
                TextEntry::make('source_ip')->label('Source IP')->fontFamily(FontFamily::Mono)->placeholder(Format::PLACEHOLDER),
                TextEntry::make('processed_at')->label('Processed')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                TextEntry::make('provider_event_id')->label('Provider event id')->fontFamily(FontFamily::Mono)->placeholder(Format::PLACEHOLDER),
                TextEntry::make('payload_hash')->label('Payload hash')->fontFamily(FontFamily::Mono)->size('xs'),
                TextEntry::make('processing_notes')->label('Processing notes')->placeholder(Format::PLACEHOLDER)->columnSpanFull(),
                TextEntry::make('payload')
                    ->label('Payload')
                    ->state(fn (PaymentEvent $record) => Format::json($record->payload))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return PaymentEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentEvents::route('/'),
        ];
    }
}
