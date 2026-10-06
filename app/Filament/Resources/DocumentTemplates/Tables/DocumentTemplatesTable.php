<?php

namespace App\Filament\Resources\DocumentTemplates\Tables;

use App\Filament\Support\Catalogue\DocumentFormatOptions;
use App\Models\DocumentTemplate;
use App\Models\Service;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DocumentTemplatesTable
{
    public static function configure(Table $table): Table
    {
        // Service names, loaded at most once per table render.
        $serviceNames = null;
        $names = function () use (&$serviceNames): array {
            return $serviceNames ??= Service::withTrashed()->pluck('name', 'id')->all();
        };

        return $table
            ->defaultSort('is_default', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (DocumentTemplate $record): ?string => $record->description),
                TextColumn::make('default')
                    ->label('')
                    ->state(fn (DocumentTemplate $record): ?string => $record->is_default ? 'Default' : null)
                    ->badge()
                    ->color('success'),
                TextColumn::make('format')
                    ->label('Format')
                    ->state(fn (DocumentTemplate $record): string => sprintf(
                        '%s · %s %spt · %s spacing',
                        $record->page_size,
                        $record->font_family,
                        rtrim(rtrim(number_format((float) $record->font_size, 1), '0'), '.'),
                        rtrim(rtrim(number_format((float) $record->line_spacing, 2), '0'), '.'),
                    )),
                TextColumn::make('match')
                    ->label('Used for')
                    ->state(fn (DocumentTemplate $record): string => self::matchSummary($record, $names))
                    ->wrap(),
                TextColumn::make('priority')->sortable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable()->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** @param  \Closure(): array<int, string>  $serviceNames */
    private static function matchSummary(DocumentTemplate $template, \Closure $serviceNames): string
    {
        $rules = array_filter((array) $template->match_rules);
        if ($rules === []) {
            return $template->is_default ? 'Everything else' : 'Any order (general)';
        }

        $labels = [
            'services' => 'service',
            'document_kinds' => 'document type',
            'countries' => 'country',
            'institutions' => 'institution',
            'platforms' => 'platform',
            'degree_levels' => 'level',
        ];

        $parts = [];
        foreach ($rules as $criterion => $values) {
            $values = (array) $values;
            $shown = match ($criterion) {
                'countries' => array_map(fn ($code) => DocumentFormatOptions::countries()[$code] ?? $code, $values),
                'services' => array_map(fn ($id) => $serviceNames()[$id] ?? 'Service #'.$id, $values),
                default => $values,
            };
            $parts[] = count($values) > 2
                ? count($values).' '.($labels[$criterion] ?? $criterion).'s'
                : implode(', ', $shown);
        }

        return implode(' · ', $parts);
    }
}
