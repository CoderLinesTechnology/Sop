<?php

namespace App\Filament\Resources\WritingSamples\Tables;

use App\Enums\DocumentKind;
use App\Filament\Resources\WritingSamples\WritingSampleData;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\DocumentFormatOptions;
use App\Models\WritingSample;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WritingSamplesTable
{
    private const LIST_COLUMNS = [
        'id', 'title', 'document_kind', 'degree_level', 'field_of_study', 'country_code', 'word_count', 'source',
        'priority', 'is_active', 'created_by_admin_id', 'created_at', 'updated_at',
    ];

    public static function configure(Table $table): Table
    {
        // Country names, looked up at most once per table render.
        $countries = null;
        $countryNames = function () use (&$countries): array {
            return $countries ??= DocumentFormatOptions::countries();
        };

        return $table
            // The list never needs the (long, encrypted) text.
            ->modifyQueryUsing(fn (Builder $query) => $query->select(self::LIST_COLUMNS)->with('createdBy:id,name'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (WritingSample $record): ?string => self::matchSummary($record, $countryNames)),
                TextColumn::make('document_kind')
                    ->label('Document type')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('word_count')
                    ->label('Words')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('priority')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('createdBy.name')
                    ->label('Added by')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('document_kind')
                    ->label('Document type')
                    ->options(DocumentKind::class),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->after(fn (WritingSample $record) => Audit::log('writing_sample.deleted', $record, AuditDiff::snapshot($record, WritingSampleData::AUDITED))),
            ])
            ->emptyStateHeading('No writing samples yet')
            ->emptyStateDescription('Add strong SOPs, motivation letters, essays or CVs. The AI studies samples of the same document type for structure, tone and specificity.');
    }

    /** @param  \Closure(): array<string, string>  $countryNames */
    private static function matchSummary(WritingSample $sample, \Closure $countryNames): ?string
    {
        $parts = array_filter([
            $sample->field_of_study,
            $sample->degree_level ? (DocumentFormatOptions::DEGREE_LEVELS[$sample->degree_level] ?? $sample->degree_level) : null,
            $sample->country_code ? ($countryNames()[$sample->country_code] ?? $sample->country_code) : null,
        ]);

        return $parts !== [] ? implode(' · ', $parts) : null;
    }
}
