<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Filament\Support\Catalogue\IconOptions;
use App\Filament\Support\Catalogue\MarkdownPreview;
use App\Filament\Support\Catalogue\MediaUpload;
use App\Filament\Support\Catalogue\SeoFields;
use App\Models\Article;
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

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Group::make([
                        Section::make('Article')
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, string $operation): void {
                                        if ($operation === 'create' && ($get('slug') ?? '') === Str::slug((string) $old)) {
                                            $set('slug', Str::slug((string) $state));
                                        }
                                    })
                                    ->columnSpanFull(),
                                TextInput::make('slug')
                                    ->label('URL slug')
                                    ->required()
                                    ->maxLength(160)
                                    ->prefix('/resources/')
                                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                    ->unique(ignoreRecord: true)
                                    ->validationMessages(['regex' => 'Use lowercase letters, numbers and single hyphens.']),
                                Select::make('article_category_id')
                                    ->label('Category')
                                    ->relationship('category', 'name', fn ($query) => $query->orderBy('display_order')->orderBy('name'))
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        TextInput::make('name')->required()->maxLength(120)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                                        TextInput::make('slug')->required()->maxLength(120)->unique('article_categories', 'slug'),
                                        Select::make('icon')->options(IconOptions::SERVICE_ICONS)->default('book')->required(),
                                    ]),
                                Textarea::make('excerpt')
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->helperText('Shown on article cards and as a fallback meta description.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Section::make('Body')
                            ->schema([
                                MarkdownEditor::make('body')
                                    ->hiddenLabel()
                                    ->required()
                                    ->minHeight('28rem')
                                    ->maxLength(200000)
                                    ->fileAttachmentsDisk(fn (): string => MediaUpload::diskName())
                                    ->fileAttachmentsDirectory('articles/inline')
                                    ->fileAttachmentsAcceptedFileTypes(MediaUpload::ACCEPTED_TYPES)
                                    ->fileAttachmentsMaxSize(MediaUpload::MAX_KB)
                                    ->helperText('Markdown. Reading time is calculated automatically when you save.')
                                    ->afterLabel(Action::make('previewBody')
                                        ->label('Preview')
                                        ->icon(Heroicon::OutlinedEye)
                                        ->link()
                                        ->modalHeading(fn (Get $get): string => (string) ($get('title') ?: 'Preview'))
                                        ->modalWidth(Width::FiveExtraLarge)
                                        ->modalSubmitAction(false)
                                        ->modalCancelActionLabel('Close')
                                        ->modalContent(fn (Get $get) => MarkdownPreview::html($get('body')))),
                            ]),
                    ])->columnSpan(2),
                    Group::make([
                        Section::make('Publishing')
                            ->schema([
                                Toggle::make('is_published')
                                    ->label('Published')
                                    ->live(),
                                DateTimePicker::make('published_at')
                                    ->label('Publish date')
                                    ->seconds(false)
                                    ->helperText('A future date schedules the article. Leave blank to publish immediately.'),
                                Toggle::make('is_featured')
                                    ->label('Featured')
                                    ->helperText('Featured guides appear first on the home and resources pages.'),
                                TextInput::make('display_order')
                                    ->label('Display order')
                                    ->integer()
                                    ->default(0)
                                    ->required()
                                    ->helperText('Lower numbers appear first among featured guides.'),
                                TextInput::make('author_name')
                                    ->label('Author')
                                    ->maxLength(120)
                                    ->placeholder('e.g. Statementra Editorial Team'),
                                TextInput::make('reading_minutes')
                                    ->label('Reading time')
                                    ->suffix('min')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visibleOn('edit'),
                            ]),
                        Section::make('Cover image')
                            ->schema([
                                MediaUpload::to('cover_image_path', 'articles')
                                    ->hiddenLabel()
                                    ->live(),
                                TextInput::make('cover_image_alt')
                                    ->label('Image description (alt text)')
                                    ->maxLength(255)
                                    ->required(fn (Get $get): bool => filled($get('cover_image_path')))
                                    ->validationMessages(['required' => 'Describe the cover image for screen readers and search engines.'])
                                    ->helperText('Required when there is a cover image.'),
                            ]),
                        Section::make('Search & sharing')
                            ->schema(SeoFields::make(null))
                            ->collapsible(),
                    ])->columnSpan(1),
                ]),
        ]);
    }

    public static function isLive(Article $article): bool
    {
        return $article->is_published && (! $article->published_at || $article->published_at->isPast());
    }
}
