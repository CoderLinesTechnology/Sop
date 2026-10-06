<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Models\Article;
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

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->limit(70)
                    ->description(fn (Article $record): string => '/resources/'.$record->slug),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->state(fn (Article $record): string => match (true) {
                        ! $record->is_published => 'Draft',
                        ArticleForm::isLive($record) => 'Published',
                        default => 'Scheduled',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Scheduled' => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('is_featured')->label('Featured')->boolean()->sortable(),
                TextColumn::make('published_at')->label('Publish date')->dateTime('j M Y H:i')->sortable()->placeholder('—'),
                TextColumn::make('reading_minutes')->label('Read')->suffix(' min')->toggleable(),
                TextColumn::make('author_name')->label('Author')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('article_category_id')->label('Category')->relationship('category', 'name'),
                TernaryFilter::make('is_published')->label('Published'),
                TernaryFilter::make('is_featured')->label('Featured'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
