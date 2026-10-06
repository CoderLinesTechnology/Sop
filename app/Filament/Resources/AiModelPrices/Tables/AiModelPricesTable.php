<?php

namespace App\Filament\Resources\AiModelPrices\Tables;

use App\Filament\Support\Catalogue\AuditDiff;
use App\Models\AiModelPrice;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiModelPricesTable
{
    private const AUDITED = ['model', 'input_per_million', 'cached_input_per_million', 'output_per_million', 'web_search_per_call', 'notes', 'is_active'];

    public static function configure(Table $table): Table
    {
        $usd = fn (?string $state): string => $state === null ? '—' : '$'.rtrim(rtrim(number_format((float) $state, 4), '0'), '.');

        return $table
            ->defaultSort('model')
            ->columns([
                TextColumn::make('model')->searchable()->sortable()->weight('medium')->description(fn (AiModelPrice $record): ?string => $record->notes),
                TextColumn::make('input_per_million')->label('Input / 1M')->formatStateUsing($usd)->sortable(),
                TextColumn::make('cached_input_per_million')->label('Cached / 1M')->formatStateUsing($usd)->placeholder('—'),
                TextColumn::make('output_per_million')->label('Output / 1M')->formatStateUsing($usd)->sortable(),
                TextColumn::make('web_search_per_call')->label('Search / call')->formatStateUsing(fn (?string $state): string => '$'.rtrim(rtrim(number_format((float) $state, 6), '0'), '.')),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (AiModelPrice $record, array $data): void {
                        $snapshot = AuditDiff::snapshot($record, self::AUDITED);
                        $record->update($data);

                        [$before, $after] = AuditDiff::changes($snapshot, AuditDiff::snapshot($record, self::AUDITED));
                        if ($after !== []) {
                            Audit::log('ai_model_price.updated', $record, $before, $after);
                        }
                    }),
                DeleteAction::make()
                    ->after(fn (AiModelPrice $record) => Audit::log('ai_model_price.deleted', $record, AuditDiff::snapshot($record, self::AUDITED))),
            ]);
    }
}
