<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\DocumentKind;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderInsights;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Customer, application, pricing, fulfilment and the status timeline. */
class OrderOverviewTab
{
    public static function make(): Tab
    {
        return Tab::make('Overview')
            ->key('overview')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->schema([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    self::customer()->columnSpan(1),
                    self::application()->columnSpan(['default' => 1, 'lg' => 2]),
                ]),
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    self::pricing()->columnSpan(1),
                    self::fulfilment()->columnSpan(['default' => 1, 'lg' => 2]),
                ]),
                self::timeline(),
            ]);
    }

    private static function customer(): Section
    {
        return Section::make('Customer')
            ->icon(Heroicon::OutlinedUser)
            ->schema([
                TextEntry::make('customer_name')
                    ->label('Name')
                    ->placeholder('Not provided'),
                TextEntry::make('applicant_name')
                    ->label('Applicant name')
                    ->visible(fn (Order $record): bool => filled($record->applicant_name) && $record->applicant_name !== $record->customer_name),
                TextEntry::make('email')
                    ->label('Email')
                    ->copyable(),
                TextEntry::make('customer_phone')
                    ->label('Phone')
                    ->placeholder('Not provided')
                    ->visible(fn (Order $record): bool => AdminContext::allows('viewCustomerData', $record)),
                TextEntry::make('account')
                    ->label('Customer account')
                    ->state(fn (Order $record): string => $record->user ? 'Account · '.$record->user->email : 'Guest checkout')
                    ->url(fn (Order $record): ?string => $record->user && class_exists(CustomerResource::class) && CustomerResource::canView($record->user)
                        ? CustomerResource::getUrl('view', ['record' => $record->user])
                        : null),
                TextEntry::make('risk_score')
                    ->label('Risk score')
                    ->badge()
                    ->color(fn ($state): string => (int) $state >= 50 ? 'danger' : ((int) $state >= 20 ? 'warning' : 'gray'))
                    ->helperText(fn (Order $record): ?string => $record->risk_flags ? implode(', ', array_map('strval', (array) $record->risk_flags)) : null)
                    ->visible(fn (Order $record): bool => (int) $record->risk_score > 0),
                TextEntry::make('ip_address')
                    ->label('Placed from')
                    ->state(fn (Order $record): ?string => $record->ip_address)
                    ->tooltip(fn (Order $record): ?string => $record->user_agent)
                    ->placeholder(Format::PLACEHOLDER)
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Small)
                    ->visible(fn (Order $record): bool => AdminContext::allows('viewCustomerData', $record)),
            ]);
    }

    private static function application(): Section
    {
        return Section::make('Application')
            ->icon(Heroicon::OutlinedAcademicCap)
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                TextEntry::make('service_name')
                    ->label('Service')
                    ->state(fn (Order $record): string => $record->serviceName())
                    ->helperText(function (Order $record): ?string {
                        $kind = DocumentKind::tryFrom($record->documentKind())?->getLabel();

                        return $kind !== $record->serviceName() ? $kind : null;
                    })
                    ->weight(FontWeight::SemiBold),
                TextEntry::make('institution')
                    ->label('Institution')
                    ->placeholder('Not specified'),
                TextEntry::make('programme')
                    ->label('Programme')
                    ->placeholder('Not specified'),
                TextEntry::make('degree_level')
                    ->label('Degree level')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? str($state)->replace('_', ' ')->ucfirst()->toString() : null)
                    ->placeholder('Not specified'),
                TextEntry::make('country_code')
                    ->label('Destination country')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? self::countryName($state) : null)
                    ->placeholder('Not specified'),
                TextEntry::make('intake')
                    ->label('Intake')
                    ->placeholder('Not specified'),
                TextEntry::make('deadline')
                    ->label('Application deadline')
                    ->date(Format::DATE)
                    ->placeholder('Not specified'),
                TextEntry::make('word_limit')
                    ->label('Word limit')
                    ->numeric()
                    ->placeholder('None stated'),
                TextEntry::make('language_variant')
                    ->label('Language variant')
                    ->placeholder('Default'),
                TextEntry::make('essay_prompt')
                    ->label('Essay prompt / question')
                    ->formatStateUsing(fn (?string $state): ?HtmlString => $state ? new HtmlString(nl2br(e($state))) : null)
                    ->placeholder('No specific prompt')
                    ->columnSpanFull(),
            ]);
    }

    private static function pricing(): Section
    {
        return Section::make('Pricing')
            ->icon(Heroicon::OutlinedBanknotes)
            ->schema([
                TextEntry::make('pricing_base')
                    ->label('Service price')
                    ->state(fn (Order $record): string => Format::money((int) data_get($record->pricing_snapshot, 'base_amount', $record->subtotal_amount), $record->currency))
                    ->helperText(function (Order $record): ?string {
                        $compareAt = data_get($record->pricing_snapshot, 'compare_at_amount');

                        return $compareAt ? 'Compare-at price '.Format::money((int) $compareAt, $record->currency) : null;
                    }),
                TextEntry::make('promotion_discount')
                    ->label('Promotion')
                    ->state(fn (Order $record): string => '−'.Format::money($record->promotion_discount, $record->currency))
                    ->helperText(fn (Order $record): ?string => data_get($record->pricing_snapshot, 'promotion.label')
                        ?: data_get($record->pricing_snapshot, 'promotion.name')
                        ?: $record->promotion?->name)
                    ->visible(fn (Order $record): bool => $record->promotion_discount > 0),
                TextEntry::make('coupon_discount')
                    ->label('Coupon')
                    ->state(fn (Order $record): string => '−'.Format::money($record->coupon_discount, $record->currency))
                    ->helperText(fn (Order $record): ?string => $record->coupon_code)
                    ->visible(fn (Order $record): bool => $record->coupon_discount > 0 || filled($record->coupon_code)),
                TextEntry::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn (?int $state, Order $record): string => Format::money($state, $record->currency))
                    ->weight(FontWeight::Bold)
                    ->size(TextSize::Large),
                TextEntry::make('payment_status')
                    ->label('Payment')
                    ->badge(),
                TextEntry::make('payment_reference')
                    ->label('Payment reference')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Small)
                    ->placeholder(Format::PLACEHOLDER),
                TextEntry::make('paid_at')
                    ->label('Paid')
                    ->state(fn (Order $record) => $record->successfulPayment?->paid_at)
                    ->dateTime(Format::DATETIME)
                    ->visible(fn (Order $record): bool => in_array($record->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)),
            ]);
    }

    private static function fulfilment(): Section
    {
        return Section::make('Fulfilment')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                TextEntry::make('status')
                    ->label('Order status')
                    ->badge()
                    ->helperText(fn (Order $record): ?string => $record->isPaused() ? 'Paused '.Format::dateTime($record->paused_at) : null),
                TextEntry::make('pipeline')
                    ->label('AI pipeline')
                    ->state(function (Order $record): ?string {
                        $job = OrderInsights::latestJob($record);
                        if (! $job) {
                            return null;
                        }

                        return $job->status->getLabel().($job->current_stage ? ' · '.$job->current_stage->getLabel() : '');
                    })
                    ->badge()
                    ->color(fn (Order $record): string => OrderInsights::latestJob($record)?->status->getColor() ?? 'gray')
                    ->placeholder('Not started'),
                TextEntry::make('created_at')
                    ->label('Order created')
                    ->dateTime(Format::DATETIME),
                TextEntry::make('processing_started_at')
                    ->label('Processing started')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER),
                TextEntry::make('estimated_ready_at')
                    ->label('Estimated ready')
                    ->dateTime(Format::DATETIME)
                    ->visible(fn (Order $record): bool => $record->estimated_ready_at !== null && $record->delivered_at === null),
                TextEntry::make('delivered_at')
                    ->label('Delivered')
                    ->dateTime(Format::DATETIME)
                    ->placeholder('Not delivered yet'),
                TextEntry::make('cancelled_at')
                    ->label('Cancelled')
                    ->dateTime(Format::DATETIME)
                    ->visible(fn (Order $record): bool => $record->cancelled_at !== null),
                TextEntry::make('revisions')
                    ->label('Revisions')
                    ->state(fn (Order $record): string => (int) $record->revisions_used.' of '.(int) $record->revisions_allowed.' used')
                    ->helperText(fn (Order $record): ?string => $record->revision_deadline_at ? 'Window closes '.Format::dateTime($record->revision_deadline_at) : null),
                TextEntry::make('retention_until')
                    ->label('Data retained until')
                    ->dateTime(Format::DATE)
                    ->placeholder(Format::PLACEHOLDER),
                TextEntry::make('data_purged_at')
                    ->label('Customer data purged')
                    ->dateTime(Format::DATETIME)
                    ->color('danger')
                    ->visible(fn (Order $record): bool => $record->data_purged_at !== null),
            ]);
    }

    private static function timeline(): Section
    {
        return Section::make('Timeline')
            ->icon(Heroicon::OutlinedClock)
            ->description('Every status change, who made it and why.')
            ->schema([
                RepeatableEntry::make('statusHistories')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('When')->width('11rem'),
                        TableColumn::make('From'),
                        TableColumn::make('To'),
                        TableColumn::make('By'),
                        TableColumn::make('Reason'),
                    ])
                    ->schema([
                        TextEntry::make('created_at')->dateTime(Format::DATETIME),
                        TextEntry::make('from_status')->badge()->placeholder('Created'),
                        TextEntry::make('to_status')->badge(),
                        TextEntry::make('actor')
                            ->state(fn (OrderStatusHistory $record): string => $record->admin?->name
                                ?? str($record->actor_type ?: 'system')->ucfirst()->toString()),
                        TextEntry::make('reason')->placeholder(Format::PLACEHOLDER),
                    ])
                    ->visible(fn (Order $record): bool => $record->statusHistories->isNotEmpty()),
                EmptyState::make('No status changes yet')
                    ->description('Status changes appear here as the order moves through payment and fulfilment.')
                    ->icon(Heroicon::OutlinedClock)
                    ->contained(false)
                    ->visible(fn (Order $record): bool => $record->statusHistories->isEmpty()),
            ]);
    }

    private static function countryName(string $code): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, 'en') : null;

        return $name && $name !== $code ? $name.' ('.$code.')' : $code;
    }
}
