<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Support\Catalogue\MarkdownPreview;
use App\Filament\Support\Catalogue\MediaUpload;
use App\Filament\Support\Catalogue\PageSections;
use App\Filament\Support\Catalogue\SeoFields;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Group::make([
                        self::pageSection(),
                        self::bodySection(),
                        Group::make(fn (Get $get, ?Page $record): array => PageSections::components(
                            $record?->exists ? $record->getOriginal('slug') : $get('slug'),
                            $get('kind'),
                        ))->key('page-sections'),
                    ])->columnSpan(2),
                    Group::make([
                        self::publishingSection(),
                        Section::make('Search & sharing')->schema(SeoFields::make('og_image_path', 'pages/social')),
                    ])->columnSpan(1),
                ]),
        ]);
    }

    private static function pageSection(): Section
    {
        return Section::make('Page')
            ->description(fn (?Page $record): ?string => $record && PageSections::isSystemPage($record->getOriginal('slug'))
                ? 'This is a system page: its address and type are fixed and it cannot be deleted.'
                : null)
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, string $operation): void {
                        if ($operation === 'create' && ($get('slug') ?? '') === Str::slug((string) $old)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label('URL slug')
                    ->required()
                    ->maxLength(120)
                    ->prefix('/')
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['regex' => 'Use lowercase letters, numbers and single hyphens.'])
                    ->disabled(fn (?Page $record): bool => self::isSystem($record))
                    ->live(onBlur: true),
                Select::make('kind')
                    ->label('Page type')
                    ->options(fn (?Page $record): array => $record?->getOriginal('kind') === 'home'
                        ? PageSections::KINDS
                        : array_diff_key(PageSections::KINDS, ['home' => true]))
                    ->default('standard')
                    ->required()
                    ->live()
                    ->native(false)
                    ->disabled(fn (?Page $record): bool => self::isSystem($record))
                    ->helperText('Landing pages are built from editable sections; standard and legal pages use a Markdown body.'),
                Textarea::make('excerpt')
                    ->label('Summary')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Optional short introduction, also used as a fallback meta description.')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function bodySection(): Section
    {
        return Section::make('Content')
            ->schema([
                MarkdownEditor::make('body')
                    ->hiddenLabel()
                    ->toolbarButtons([
                        ['bold', 'italic', 'strike', 'link'],
                        ['heading'],
                        ['blockquote', 'bulletList', 'orderedList', 'table'],
                        ['undo', 'redo'],
                    ])
                    ->minHeight('24rem')
                    ->maxLength(200000)
                    ->helperText('Markdown. Placeholders replaced on the site: {{date}} (date of the last change to this page), {{support_email}}, {{contact_email}}, {{site_name}}, {{retention_days}}.')
                    ->afterLabel(Action::make('previewBody')
                        ->label('Preview')
                        ->icon(Heroicon::OutlinedEye)
                        ->link()
                        ->modalHeading('Preview')
                        ->modalWidth(Width::FiveExtraLarge)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (Get $get) => MarkdownPreview::html($get('body')))),
            ])
            ->visible(fn (Get $get): bool => ! PageSections::usesSections($get('kind')));
    }

    private static function publishingSection(): Section
    {
        return Section::make('Publishing')
            ->schema([
                Toggle::make('is_published')
                    ->label('Published')
                    ->default(true)
                    ->disabled(fn (?Page $record): bool => $record?->getOriginal('slug') === 'home')
                    ->helperText(fn (?Page $record): string => $record?->getOriginal('slug') === 'home'
                        ? 'The home page is always published.'
                        : 'Unpublished pages are hidden from the site.'),
                DateTimePicker::make('published_at')
                    ->label('Published on')
                    ->seconds(false)
                    ->helperText('Set automatically when the page is first published.'),
                MediaUpload::to('hero_image_path', 'pages/hero')
                    ->label('Header image')
                    ->visible(fn (Get $get): bool => ! PageSections::usesSections($get('kind'))),
            ]);
    }

    private static function isSystem(?Page $record): bool
    {
        return $record !== null && $record->exists && PageSections::isSystemPage($record->getOriginal('slug'));
    }
}
