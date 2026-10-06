<?php

namespace App\Filament\Resources\ContactMessages;

use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessages\Tables\ContactMessagesTable;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\Format;
use App\Models\ContactMessage;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** The support inbox: messages from the contact form (support.manage). */
class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static ?string $slug = 'support-inbox';

    protected static ?string $modelLabel = 'support message';

    protected static ?string $pluralModelLabel = 'support messages';

    protected static ?string $navigationLabel = 'Support inbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('name')->label('From'),
                TextEntry::make('email')->label('Email')->copyable(),
                TextEntry::make('created_at')->label('Received')->dateTime(Format::DATETIME),
                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => ContactMessagesTable::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => ContactMessagesTable::STATUSES[$state] ?? ucfirst((string) $state)),
                TextEntry::make('order_label')
                    ->label('Order')
                    ->state(fn (ContactMessage $record): ?string => $record->order?->reference ?? $record->order_reference)
                    ->url(fn (ContactMessage $record): ?string => $record->order && OrderResource::canView($record->order)
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->helperText(fn (ContactMessage $record): ?string => ! $record->order && $record->order_reference ? 'No order matches this reference.' : null)
                    ->placeholder('None given'),
                TextEntry::make('handled')
                    ->label('Handled')
                    ->state(fn (ContactMessage $record): ?string => $record->handled_at
                        ? Format::dateTime($record->handled_at).($record->handledBy ? ' by '.$record->handledBy->name : '')
                        : null)
                    ->placeholder('Not yet'),
                TextEntry::make('subject')->label('Subject')->placeholder('No subject')->columnSpanFull(),
                TextEntry::make('message')
                    ->label('Message')
                    ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(nl2br(e((string) $state))))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ContactMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactMessages::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = (int) Cache::remember('admin:operations:support-new-count', 60,
            fn () => ContactMessage::query()->where('status', 'new')->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'New support messages';
    }
}
