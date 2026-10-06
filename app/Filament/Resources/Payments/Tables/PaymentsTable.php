<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentRecordStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\Format;
use App\Models\Payment;
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

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order:id,public_id,reference,email'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->fontFamily(FontFamily::Mono)
                    ->description(fn (Payment $record): ?string => $record->order?->email)
                    ->url(fn (Payment $record): ?string => $record->order && OrderResource::canView($record->order)
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->searchable(['reference', 'email']),
                TextColumn::make('purpose')
                    ->label('Purpose')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Payment $record): string => Format::money((int) $state, $record->currency))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->description(fn (Payment $record): ?string => $record->failure_reason ? str($record->failure_reason)->limit(60)->toString() : null)
                    ->sortable(),
                TextColumn::make('channel')
                    ->label('Channel')
                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->ucfirst()->toString())
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->sortable(),
                TextColumn::make('verified_at')
                    ->label('Verified')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(PaymentRecordStatus::class),
                SelectFilter::make('purpose')
                    ->label('Purpose')
                    ->options(['order' => 'Order', 'revision' => 'Revision fee']),
                Filter::make('mismatch')
                    ->label('Verification mismatches')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                        ->where('status', PaymentRecordStatus::Mismatch->value)
                        ->orWhereNotNull('mismatch_details'))),
                Filter::make('created_at')
                    ->label('Created')
                    ->schema([
                        DatePicker::make('from')->label('Created from'),
                        DatePicker::make('until')->label('Created until'),
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
                ViewAction::make(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCreditCard)
            ->emptyStateHeading('No payments yet')
            ->striped()
            ->defaultPaginationPageOption(25);
    }
}
