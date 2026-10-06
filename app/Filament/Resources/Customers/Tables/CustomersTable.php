<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Support\Operations\Format;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect([
                'orders_count' => Order::query()
                    ->selectRaw('count(*)')
                    ->where(fn ($q) => $q->whereColumn('orders.user_id', 'users.id')->orWhereColumn('orders.email', 'users.email'))
                    ->whereNotIn('orders.status', ['NEW', 'FORM_SUBMITTED']),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->placeholder('No name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->email_verified_at !== null),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->numeric()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('orders_count', $direction)),
                TextColumn::make('last_login_at')
                    ->label('Last sign-in')
                    ->dateTime(Format::DATETIME)
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('email_verified_at')
                    ->label('Email verified')
                    ->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading('No customer accounts yet')
            ->emptyStateDescription('Customers can order without an account; accounts appear here when created.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }
}
