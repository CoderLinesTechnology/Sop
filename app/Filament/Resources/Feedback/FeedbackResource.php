<?php

namespace App\Filament\Resources\Feedback;

use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Filament\Resources\Feedback\Tables\FeedbackTable;
use App\Filament\Resources\Feedback\Widgets\FeedbackStats;
use App\Filament\Support\Operations\Format;
use App\Models\Feedback;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Customer satisfaction ratings, stored with the service, AI workflow and
 * prompt versions that produced the document so they can be compared.
 * Read-only (feedback.view) with an audited CSV export.
 */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static ?string $slug = 'feedback';

    protected static ?string $modelLabel = 'feedback';

    protected static ?string $pluralModelLabel = 'feedback';

    protected static ?string $navigationLabel = 'Feedback';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('rating')
                    ->label('Rating')
                    ->formatStateUsing(fn ($state): string => FeedbackTable::stars((int) $state))
                    ->color(fn ($state): string => FeedbackTable::ratingColor((int) $state)),
                TextEntry::make('created_at')->label('Submitted')->dateTime(Format::DATETIME),
                TextEntry::make('order.reference')->label('Order'),
                TextEntry::make('service.name')->label('Service')->placeholder(Format::PLACEHOLDER),
                TextEntry::make('workflow.name')->label('AI workflow')->placeholder(Format::PLACEHOLDER),
                TextEntry::make('prompt_versions')
                    ->label('Prompt versions')
                    ->state(fn (Feedback $record): array => FeedbackTable::promptVersions($record))
                    ->badge()
                    ->color('gray')
                    ->placeholder(Format::PLACEHOLDER),
                TextEntry::make('liked')->label('What they liked')->placeholder('No comment')->columnSpanFull(),
                TextEntry::make('improve')->label('What could be better')->placeholder('No comment')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return FeedbackTable::configure($table);
    }

    public static function getWidgets(): array
    {
        return [FeedbackStats::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedback::route('/'),
        ];
    }
}
