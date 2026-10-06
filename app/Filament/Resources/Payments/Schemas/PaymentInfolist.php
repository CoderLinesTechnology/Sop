<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Filament\Support\Operations\Format;
use App\Models\Payment;
use App\Models\Refund;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Payment')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('reference')->label('Reference')->fontFamily(FontFamily::Mono)->copyable(),
                        TextEntry::make('order.reference')->label('Order')->fontFamily(FontFamily::Mono),
                        TextEntry::make('amount')
                            ->label('Amount')
                            ->formatStateUsing(fn ($state, Payment $record): string => Format::money((int) $state, $record->currency))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('purpose')->label('Purpose')->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                        TextEntry::make('currency')->label('Currency'),
                        TextEntry::make('provider')->label('Provider')->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                        TextEntry::make('provider_transaction_id')->label('Provider transaction')->fontFamily(FontFamily::Mono)->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('channel')->label('Channel')->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('fees')
                            ->label('Provider fees')
                            ->formatStateUsing(fn ($state, Payment $record): string => Format::money((int) $state, $record->currency))
                            ->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('gateway_response')->label('Gateway response')->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('customer_email')->label('Customer email')->placeholder(Format::PLACEHOLDER),
                    ]),
                Section::make('Timeline')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('created_at')->label('Initialized')->dateTime(Format::DATETIME),
                        TextEntry::make('expires_at')->label('Expires')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('paid_at')->label('Paid')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('verified_at')->label('Verified')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('ip_address')->label('Customer IP')->fontFamily(FontFamily::Mono)->placeholder(Format::PLACEHOLDER),
                    ]),
                Section::make('Verification')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('failure_reason')->label('Failure reason')->color('danger')->placeholder('None'),
                        TextEntry::make('mismatch_details')
                            ->label('Mismatch details')
                            ->state(fn (Payment $record) => Format::json($record->mismatch_details))
                            ->visible(fn (Payment $record): bool => filled($record->mismatch_details)),
                    ]),
                Section::make('Refunds')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('refundable')
                            ->label('Refundable balance')
                            ->state(fn (Payment $record): string => Format::money($record->refundableAmount(), $record->currency)),
                        RepeatableEntry::make('refunds')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Requested'),
                                TableColumn::make('Amount'),
                                TableColumn::make('Status'),
                                TableColumn::make('Reason'),
                                TableColumn::make('Processed'),
                            ])
                            ->schema([
                                TextEntry::make('created_at')->dateTime(Format::DATETIME),
                                TextEntry::make('amount')->formatStateUsing(fn ($state, Refund $record): string => Format::money((int) $state, $record->currency)),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('reason')->limit(120),
                                TextEntry::make('processed_at')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                            ])
                            ->visible(fn (Payment $record): bool => $record->refunds->isNotEmpty()),
                    ])
                    ->visible(fn (Payment $record): bool => $record->purpose === 'order'),
            ]);
    }
}
