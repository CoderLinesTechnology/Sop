<?php

namespace App\Filament\Resources\Testimonials\Tables;

use App\Filament\Support\Catalogue\MediaUpload;
use App\Models\Testimonial;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TestimonialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('display_order')
            ->reorderable('display_order')
            ->columns([
                ImageColumn::make('avatar_path')
                    ->label('')
                    ->disk(MediaUpload::diskName())
                    ->circular()
                    ->imageSize(36),
                TextColumn::make('author_name')
                    ->label('Name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Testimonial $record): ?string => $record->author_detail),
                TextColumn::make('quote')->limit(90)->wrap()->searchable(),
                TextColumn::make('rating')
                    ->formatStateUsing(fn (?int $state): string => $state ? str_repeat('★', $state) : '—')
                    ->color('warning'),
                IconColumn::make('is_published')->label('Published')->boolean(),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
            ])
            ->filters([
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
