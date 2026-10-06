<?php

namespace App\Filament\Resources\EmailTemplates\Tables;

use App\Enums\EmailTemplateKey;
use App\Models\EmailTemplate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EmailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (EmailTemplate $record): string => $record->key),
                TextColumn::make('subject')
                    ->searchable()
                    ->limit(70)
                    ->color('gray'),
                TextColumn::make('kind')
                    ->label('')
                    ->state(fn (EmailTemplate $record): string => EmailTemplateKey::tryFrom($record->key)?->isEssential() ? 'Essential' : 'Optional')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Essential' ? 'warning' : 'gray'),
                IconColumn::make('is_active')->label('Sending')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Sending'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
