<?php

namespace App\Filament\Resources\Faqs\Tables;

use App\Models\Faq;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FaqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('service'))
            ->defaultSort('display_order')
            ->reorderable('display_order')
            ->columns([
                TextColumn::make('question')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->limit(110),
                TextColumn::make('scope')
                    ->label('Shown on')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'service' ? 'info' : 'gray')
                    ->formatStateUsing(fn (string $state, Faq $record): string => $state === 'service'
                        ? 'Service: '.($record->service?->name ?? 'archived service')
                        : (Faq::SCOPES[$state] ?? $state)),
                IconColumn::make('is_published')->label('Published')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('scope')->label('Shown on')->options(Faq::SCOPES),
                SelectFilter::make('service_id')->label('Service')->relationship('service', 'name'),
                TernaryFilter::make('is_published')->label('Published'),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => self::normalise($data)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** A service is only attached to service-scoped FAQs. */
    public static function normalise(array $data): array
    {
        if (($data['scope'] ?? null) !== 'service') {
            $data['service_id'] = null;
        }

        return $data;
    }
}
