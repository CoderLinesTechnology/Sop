<?php

namespace App\Filament\Resources\RequirementRules\Tables;

use App\Domain\Documents\ResolvedRequirements;
use App\Filament\Support\Catalogue\DocumentFormatOptions;
use App\Filament\Support\Catalogue\RequirementRuleAudit;
use App\Models\RequirementRule;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RequirementRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('verifiedBy:id,name'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable(['name', 'institution_name', 'programme_name', 'application_platform'])
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (RequirementRule $record): ?string => self::target($record)),
                TextColumn::make('scope')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => RequirementRule::SCOPES[$state] ?? $state),
                TextColumn::make('limits')
                    ->label('Limits')
                    ->state(fn (RequirementRule $record): ?string => self::limits($record))
                    ->placeholder('—'),
                TextColumn::make('language_variant')
                    ->label('Language')
                    ->formatStateUsing(fn (?string $state): string => ResolvedRequirements::LANGUAGE_VARIANTS[$state] ?? (string) $state)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('last_verified_at')
                    ->label('Verified')
                    ->since()
                    ->placeholder('Never')
                    ->color(fn (RequirementRule $record): string => $record->last_verified_at?->gt(now()->subYear()) ? 'gray' : 'warning')
                    ->description(fn (RequirementRule $record): ?string => $record->verifiedBy?->name)
                    ->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('scope')->options(RequirementRule::SCOPES),
                SelectFilter::make('country_code')->label('Country')->options(fn (): array => DocumentFormatOptions::countries())->searchable(),
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('needs_verification')
                    ->label('Not verified in the last 12 months')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNull('last_verified_at')->orWhere('last_verified_at', '<', now()->subYear()))),
            ])
            ->recordActions([
                EditAction::make(),
                RequirementRuleAudit::verifyAction(),
            ])
            ->emptyStateHeading('No requirement rules yet')
            ->emptyStateDescription('Add country conventions, application-platform limits (e.g. UCAS) or institution and programme requirements, each with its official source.');
    }

    private static function target(RequirementRule $rule): ?string
    {
        $parts = array_filter([
            $rule->programme_name,
            $rule->institution_name ?: $rule->institution_domain,
            $rule->application_platform,
            $rule->country_code ? (DocumentFormatOptions::countries()[$rule->country_code] ?? $rule->country_code) : null,
            $rule->degree_level ? (DocumentFormatOptions::DEGREE_LEVELS[$rule->degree_level] ?? $rule->degree_level) : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    private static function limits(RequirementRule $rule): ?string
    {
        $parts = [];
        if ($rule->max_words) {
            $parts[] = ($rule->min_words ? number_format($rule->min_words).'–' : '≤ ').number_format($rule->max_words).' words';
        }
        if ($rule->max_characters) {
            $parts[] = ($rule->min_characters ? number_format($rule->min_characters).'–' : '≤ ').number_format($rule->max_characters).' chars';
        }
        if ($rule->max_pages) {
            $parts[] = '≤ '.(int) $rule->max_pages.' page'.((int) $rule->max_pages === 1 ? '' : 's');
        }
        if ($sections = count((array) $rule->required_sections)) {
            $parts[] = $sections.' section'.($sections === 1 ? '' : 's');
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
