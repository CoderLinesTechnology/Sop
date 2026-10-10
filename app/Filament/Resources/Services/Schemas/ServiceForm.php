<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\DocumentKind;
use App\Filament\Support\Catalogue\FormRules;
use App\Filament\Support\Catalogue\IconOptions;
use App\Filament\Support\Catalogue\MediaUpload;
use App\Filament\Support\Catalogue\MoneyInput;
use App\Filament\Support\Catalogue\SeoFields;
use App\Filament\Support\Catalogue\ServiceAccess;
use App\Filament\Support\Catalogue\ServiceFieldSchema;
use App\Filament\Support\Catalogue\ServiceFormChecks;
use App\Models\AiWorkflow;
use App\Models\DocumentTemplate;
use App\Models\Service;
use App\Support\Money;
use App\Support\Settings;
use Closure;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Service')
                ->persistTabInQueryString('tab')
                ->columnSpanFull()
                ->tabs([
                    self::detailsTab(),
                    self::pricingTab(),
                    self::orderFormTab(),
                    self::aiTab(),
                    self::revisionsTab(),
                    self::seoTab(),
                    self::faqsTab(),
                ]),
        ]);
    }

    private static function contentLocked(): Closure
    {
        return fn (): bool => ! ServiceAccess::canEditContent();
    }

    private static function pricingLocked(): Closure
    {
        return fn (): bool => ! ServiceAccess::canEditPricing();
    }

    private static function detailsTab(): Tab
    {
        return Tab::make('Details')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                Callout::make('Read-only for your role')
                    ->description('You can view this service. Only prices are editable with your permissions (see the Pricing tab).')
                    ->info()
                    ->visible(fn (): bool => ! ServiceAccess::canEditContent()),

                Section::make('Service')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(160)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, string $operation): void {
                                if ($operation === 'create' && ($get('slug') ?? '') === Str::slug((string) $old)) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            })
                            ->placeholder('e.g. CV Optimization'),
                        TextInput::make('slug')
                            ->label('URL slug')
                            ->required()
                            ->maxLength(160)
                            ->prefix('/services/')
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->scopedUnique(ignoreRecord: true)
                            ->rules([self::archivedSlugRule()])
                            ->validationMessages([
                                'regex' => 'Use lowercase letters, numbers and single hyphens (e.g. cv-optimization).',
                                'unique' => 'Another active service already uses this slug.',
                            ])
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Changing the slug changes the page address and breaks existing links to it.'
                                : 'Generated from the name. Used in the page address.'),
                        Select::make('document_kind')
                            ->label('Document type')
                            ->options(DocumentKind::class)
                            ->required()
                            ->default(DocumentKind::PersonalStatement->value)
                            ->native(false)
                            ->helperText('Selects the built-in writing approach and default formatting. Use “Custom document” for anything else and describe it in AI & formatting → Writing guidance.'),
                        Toggle::make('is_active')
                            ->label('Active (visible and orderable on the site)')
                            ->default(false)
                            ->inline(false)
                            ->helperText('New services start inactive so you can review them first.'),
                        Textarea::make('short_description')
                            ->label('Short description')
                            ->required()
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('Shown on service cards. One or two sentences.')
                            ->columnSpanFull(),
                        MarkdownEditor::make('description')
                            ->label('Full description')
                            ->toolbarButtons([
                                ['bold', 'italic', 'link'],
                                ['heading', 'bulletList', 'orderedList'],
                                ['undo', 'redo'],
                            ])
                            ->maxLength(20000)
                            ->helperText('Shown on the service page. Markdown is supported.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Card & display')
                    ->description('How the service appears in service lists and on the home page.')
                    ->schema([
                        Repeater::make('card_features')
                            ->label('Card features')
                            ->simple(TextInput::make('feature')->required()->maxLength(80))
                            ->defaultItems(0)
                            ->maxItems(6)
                            ->addActionLabel('Add feature')
                            ->reorderableWithButtons()
                            ->helperText('Short selling points listed on the service card (up to 6).')
                            ->columnSpanFull(),
                        TextInput::make('badge')
                            ->maxLength(40)
                            ->datalist(['Most Popular', 'Best Value', 'New', 'Fastest'])
                            ->placeholder('e.g. Most Popular')
                            ->helperText('Optional highlight shown on the card.'),
                        Toggle::make('is_featured')
                            ->label('Featured')
                            ->inline(false)
                            ->helperText('Featured services are emphasised on the home page.'),
                        Select::make('icon')
                            ->options(fn (?Service $record): array => IconOptions::withCurrent(IconOptions::SERVICE_ICONS, $record?->icon))
                            ->default('document')
                            ->required()
                            ->native(false),
                        Select::make('icon_color')
                            ->label('Icon colour')
                            ->options(fn (?Service $record): array => IconOptions::withCurrent(IconOptions::SERVICE_COLORS, $record?->icon_color))
                            ->default('green')
                            ->required()
                            ->native(false),
                        MediaUpload::to('image_path', 'services')
                            ->label('Image')
                            ->helperText('Optional illustration for the service page. JPEG, PNG or WebP, up to 5 MB.'),
                        TextInput::make('display_order')
                            ->label('Display order')
                            ->integer()
                            ->default(fn (): int => (int) Service::withTrashed()->max('display_order') + 1)
                            ->required()
                            ->helperText('Lower numbers appear first. You can also drag services in the list.'),
                    ])
                    ->columns(2),
            ])
            ->disabled(self::contentLocked());
    }

    private static function pricingTab(): Tab
    {
        return Tab::make('Pricing & delivery')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->schema([
                Section::make('Price')
                    ->description('Prices are entered in normal currency units (e.g. 89.50) and charged exactly as shown; promotions and coupons are applied on top at checkout.')
                    ->schema([
                        Callout::make('Prices are managed by Finance')
                            ->description('You need the “pricing.manage” permission to change prices.')
                            ->warning()
                            ->visible(fn (): bool => ! ServiceAccess::canEditPricing())
                            ->columnSpanFull(),
                        Select::make('currency')
                            ->options(Money::currencyOptions())
                            ->default(fn (): string => Settings::currency())
                            ->required()
                            ->live()
                            ->native(false)
                            ->disabled(self::pricingLocked()),
                        MoneyInput::make('price')
                            ->label('Price')
                            ->required()
                            ->minValue(0.5)
                            ->live(onBlur: true)
                            ->disabled(self::pricingLocked()),
                        MoneyInput::make('compare_at_price')
                            ->label('Original price (crossed out)')
                            ->rules([FormRules::greaterThan('price', 'The original price must be higher than the price.')])
                            ->live(onBlur: true)
                            ->helperText('Optional. Shown crossed out next to the price.')
                            ->disabled(self::pricingLocked()),
                        TextInput::make('promo_label')
                            ->label('Promo label')
                            ->maxLength(80)
                            ->placeholder('e.g. Limited-time offer')
                            ->helperText('Optional short text shown with the discounted price.')
                            ->disabled(self::pricingLocked()),
                        Text::make(fn (Get $get): HtmlString => self::pricePreview($get))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Delivery estimate')
                    ->description(fn (): string => 'Leave blank to use the global estimate from Settings → Orders ('
                        .Settings::formatMinutesRange((int) Settings::get('orders.delivery_min_minutes', 10), (int) Settings::get('orders.delivery_max_minutes', 15)).').')
                    ->schema([
                        TextInput::make('delivery_min_minutes')
                            ->label('From (minutes)')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10080)
                            ->placeholder((string) Settings::get('orders.delivery_min_minutes', 10)),
                        TextInput::make('delivery_max_minutes')
                            ->label('To (minutes)')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10080)
                            ->rules([FormRules::notLessThan('delivery_min_minutes', 'The upper estimate cannot be lower than the lower estimate.')])
                            ->placeholder((string) Settings::get('orders.delivery_max_minutes', 15)),
                    ])
                    ->columns(2)
                    ->disabled(self::contentLocked()),
            ]);
    }

    private static function orderFormTab(): Tab
    {
        return Tab::make('Order form')
            ->icon(Heroicon::OutlinedQueueList)
            ->badge(fn (Get $get): ?string => ($count = count((array) $get('fields'))) > 0 ? (string) $count : null)
            ->schema([
                Text::make('The questions and upload slots customers fill in when ordering this service, in display order. Map questions to order details (name, delivery email, institution…) so the rest of the system can use the answers.')
                    ->color('gray'),
                Callout::make('Check the order form')
                    ->description(fn (Get $get): HtmlString => self::warningList(ServiceFormChecks::warnings((array) $get('fields'))))
                    ->warning()
                    ->visible(fn (Get $get): bool => ServiceFormChecks::warnings((array) $get('fields')) !== []),
                ServiceFieldSchema::repeater()
                    ->disabled(self::contentLocked()),
            ]);
    }

    private static function aiTab(): Tab
    {
        return Tab::make('AI & formatting')
            ->icon(Heroicon::OutlinedSparkles)
            ->schema([
                Section::make('Generation')
                    ->schema([
                        Select::make('ai_workflow_id')
                            ->label('AI workflow')
                            ->options(fn (): array => AiWorkflow::query()->orderByDesc('is_default')->orderBy('name')->get()
                                ->mapWithKeys(fn (AiWorkflow $workflow): array => [$workflow->id => $workflow->name
                                    .($workflow->is_default ? ' (default)' : '')
                                    .($workflow->is_active ? '' : ' — inactive')])
                                ->all())
                            ->placeholder('Use the default workflow')
                            ->native(false)
                            ->helperText('Leave blank to use the default workflow (AI → Workflows).'),
                        Select::make('document_template_id')
                            ->label('Document template')
                            ->options(fn (): array => DocumentTemplate::query()->orderByDesc('is_default')->orderBy('name')->get()
                                ->mapWithKeys(fn (DocumentTemplate $template): array => [$template->id => $template->name
                                    .($template->is_active ? '' : ' — inactive')])
                                ->all())
                            ->placeholder('Choose automatically')
                            ->native(false)
                            ->helperText('Leave blank to pick the best template by country, institution and document type.'),
                        TextInput::make('default_word_limit')
                            ->label('Default word limit')
                            ->integer()
                            ->minValue(50)
                            ->maxValue(20000)
                            ->suffix('words')
                            ->helperText('Used only when no official or customer-stated limit is found.'),
                    ])
                    ->columns(2),
                Section::make('Instructions')
                    ->schema([
                        Textarea::make('writing_guidance')
                            ->label('Writing guidance (sent to the AI)')
                            ->rows(5)
                            ->maxLength(5000)
                            ->helperText('Trusted instructions for every document of this service: structure, emphasis, tone. Customer answers can never override them.'),
                        Textarea::make('order_instructions')
                            ->label('Order instructions (shown to customers)')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Optional guidance displayed at the top of the order form.'),
                    ]),
            ])
            ->disabled(self::contentLocked());
    }

    private static function revisionsTab(): Tab
    {
        return Tab::make('Revisions')
            ->icon(Heroicon::OutlinedArrowPath)
            ->schema([
                Section::make('Revision policy')
                    ->description('Applies to new orders; existing orders keep the policy they were bought with.')
                    ->schema([
                        TextInput::make('revisions_included')
                            ->label('Revisions included')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10)
                            ->default(1)
                            ->required()
                            ->disabled(self::contentLocked()),
                        TextInput::make('revision_window_days')
                            ->label('Revision window')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(14)
                            ->suffix('days after delivery')
                            ->required()
                            ->disabled(self::contentLocked()),
                        MoneyInput::make('revision_fee')
                            ->label('Fee for extra revisions')
                            ->helperText('Charged for each revision beyond those included. Leave blank or 0 to not sell extra revisions. Requires pricing permission.')
                            ->disabled(self::pricingLocked()),
                        Select::make('revision_mode')
                            ->label('How revisions are produced')
                            ->options([
                                'ai' => 'Automatically by the AI pipeline',
                                'manual' => 'Manually by the team',
                            ])
                            ->default('ai')
                            ->required()
                            ->native(false)
                            ->disabled(self::contentLocked()),
                    ])
                    ->columns(2),
            ]);
    }

    private static function seoTab(): Tab
    {
        return Tab::make('SEO')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->schema([
                Section::make('Search & sharing')
                    ->schema(SeoFields::make('og_image_path', 'services/social')),
            ])
            ->disabled(self::contentLocked());
    }

    private static function faqsTab(): Tab
    {
        return Tab::make('FAQs')
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->badge(fn (Get $get): ?string => ($count = count((array) $get('faqs'))) > 0 ? (string) $count : null)
            ->schema([
                Text::make('Questions shown on this service’s page. General FAQs are managed under Content → FAQs.')
                    ->color('gray'),
                Repeater::make('faqs')
                    ->hiddenLabel()
                    ->relationship('faqs')
                    ->orderColumn('display_order')
                    ->schema([
                        TextInput::make('question')->required()->maxLength(500)->live(onBlur: true)->columnSpanFull(),
                        Textarea::make('answer')->required()->rows(3)->maxLength(5000)->columnSpanFull(),
                        Toggle::make('is_published')->label('Published')->default(true),
                    ])
                    ->itemLabel(fn (array $state): ?string => Str::limit((string) ($state['question'] ?? ''), 90) ?: null)
                    ->defaultItems(0)
                    ->addActionLabel('Add FAQ')
                    ->collapsible()
                    ->reorderableWithButtons()
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => ['scope' => 'service'] + $data)
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => ['scope' => 'service'] + $data),
            ])
            ->disabled(self::contentLocked());
    }

    /**
     * The database keeps slugs unique across archived services too, so explain
     * the conflict instead of failing on save.
     */
    private static function archivedSlugRule(): Closure
    {
        return fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $archived = Service::onlyTrashed()
                ->where('slug', (string) $value)
                ->when($record?->getKey(), fn ($query, $id) => $query->whereKeyNot($id))
                ->first();

            if ($archived) {
                $fail("This slug belongs to the archived service “{$archived->name}”. Restore that service, permanently delete it (only possible if it has no orders), or choose another slug.");
            }
        };
    }

    private static function pricePreview(Get $get): HtmlString
    {
        $currency = (string) ($get('currency') ?: Settings::currency());
        $price = is_numeric($get('price')) ? Money::toMinor($get('price')) : null;
        $compare = is_numeric($get('compare_at_price')) ? Money::toMinor($get('compare_at_price')) : null;

        if ($price === null) {
            return new HtmlString('<span style="color: var(--gray-500);">Enter a price to preview it.</span>');
        }

        $text = 'Customers see: <strong>'.e(Money::format($price, $currency)).'</strong>';
        if ($compare && $compare > $price) {
            $text .= ' <s style="color: var(--gray-500);">'.e(Money::format($compare, $currency)).'</s> ('.Money::percentOff($compare, $price).'% off)';
        }
        if (filled($label = $get('promo_label'))) {
            $text .= ' · '.e((string) $label);
        }

        return new HtmlString($text.' <span style="color: var(--gray-500);">before promotions and coupons.</span>');
    }

    /** @param  list<string>  $warnings */
    private static function warningList(array $warnings): HtmlString
    {
        return new HtmlString('<ul style="list-style: disc; padding-left: 1.25rem;">'
            .implode('', array_map(fn (string $warning): string => '<li>'.e($warning).'</li>', $warnings))
            .'</ul>');
    }
}
