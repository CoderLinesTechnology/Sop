<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\Format;
use App\Filament\Support\Operations\RecordMemo;
use App\Models\Order;
use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class CustomerInfolist
{
    /** Orders listed on the customer page. */
    private const ORDER_LIMIT = 100;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Account')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('name')->label('Name')->placeholder('No name'),
                        TextEntry::make('email')->label('Email')->copyable(),
                        TextEntry::make('email_verified_at')->label('Email verified')->dateTime(Format::DATETIME)->placeholder('Not verified'),
                        TextEntry::make('last_login_at')->label('Last sign-in')->dateTime(Format::DATETIME)->placeholder('Never'),
                        TextEntry::make('created_at')->label('Joined')->dateTime(Format::DATETIME),
                        TextEntry::make('lifetime')
                            ->label('Paid orders')
                            ->state(function (User $record): string {
                                $orders = self::orders($record)->filter(fn (Order $order) => in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true));

                                return $orders->count().' · '.$orders->groupBy('currency')
                                    ->map(fn (Collection $group, string $currency) => Format::money((int) $group->sum('total_amount'), $currency))
                                    ->implode(', ');
                            }),
                    ]),
                Section::make('Orders')
                    ->description('Orders placed with this account or its email address (unpaid drafts excluded).')
                    ->schema([
                        RepeatableEntry::make('customer_orders')
                            ->hiddenLabel()
                            ->state(fn (User $record): Collection => self::orders($record))
                            ->table([
                                TableColumn::make('Reference'),
                                TableColumn::make('Service'),
                                TableColumn::make('Application'),
                                TableColumn::make('Status'),
                                TableColumn::make('Total'),
                                TableColumn::make('Created'),
                            ])
                            ->schema([
                                TextEntry::make('reference')
                                    ->fontFamily(FontFamily::Mono)
                                    ->url(fn (Order $record): ?string => OrderResource::canView($record) ? OrderResource::getUrl('view', ['record' => $record]) : null),
                                TextEntry::make('service_name')->state(fn (Order $record): string => $record->serviceName()),
                                TextEntry::make('programme')
                                    ->state(fn (Order $record): string => $record->applicationTitle())
                                    ->helperText(fn (Order $record): ?string => $record->user_id ? null : 'Guest checkout'),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('total_amount')->formatStateUsing(fn ($state, Order $record): string => Format::money((int) $state, $record->currency)),
                                TextEntry::make('created_at')->dateTime(Format::DATETIME),
                            ])
                            ->visible(fn (User $record): bool => self::orders($record)->isNotEmpty()),
                        EmptyState::make('No orders yet')
                            ->icon(Heroicon::OutlinedShoppingBag)
                            ->contained(false)
                            ->visible(fn (User $record): bool => self::orders($record)->isEmpty()),
                    ]),
            ]);
    }

    /** @return Collection<int, Order> loaded once per request */
    private static function orders(User $user): Collection
    {
        return RecordMemo::remember($user, 'orders', fn (): Collection => $user->ordersByEmail()
            ->submitted()
            ->with(['service' => fn ($q) => $q->select('id', 'name', 'deleted_at')])
            ->latest()
            ->limit(self::ORDER_LIMIT)
            ->get());
    }
}
