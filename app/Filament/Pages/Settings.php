<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\FormRules;
use App\Filament\Support\Catalogue\MediaUpload;
use App\Filament\Support\Catalogue\SettingsSchema;
use App\Filament\Support\Catalogue\SystemHealth;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Settings as SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Site-wide settings (App\Support\Settings). Every change is saved with the
 * administrator's id and audited with before/after values; only changed keys
 * are stored, so untouched settings keep following the code defaults.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Settings';

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Permission::SettingsManage);
    }

    public function mount(): void
    {
        $this->form->fill(SettingsSchema::formState());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('Settings')
                    ->persistTabInQueryString('tab')
                    ->tabs([
                        $this->generalTab(),
                        $this->ordersTab(),
                        $this->paymentsTab(),
                        $this->aiTab(),
                        $this->emailTab(),
                        $this->securityTab(),
                        $this->seoTab(),
                        $this->analyticsTab(),
                        $this->systemTab(),
                        $this->otherTab(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $values = SettingsSchema::fromFormState($this->form->getState());
        [$before, $after] = AuditDiff::changes(SettingsSchema::current(), $values);

        if ($after === []) {
            Notification::make()->info()->title('No changes to save')->send();

            return;
        }

        $admin = AdminAccess::user();
        SiteSettings::setMany($after, $admin?->id);
        Audit::log('settings.updated', 'settings', $before, $after, ['keys' => array_keys($after)], $admin);

        $this->form->fill(SettingsSchema::formState());

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->body(count($after).' setting(s) updated. Changes apply immediately.')
            ->send();
    }

    private function generalTab(): Tab
    {
        return Tab::make('General')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->schema([
                Section::make('Brand')
                    ->schema([
                        TextInput::make('general.site_name')->label('Site name')->required()->maxLength(80),
                        TextInput::make('general.tagline')->label('Tagline')->maxLength(160),
                        MediaUpload::to('general.logo_path', 'settings')
                            ->label('Logo')
                            ->helperText('Optional. PNG or WebP with a transparent background works best (up to 5 MB).')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Contact')
                    ->schema([
                        TextInput::make('general.contact_email')->label('Contact email')->email()->maxLength(255),
                        TextInput::make('general.support_email')->label('Support email')->email()->required()->maxLength(255)
                            ->helperText('Shown to customers and in every email.'),
                    ])
                    ->columns(2),
                Section::make('Regional')
                    ->schema([
                        Select::make('general.currency')
                            ->label('Default currency')
                            ->options(Money::currencyOptions())
                            ->required()
                            ->native(false)
                            ->helperText('Default for new services. Each service has its own price and currency.'),
                        Select::make('general.timezone')
                            ->label('Timezone')
                            ->options(SettingsSchema::timezones())
                            ->searchable()
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Social profiles')
                    ->schema([
                        TextInput::make('general.social_linkedin')->label('LinkedIn')->url()->maxLength(255)->placeholder('https://www.linkedin.com/company/…'),
                        TextInput::make('general.social_x')->label('X (Twitter)')->url()->maxLength(255)->placeholder('https://x.com/…'),
                        TextInput::make('general.social_instagram')->label('Instagram')->url()->maxLength(255)->placeholder('https://www.instagram.com/…'),
                        TextInput::make('general.social_youtube')->label('YouTube')->url()->maxLength(255)->placeholder('https://www.youtube.com/@…'),
                    ])
                    ->columns(2),
            ]);
    }

    private function ordersTab(): Tab
    {
        return Tab::make('Orders')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->schema([
                Section::make('Delivery estimate')
                    ->description('Shown to customers. Services can override it.')
                    ->schema([
                        TextInput::make('orders.delivery_min_minutes')->label('From')->integer()->minValue(1)->maxValue(10080)->required()->suffix('minutes'),
                        TextInput::make('orders.delivery_max_minutes')->label('To')->integer()->minValue(1)->maxValue(10080)->required()->suffix('minutes')
                            ->rules([FormRules::notLessThan('orders.delivery_min_minutes', 'The upper estimate cannot be lower than the lower estimate.')]),
                    ])
                    ->columns(2),
                Section::make('Uploads')
                    ->schema([
                        TextInput::make('orders.max_file_size_mb')->label('Maximum file size')->integer()->minValue(1)->maxValue(25)->required()->suffix('MB'),
                        TextInput::make('orders.max_files_per_order')->label('Maximum files per order')->integer()->minValue(1)->maxValue(50)->required(),
                        CheckboxList::make('orders.allowed_file_types')
                            ->label('Allowed file types')
                            ->options(SettingsSchema::FILE_TYPES)
                            ->required()
                            ->columns(3)
                            ->helperText('Upload slots can only narrow this list.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Links, payment and drafts')
                    ->schema([
                        TextInput::make('orders.order_link_days')->label('Order links valid for')->integer()->minValue(1)->maxValue(365)->required()->suffix('days'),
                        TextInput::make('orders.payment_expiry_hours')->label('Unpaid checkouts expire after')->integer()->minValue(1)->maxValue(168)->required()->suffix('hours'),
                        TextInput::make('orders.draft_expiry_hours')->label('Unsubmitted drafts expire after')->integer()->minValue(1)->maxValue(720)->required()->suffix('hours'),
                        TextInput::make('orders.retention_days')->label('Keep customer files for')->integer()->minValue(7)->maxValue(3650)->required()->suffix('days after delivery')
                            ->helperText('Uploaded and generated files are deleted after this period.'),
                    ])
                    ->columns(2),
                Section::make('When we need more information')
                    ->schema([
                        TextInput::make('orders.needs_info_reminder_hours')->label('Send a reminder after')->integer()->minValue(1)->maxValue(168)->required()->suffix('hours'),
                        TextInput::make('orders.needs_info_timeout_hours')->label('Give up waiting after')->integer()->minValue(1)->maxValue(336)->required()->suffix('hours')
                            ->rules([FormRules::greaterThan('orders.needs_info_reminder_hours', 'Must be later than the reminder.')]),
                        Select::make('orders.needs_info_timeout_action')
                            ->label('If the customer does not answer')
                            ->options([
                                'proceed' => 'Continue with the information we have',
                                'manual_review' => 'Send the order to manual review',
                            ])
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2),
            ]);
    }

    private function paymentsTab(): Tab
    {
        return Tab::make('Payments')
            ->icon(Heroicon::OutlinedCreditCard)
            ->schema([
                Section::make('Orders')
                    ->schema([
                        Toggle::make('payments.allow_free_orders')
                            ->label('Allow fully discounted (free) orders')
                            ->helperText('When a coupon brings the total to zero, the order skips Paystack and starts immediately.'),
                    ]),
                Section::make('Paystack')
                    ->description('Configured in the server environment (PAYSTACK_* variables), not here. Keys are never shown.')
                    ->schema([
                        Callout::make('Live mode is using a test key')
                            ->description('PAYSTACK_MODE is "live" but the configured keys are not live keys (sk_live_… / pk_live_…). Real payments will fail.')
                            ->danger()
                            ->visible(fn (): bool => self::paystackLiveWithTestKey()),
                        Text::make(fn (): HtmlString => self::paystackStatus()),
                        TextInput::make('paystack_webhook_url')
                            ->label('Webhook URL (set this in the Paystack dashboard)')
                            ->default(fn (): string => url('/webhooks/paystack'))
                            ->formatStateUsing(fn (): string => url('/webhooks/paystack'))
                            ->readOnly()
                            ->dehydrated(false)
                            ->copyable(),
                    ]),
            ]);
    }

    private function aiTab(): Tab
    {
        return Tab::make('AI')
            ->icon(Heroicon::OutlinedSparkles)
            ->schema([
                Section::make('Budget')
                    ->schema([
                        TextInput::make('ai.daily_budget_usd')
                            ->label('Daily AI budget')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100000)
                            ->step(0.01)
                            ->prefix('$')
                            ->suffix('USD per day')
                            ->required()
                            ->helperText('Estimated model and web-search spend across all orders. Per-order limits are set on each AI workflow.'),
                    ]),
                Section::make('Writing style')
                    ->schema([
                        TagsInput::make('ai.banned_phrases')
                            ->label('Banned phrases')
                            ->placeholder('Add a phrase and press Enter')
                            ->nestedRecursiveRules(['string', 'max:100'])
                            ->helperText('Clichés the writer must avoid. Matching is case-insensitive.'),
                    ]),
            ]);
    }

    private function emailTab(): Tab
    {
        return Tab::make('Email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->schema([
                Section::make('Sender')
                    ->schema([
                        TextInput::make('email.from_name')->label('From name')->required()->maxLength(100),
                        TextInput::make('email.from_address')->label('From address')->email()->maxLength(255)
                            ->helperText('Blank = the MAIL_FROM_ADDRESS of the server. Must be a domain verified with your email provider.'),
                        TextInput::make('email.reply_to')->label('Reply-to address')->email()->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Delivery')
                    ->schema([
                        Toggle::make('email.attach_documents')
                            ->label('Attach the PDF and Word files to delivery emails')
                            ->helperText('When off (or the files are too large), customers download them from a secure link.'),
                        TextInput::make('email.document_link_days')->label('Download links valid for')->integer()->minValue(1)->maxValue(90)->required()->suffix('days'),
                    ]),
                Section::make('Team notifications')
                    ->schema([
                        TagsInput::make('email.admin_notification_emails')
                            ->label('Notify these addresses about failures and manual reviews')
                            ->placeholder('Add an email and press Enter')
                            ->nestedRecursiveRules(['email', 'max:255']),
                    ]),
            ]);
    }

    private function securityTab(): Tab
    {
        return Tab::make('Security')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->schema([
                Section::make('Admin sessions')
                    ->schema([
                        TextInput::make('security.admin_session_minutes')->label('Sign administrators out after')->integer()->minValue(5)->maxValue(1440)->required()->suffix('minutes of inactivity'),
                    ]),
                Section::make('Rate limits (per IP address)')
                    ->schema([
                        TextInput::make('security.rate_limit_uploads_per_hour')->label('Uploads')->integer()->minValue(1)->maxValue(1000)->required()->suffix('per hour'),
                        TextInput::make('security.rate_limit_orders_per_hour')->label('Order checkouts')->integer()->minValue(1)->maxValue(1000)->required()->suffix('per hour'),
                        TextInput::make('security.rate_limit_coupon_attempts_per_hour')->label('Coupon attempts')->integer()->minValue(1)->maxValue(1000)->required()->suffix('per hour'),
                    ])
                    ->columns(3),
            ]);
    }

    private function seoTab(): Tab
    {
        return Tab::make('SEO')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->schema([
                Section::make('Defaults')
                    ->schema([
                        TextInput::make('seo.default_title')->label('Default page title')->required()->maxLength(255),
                        TextInput::make('seo.title_suffix')->label('Title suffix')->maxLength(60)
                            ->helperText('Added after every page title, including its leading space, e.g. “ | Statementra”.'),
                        Textarea::make('seo.default_description')->label('Default meta description')->rows(3)->maxLength(500),
                        MediaUpload::to('seo.social_image', 'settings')
                            ->label('Default social sharing image')
                            ->helperText('1200 × 630 px recommended. JPEG, PNG or WebP, up to 5 MB.'),
                        TextInput::make('seo.google_site_verification')->label('Google Search Console verification code')->maxLength(120)
                            ->helperText('Only the content value of the meta tag.'),
                    ]),
            ]);
    }

    private function analyticsTab(): Tab
    {
        return Tab::make('Analytics')
            ->icon(Heroicon::OutlinedChartBar)
            ->schema([
                Section::make('Privacy-friendly analytics')
                    ->description('Cookieless, first-party analytics: no third-party scripts and no raw IP addresses are stored.')
                    ->schema([
                        Toggle::make('analytics.enabled')->label('Record analytics'),
                        Toggle::make('analytics.respect_gpc')->label('Honour Global Privacy Control / Do Not Track')
                            ->helperText('Visitors sending these signals are counted without a visitor id.'),
                        TextInput::make('analytics.retention_days')->label('Keep analytics events for')->integer()->minValue(30)->maxValue(3650)->required()->suffix('days'),
                    ]),
            ]);
    }

    /** Settings added to the code defaults that have no dedicated field yet. */
    private function otherTab(): Tab
    {
        $keys = SettingsSchema::unhandledKeys();

        return Tab::make('Other')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->visible($keys !== [])
            ->schema([
                Section::make('Additional settings')
                    ->description('Settings without a dedicated screen yet.')
                    ->schema(array_map(SettingsSchema::genericField(...), $keys))
                    ->columns(2),
            ]);
    }

    private function systemTab(): Tab
    {
        return Tab::make('System')
            ->icon(Heroicon::OutlinedServerStack)
            ->schema([
                Section::make('Heartbeat')
                    ->key('heartbeat')
                    ->description('There is no cron job or queue worker. Maintenance tasks run on a heartbeat that follows ordinary site traffic (at most once a minute). An uptime monitor calling this URL every few minutes keeps them running when the site is quiet.')
                    ->afterHeader([
                        Action::make('regenerateHeartbeatToken')
                            ->label('Regenerate URL')
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->color('gray')
                            ->hidden(fn (): bool => SystemHealth::tokenFromEnvironment())
                            ->requiresConfirmation()
                            ->modalHeading('Regenerate the heartbeat URL?')
                            ->modalDescription('The current URL stops working immediately. Update your uptime monitor with the new URL.')
                            ->modalSubmitActionLabel('Regenerate')
                            ->action(function (): void {
                                abort_unless(static::canAccess(), 403);

                                SystemHealth::regenerateToken(AdminAccess::user());
                                $this->data['system_ping_url'] = SystemHealth::pingUrl();

                                Notification::make()
                                    ->success()
                                    ->title('New heartbeat URL generated')
                                    ->body('Copy it into your uptime monitor.')
                                    ->send();
                            }),
                    ])
                    ->schema([
                        TextInput::make('system_ping_url')
                            ->label('Ping URL')
                            ->formatStateUsing(fn (): ?string => SystemHealth::pingUrl())
                            ->readOnly()
                            ->dehydrated(false)
                            ->copyable()
                            ->helperText(fn (): string => SystemHealth::tokenFromEnvironment()
                                ? 'The secret part of this URL is set in the server environment (RUNTIME_HEARTBEAT_TOKEN); change it there.'
                                : 'Keep it private: anyone with the URL can trigger the maintenance tasks (they only run when due).'),
                        Text::make(fn (): HtmlString => SystemHealth::heartbeatSummary()),
                    ]),
                Section::make('Maintenance tasks')
                    ->key('maintenance')
                    ->description('Each task runs from the heartbeat when its interval has passed. A task is overdue when it has not started for three intervals.')
                    ->afterHeader([
                        Action::make('runSystemTask')
                            ->label('Run a task now')
                            ->icon(Heroicon::OutlinedPlayCircle)
                            ->color('gray')
                            ->visible(fn (): bool => SystemHealth::configuredTasks() !== [])
                            ->schema([
                                Select::make('task')
                                    ->options(fn (): array => SystemHealth::configuredTasks())
                                    ->required()
                                    ->native(false),
                            ])
                            ->modalDescription('The task runs right after this request, whatever its schedule. Refresh the page in a moment to see the result.')
                            ->modalSubmitActionLabel('Run now')
                            ->action(function (array $data): void {
                                abort_unless(static::canAccess(), 403);

                                SystemHealth::runTaskNow((string) $data['task'], AdminAccess::user());

                                Notification::make()->success()->title('Task started')->body((string) $data['task'])->send();
                            }),
                    ])
                    ->schema([
                        Text::make(fn (): HtmlString => SystemHealth::tasksTable()),
                    ]),
            ]);
    }

    public static function paystackLiveWithTestKey(): bool
    {
        if (config('statementra.paystack.mode') !== 'live') {
            return false;
        }

        $secret = (string) config('statementra.paystack.secret_key');
        $public = (string) config('statementra.paystack.public_key');

        return ($secret !== '' && ! str_starts_with($secret, 'sk_live_'))
            || ($public !== '' && ! str_starts_with($public, 'pk_live_'));
    }

    private static function paystackStatus(): HtmlString
    {
        $mode = (string) config('statementra.paystack.mode');
        $row = fn (string $label, string $value, bool $ok): string => '<div style="display: flex; justify-content: space-between; gap: 1rem; padding: .35rem 0; border-bottom: 1px solid var(--gray-100);">'
            .'<span>'.e($label).'</span><strong style="color: '.($ok ? 'var(--success-600)' : 'var(--danger-600)').';">'.e($value).'</strong></div>';

        return new HtmlString(
            $row('Mode', match ($mode) {
                'live' => 'Live',
                'test' => 'Test',
                'mock' => 'Mock (development only)',
                default => $mode ?: 'Not set',
            }, in_array($mode, ['live', 'test', 'mock'], true))
            .$row('Secret key', filled(config('statementra.paystack.secret_key')) ? 'Configured' : 'Missing', filled(config('statementra.paystack.secret_key')))
            .$row('Public key', filled(config('statementra.paystack.public_key')) ? 'Configured' : 'Missing', filled(config('statementra.paystack.public_key')))
        );
    }
}
