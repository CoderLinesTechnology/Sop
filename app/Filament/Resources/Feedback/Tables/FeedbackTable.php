<?php

namespace App\Filament\Resources\Feedback\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\Format;
use App\Models\Feedback;
use App\Models\Service;
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

class FeedbackTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'order:id,public_id,reference',
                'service' => fn ($q) => $q->select('id', 'name', 'deleted_at'),
                'workflow:id,name',
            ]))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('rating')
                    ->label('Rating')
                    ->formatStateUsing(fn ($state): string => self::stars((int) $state))
                    ->color(fn ($state): string => self::ratingColor((int) $state))
                    ->sortable(),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->fontFamily(FontFamily::Mono)
                    ->url(fn (Feedback $record): ?string => $record->order && OrderResource::canView($record->order)
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null),
                TextColumn::make('service.name')
                    ->label('Service')
                    ->placeholder(Format::PLACEHOLDER),
                TextColumn::make('liked')
                    ->label('Liked')
                    ->limit(60)
                    ->tooltip(fn (Feedback $record): ?string => $record->liked)
                    ->placeholder(Format::PLACEHOLDER)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('improve')
                    ->label('Could be better')
                    ->limit(60)
                    ->tooltip(fn (Feedback $record): ?string => $record->improve)
                    ->placeholder(Format::PLACEHOLDER)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('workflow.name')
                    ->label('AI workflow')
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
                TextColumn::make('prompt_versions')
                    ->label('Prompt versions')
                    ->state(fn (Feedback $record): array => self::promptVersions($record))
                    ->badge()
                    ->color('gray')
                    ->limitList(3)
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('service_id')
                    ->label('Service')
                    ->options(fn (): array => Service::withTrashed()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('rating')
                    ->label('Rating')
                    ->options([5 => '5 stars', 4 => '4 stars', 3 => '3 stars', 2 => '2 stars', 1 => '1 star']),
                Filter::make('created_at')
                    ->label('Submitted')
                    ->schema([
                        DatePicker::make('from')->label('Submitted from'),
                        DatePicker::make('until')->label('Submitted until'),
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
            ->emptyStateIcon(Heroicon::OutlinedStar)
            ->emptyStateHeading('No feedback yet')
            ->emptyStateDescription('Customers can rate their document after delivery.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }

    public static function stars(int $rating): string
    {
        $rating = max(0, min(5, $rating));

        return str_repeat('★', $rating).str_repeat('☆', 5 - $rating);
    }

    public static function ratingColor(int $rating): string
    {
        return match (true) {
            $rating >= 4 => 'success',
            $rating === 3 => 'warning',
            default => 'danger',
        };
    }

    /** @return list<string> e.g. ["writing v3", "quality_review v2"] */
    public static function promptVersions(Feedback $feedback): array
    {
        return collect((array) $feedback->prompt_versions)
            ->map(fn ($version, $key) => is_array($version)
                ? $key.' v'.($version['version'] ?? '?')
                : $key.' '.(is_numeric($version) ? 'v'.$version : $version))
            ->values()
            ->all();
    }
}
