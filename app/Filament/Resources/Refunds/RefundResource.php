<?php

namespace App\Filament\Resources\Refunds;

use App\Enums\RefundStatus;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Resources\Refunds\Tables\RefundsTable;
use App\Filament\Support\Operations\Format;
use App\Models\Refund;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Refund requests and their approval workflow. Refunds are created from the
 * order page; approval, rejection and manual completion go through
 * RefundService (refunds.approve).
 */
class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static ?string $slug = 'refunds';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('order.reference')->label('Order')->fontFamily(FontFamily::Mono),
                TextEntry::make('payment.reference')->label('Payment')->fontFamily(FontFamily::Mono),
                TextEntry::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Refund $record): string => Format::money((int) $state, $record->currency)),
                TextEntry::make('status')->label('Status')->badge(),
                TextEntry::make('requested_by_label')
                    ->label('Requested by')
                    ->state(fn (Refund $record): string => $record->requestedBy?->name ?? ucfirst((string) $record->requested_by)),
                TextEntry::make('approvedBy.name')->label('Approved by')->placeholder(Format::PLACEHOLDER),
                TextEntry::make('created_at')->label('Requested')->dateTime(Format::DATETIME),
                TextEntry::make('processed_at')->label('Processed')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                TextEntry::make('provider_refund_id')->label('Provider reference')->placeholder(Format::PLACEHOLDER),
                TextEntry::make('provider_status')->label('Provider status')->placeholder(Format::PLACEHOLDER),
                TextEntry::make('reason')->label('Reason')->columnSpanFull(),
                TextEntry::make('notes')->label('Notes')->placeholder(Format::PLACEHOLDER)->columnSpanFull(),
                TextEntry::make('failure_reason')->label('Failure')->color('danger')->visible(fn (Refund $record): bool => filled($record->failure_reason))->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return RefundsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefunds::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = (int) Cache::remember('admin:operations:refunds-awaiting-approval', 60,
            fn () => Refund::query()->where('status', RefundStatus::Requested->value)->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Refunds waiting for approval';
    }
}
