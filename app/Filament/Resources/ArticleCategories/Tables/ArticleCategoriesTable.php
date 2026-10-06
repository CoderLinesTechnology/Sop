<?php

namespace App\Filament\Resources\ArticleCategories\Tables;

use App\Models\ArticleCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ArticleCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('articles'))
            ->defaultSort('display_order')
            ->reorderable('display_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (ArticleCategory $record): ?string => $record->description),
                TextColumn::make('slug')->color('gray')->toggleable(),
                TextColumn::make('icon')->badge()->color('gray')->toggleable(),
                TextColumn::make('articles_count')->label('Articles')->numeric(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (ArticleCategory $record): string => $record->articles_count > 0
                        ? "{$record->articles_count} article(s) in this category will become uncategorised."
                        : 'This category will be removed.'),
            ]);
    }
}
