<?php

namespace App\Filament\Resources\DocumentTemplates\Schemas;

use App\Enums\DocumentKind;
use App\Filament\Support\Catalogue\DocumentFormatOptions;
use App\Filament\Support\Catalogue\IconOptions;
use App\Models\DocumentTemplate;
use App\Models\Service;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class DocumentTemplateForm
{
    private const PLACEHOLDERS = '{title}, {document_type}, {applicant_name}, {institution}, {programme}';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, string $operation): void {
                            if ($operation === 'create' && ($get('slug') ?? '') === Str::slug((string) $old)) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(120)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(ignoreRecord: true),
                    Textarea::make('description')->rows(2)->maxLength(500)->columnSpanFull(),
                    Toggle::make('is_active')->label('Active')->default(true),
                    Toggle::make('is_default')
                        ->label('Default template')
                        ->disabled(fn (?DocumentTemplate $record): bool => (bool) $record?->getOriginal('is_default'))
                        ->helperText(fn (?DocumentTemplate $record): string => $record?->getOriginal('is_default')
                            ? 'This is the default. Make another template the default to change it.'
                            : 'Used when no other template matches. Replaces the current default.'),
                    TextInput::make('priority')
                        ->integer()
                        ->default(0)
                        ->required()
                        ->helperText('Breaks ties between equally specific matches (higher wins).'),
                ])
                ->columns(3),

            Tabs::make('Formatting')
                ->persistTabInQueryString('format-tab')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('When to use it')
                        ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                        ->schema([
                            Section::make()
                                ->description('A template is chosen automatically when every criterion you fill in matches the order; the most specific match wins (institution › platform › service › document type › country / level). Leave everything blank for a general-purpose template. Services can also pick a template directly.')
                                ->schema([
                                    Select::make('match_rules.services')
                                        ->label('Services')
                                        ->multiple()
                                        ->options(fn (): array => Service::withTrashed()->ordered()->pluck('name', 'id')->all())
                                        ->searchable(),
                                    Select::make('match_rules.countries')
                                        ->label('Destination countries')
                                        ->multiple()
                                        ->options(fn (): array => DocumentFormatOptions::countries())
                                        ->searchable(),
                                    CheckboxList::make('match_rules.document_kinds')
                                        ->label('Document types')
                                        ->options(DocumentKind::class)
                                        ->columns(2),
                                    CheckboxList::make('match_rules.degree_levels')
                                        ->label('Levels of study')
                                        ->options(DocumentFormatOptions::DEGREE_LEVELS)
                                        ->columns(2),
                                    TagsInput::make('match_rules.institutions')
                                        ->label('Institutions')
                                        ->placeholder('Add an institution and press Enter')
                                        ->helperText('Exact institution names as customers enter them, e.g. “University of Oxford”.'),
                                    TagsInput::make('match_rules.platforms')
                                        ->label('Application platforms')
                                        ->suggestions(DocumentFormatOptions::PLATFORMS)
                                        ->placeholder('e.g. UCAS'),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make('Page & text')
                        ->icon(Heroicon::OutlinedDocument)
                        ->schema([
                            Section::make('Page')
                                ->schema([
                                    Select::make('page_size')->label('Paper size')->options(DocumentFormatOptions::PAGE_SIZES)->default('A4')->required()->native(false),
                                    self::mm('margin_top_mm', 'Top margin'),
                                    self::mm('margin_right_mm', 'Right margin'),
                                    self::mm('margin_bottom_mm', 'Bottom margin'),
                                    self::mm('margin_left_mm', 'Left margin'),
                                ])
                                ->columns(5),
                            Section::make('Body text')
                                ->schema([
                                    Select::make('font_family')
                                        ->label('Font')
                                        ->options(fn (?DocumentTemplate $record): array => DocumentFormatOptions::fonts($record?->font_family))
                                        ->default('Times New Roman')
                                        ->required()
                                        ->native(false)
                                        ->helperText('Embedded in the PDF with a metric-compatible font, so pages break the same way as in Word.'),
                                    TextInput::make('font_size')->label('Size')->numeric()->minValue(8)->maxValue(20)->step(0.5)->default(12)->suffix('pt')->required(),
                                    TextInput::make('line_spacing')->label('Line spacing')->numeric()->minValue(0.8)->maxValue(3)->step(0.05)->default(1.5)->required(),
                                    TextInput::make('paragraph_spacing_pt')->label('Space after paragraphs')->numeric()->minValue(0)->maxValue(36)->step(0.5)->default(8)->suffix('pt')->required(),
                                    TextInput::make('first_line_indent_mm')->label('First-line indent')->numeric()->minValue(0)->maxValue(30)->step(0.5)->default(0)->suffix('mm')->required(),
                                    Select::make('text_align')->label('Alignment')->options(DocumentFormatOptions::TEXT_ALIGN)->default('left')->required()->native(false),
                                ])
                                ->columns(3),
                        ]),
                    Tab::make('Title & headings')
                        ->icon(Heroicon::OutlinedH1)
                        ->schema([
                            Section::make()
                                ->schema([
                                    Toggle::make('show_title')->label('Show a title')->default(true)->live()->columnSpanFull(),
                                    TextInput::make('title_template')
                                        ->label('Title')
                                        ->maxLength(255)
                                        ->placeholder('{document_type}')
                                        ->helperText('Blank = the document’s own title. Placeholders: '.self::PLACEHOLDERS.'.')
                                        ->visible(fn (Get $get): bool => (bool) $get('show_title'))
                                        ->columnSpanFull(),
                                    TextInput::make('title_font_size')->label('Title size')->numeric()->minValue(8)->maxValue(32)->step(0.5)->default(14)->suffix('pt')->required(),
                                    Select::make('title_align')->label('Title alignment')->options(DocumentFormatOptions::TITLE_ALIGN)->default('center')->required()->native(false),
                                    TextInput::make('heading_font_size')->label('Section heading size')->numeric()->minValue(8)->maxValue(24)->step(0.5)->default(12)->suffix('pt')->required(),
                                    Select::make('applicant_name_position')
                                        ->label('Applicant name')
                                        ->options(fn (?DocumentTemplate $record): array => IconOptions::withCurrent(DocumentFormatOptions::NAME_POSITIONS, $record?->applicant_name_position))
                                        ->default('below_title')
                                        ->required()
                                        ->native(false),
                                    Select::make('date_format')
                                        ->label('Date format')
                                        ->options(fn (?DocumentTemplate $record): array => IconOptions::withCurrent(DocumentFormatOptions::DATE_FORMATS, $record?->date_format))
                                        ->placeholder('Follow the language variant')
                                        ->native(false),
                                    Select::make('citation_style')
                                        ->label('Citations')
                                        ->options(fn (?DocumentTemplate $record): array => IconOptions::withCurrent(DocumentFormatOptions::CITATION_STYLES, $record?->citation_style))
                                        ->default('none')
                                        ->required()
                                        ->native(false)
                                        ->helperText('Most application documents use none.'),
                                ])
                                ->columns(3),
                        ]),
                    Tab::make('Header, footer & files')
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->schema([
                            Section::make('Header & footer')
                                ->schema([
                                    TextInput::make('header_text')->label('Header text')->maxLength(255)->helperText('Placeholders: '.self::PLACEHOLDERS.'.'),
                                    TextInput::make('footer_text')->label('Footer text')->maxLength(255),
                                    Select::make('page_numbers')
                                        ->label('Page numbers')
                                        ->options(fn (?DocumentTemplate $record): array => IconOptions::withCurrent(DocumentFormatOptions::PAGE_NUMBERS, $record?->page_numbers))
                                        ->default('bottom_center')
                                        ->required()
                                        ->live()
                                        ->native(false),
                                    TextInput::make('page_number_format')
                                        ->label('Page number format')
                                        ->maxLength(60)
                                        ->default('{PAGE}')
                                        ->required()
                                        ->helperText('{PAGE} and {NUMPAGES}, e.g. “Page {PAGE} of {NUMPAGES}”.')
                                        ->visible(fn (Get $get): bool => $get('page_numbers') !== 'none'),
                                    Toggle::make('include_branding')
                                        ->label('Add a small Statementra mark in the footer')
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                            Section::make('File names')
                                ->schema([
                                    TextInput::make('filename_pattern')
                                        ->label('File name')
                                        ->maxLength(255)
                                        ->default('{applicant_name}_{document_type}')
                                        ->required()
                                        ->helperText('Without extension. Placeholders: {applicant_name}, {document_type}, {institution}, {programme}. Unsafe characters are removed automatically.'),
                                    Toggle::make('include_name_in_filename')
                                        ->label('Include the applicant’s name in file names')
                                        ->default(true)
                                        ->inline(false),
                                ])
                                ->columns(2),
                        ]),
                ]),
        ]);
    }

    private static function mm(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(5)
            ->maxValue(60)
            ->step(0.5)
            ->default(25.4)
            ->suffix('mm')
            ->required();
    }
}
