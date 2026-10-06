<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Filament\Support\Catalogue\PageSections;
use App\Models\Page;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Page $record): string => $record->slug === 'home' ? '/' : '/'.$record->slug),
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => PageSections::usesSections($state) ? 'info' : 'gray')
                    ->formatStateUsing(fn (string $state): string => str(PageSections::KINDS[$state] ?? $state)->before(' (')->toString()),
                TextColumn::make('system')
                    ->label('')
                    ->state(fn (Page $record): ?string => PageSections::isSystemPage($record->slug) ? 'System page' : null)
                    ->badge()
                    ->color('warning'),
                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Type')->options(PageSections::KINDS),
                TernaryFilter::make('is_published')->label('Published'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('The page is removed from the site immediately. This cannot be undone.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
