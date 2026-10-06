<?php

namespace App\Filament\Resources\RequirementRules\Schemas;

use App\Domain\Documents\ResolvedRequirements;
use App\Enums\DocumentKind;
use App\Filament\Support\Catalogue\DocumentFormatOptions;
use App\Filament\Support\Catalogue\FormRules;
use App\Filament\Support\Catalogue\IconOptions;
use App\Models\RequirementRule;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RequirementRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rule')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. UCAS personal statement (2026 entry)')
                        ->columnSpanFull(),
                    Select::make('scope')
                        ->label('Applies at the level of')
                        ->options(RequirementRule::SCOPES)
                        ->required()
                        ->live()
                        ->native(false)
                        ->helperText('More specific rules win: programme › institution / scholarship › platform › country.'),
                    TextInput::make('priority')
                        ->integer()
                        ->default(0)
                        ->required()
                        ->helperText('Breaks ties between rules of the same level (higher wins).'),
                    Toggle::make('is_active')->label('Active')->default(true),
                ])
                ->columns(2),

            Section::make('Applies to')
                ->schema([
                    Select::make('country_code')
                        ->label('Country')
                        ->options(fn (): array => DocumentFormatOptions::countries())
                        ->searchable()
                        ->required(fn (Get $get): bool => $get('scope') === 'country'),
                    TextInput::make('application_platform')
                        ->label('Application platform')
                        ->maxLength(60)
                        ->datalist(DocumentFormatOptions::PLATFORMS)
                        ->required(fn (Get $get): bool => $get('scope') === 'platform'),
                    TextInput::make('institution_name')
                        ->label(fn (Get $get): string => $get('scope') === 'scholarship' ? 'Scholarship / organisation' : 'Institution')
                        ->maxLength(255)
                        ->required(fn (Get $get): bool => in_array($get('scope'), ['institution', 'scholarship'], true) && blank($get('institution_domain')))
                        ->helperText(fn (Get $get): ?string => $get('scope') === 'programme' ? 'Optional: leave blank to apply to this programme name at any institution.' : null),
                    TextInput::make('institution_domain')
                        ->label('Official website domain')
                        ->maxLength(255)
                        ->regex('/^(https?:\/\/)?(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+\/?$/i')
                        ->live(onBlur: true)
                        ->placeholder('e.g. ed.ac.uk')
                        ->helperText('Used to recognise the institution’s official pages during research.'),
                    TextInput::make('programme_name')
                        ->label('Programme')
                        ->maxLength(255)
                        ->required(fn (Get $get): bool => $get('scope') === 'programme'),
                    Select::make('degree_level')
                        ->label('Level of study')
                        ->options(fn (?RequirementRule $record): array => IconOptions::withCurrent(DocumentFormatOptions::DEGREE_LEVELS, $record?->degree_level))
                        ->placeholder('Any level')
                        ->native(false),
                    CheckboxList::make('document_kinds')
                        ->label('Document types')
                        ->options(DocumentKind::class)
                        ->columns(3)
                        ->helperText('Leave all unticked to apply to every document type.')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Length')
                ->schema([
                    TextInput::make('min_words')->label('Minimum words')->integer()->minValue(1)->maxValue(100000),
                    TextInput::make('max_words')->label('Maximum words')->integer()->minValue(1)->maxValue(100000)
                        ->rules([FormRules::notLessThan('min_words', 'The maximum cannot be lower than the minimum.')]),
                    TextInput::make('max_pages')->label('Maximum pages')->integer()->minValue(1)->maxValue(100),
                    TextInput::make('min_characters')->label('Minimum characters')->integer()->minValue(1)->maxValue(1000000),
                    TextInput::make('max_characters')->label('Maximum characters (incl. spaces)')->integer()->minValue(1)->maxValue(1000000)
                        ->rules([FormRules::notLessThan('min_characters', 'The maximum cannot be lower than the minimum.')]),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Language & formatting')
                ->schema([
                    Select::make('language_variant')
                        ->label('Language variant')
                        ->options(ResolvedRequirements::LANGUAGE_VARIANTS)
                        ->placeholder('Not specified')
                        ->native(false),
                    Select::make('date_format')
                        ->label('Date format')
                        ->options(fn (?RequirementRule $record): array => IconOptions::withCurrent(DocumentFormatOptions::DATE_FORMATS, $record?->date_format))
                        ->placeholder('Not specified')
                        ->native(false),
                    Select::make('page_size')
                        ->options(DocumentFormatOptions::PAGE_SIZES)
                        ->placeholder('Not specified')
                        ->native(false),
                    Select::make('font_family')
                        ->label('Font')
                        ->options(fn (?RequirementRule $record): array => DocumentFormatOptions::fonts($record?->font_family))
                        ->placeholder('Not specified')
                        ->native(false),
                    TextInput::make('font_size')->label('Font size')->numeric()->minValue(6)->maxValue(24)->step(0.5)->suffix('pt'),
                    TextInput::make('line_spacing')->label('Line spacing')->numeric()->minValue(1)->maxValue(3)->step(0.05),
                    TextInput::make('margins_mm')->label('Margins')->numeric()->minValue(5)->maxValue(60)->step(0.5)->suffix('mm'),
                    CheckboxList::make('file_types')
                        ->label('Accepted file types')
                        ->options(DocumentFormatOptions::FILE_TYPES)
                        ->columns(2),
                    TextInput::make('naming_convention')
                        ->label('File naming convention')
                        ->maxLength(255)
                        ->placeholder('e.g. Surname_Firstname_PersonalStatement'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Structure & content')
                ->schema([
                    Repeater::make('required_sections')
                        ->label('Required sections / questions')
                        ->schema([
                            TextInput::make('heading')->required()->maxLength(255)->columnSpanFull(),
                            Textarea::make('question')->label('Question or instructions')->rows(2)->maxLength(2000)->columnSpanFull(),
                            TextInput::make('min_characters')->label('Min characters')->integer()->minValue(1),
                            TextInput::make('max_characters')->label('Max characters')->integer()->minValue(1)
                                ->rules([FormRules::notLessThan('min_characters', 'The maximum cannot be lower than the minimum.')]),
                            TextInput::make('min_words')->label('Min words')->integer()->minValue(1),
                            TextInput::make('max_words')->label('Max words')->integer()->minValue(1)
                                ->rules([FormRules::notLessThan('min_words', 'The maximum cannot be lower than the minimum.')]),
                        ])
                        ->columns(4)
                        ->itemLabel(fn (array $state): ?string => $state['heading'] ?? null)
                        ->addActionLabel('Add section')
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->defaultItems(0)
                        ->helperText('For applications with several questions (e.g. UCAS-style or scholarship prompts). Sections are written in this order.'),
                    TagsInput::make('prohibited_content')
                        ->label('Must not include')
                        ->placeholder('Add an item and press Enter')
                        ->nestedRecursiveRules(['string', 'max:255'])
                        ->helperText('e.g. “Quotations”, “Name of the applicant’s school”.'),
                    Textarea::make('special_instructions')
                        ->rows(3)
                        ->maxLength(5000)
                        ->helperText('Anything else the writer must follow, quoted from the official source where possible.'),
                    TextInput::make('submission_method')
                        ->maxLength(255)
                        ->placeholder('e.g. Pasted into the online form (plain text)'),
                ])
                ->collapsible(),

            Section::make('Source & verification')
                ->description('Every rule should point to the official page it comes from. Re-check rules at least once per admissions cycle.')
                ->schema([
                    TextInput::make('source_name')->label('Source')->maxLength(255)->placeholder('e.g. UCAS — How to write a personal statement'),
                    TextInput::make('source_url')->label('Source URL')->url()->maxLength(1000),
                    Text::make(fn (?RequirementRule $record): string => $record?->last_verified_at
                        ? 'Last verified '.$record->last_verified_at->format('j M Y').($record->verifiedBy ? ' by '.$record->verifiedBy->name : '').'.'
                        : 'Not verified yet. Use “Mark verified now” after checking the source.')
                        ->color(fn (?RequirementRule $record): string => $record?->last_verified_at?->gt(now()->subYear()) ? 'gray' : 'warning')
                        ->columnSpanFull(),
                    Textarea::make('notes')->label('Internal notes')->rows(2)->maxLength(5000)->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
