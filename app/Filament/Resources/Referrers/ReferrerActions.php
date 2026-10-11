<?php

namespace App\Filament\Resources\Referrers;

use App\Domain\Referrals\PaymentDetails;
use App\Domain\Referrals\ReferrerService;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\AdminContext;
use App\Models\Referrer;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;

/**
 * Referrer actions for the table and the record pages. Each one is
 * authorised against ReferrerPolicy (visible() is presentation only) and runs
 * a domain operation in App\Domain\Referrals, which audits it.
 */
final class ReferrerActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::shareLink(),
            self::paymentDetails(),
            self::dashboardLink(),
            self::sendDashboardLink(),
            self::replaceDashboardLink(),
            self::disableDashboardLink(),
            self::deactivate(),
            self::activate(),
            self::erase(),
        ];
    }

    public static function shareLink(): Action
    {
        return Action::make('shareLink')
            ->label('Share link')
            ->icon(Heroicon::OutlinedShare)
            ->color('gray')
            ->authorize('view')
            ->visible(fn (Referrer $record): bool => ! $record->isErased())
            ->modalHeading('Share link')
            ->modalDescription('The referrer shares this link. Whichever page a visitor opens, the code stays with them.')
            ->fillForm(fn (Referrer $record): array => ['link' => $record->shareUrl()])
            ->schema([
                TextInput::make('link')->label('Link')->readOnly()->copyable(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function paymentDetails(): Action
    {
        return Action::make('paymentDetails')
            ->label('Payment details')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->authorize('viewPaymentDetails')
            ->visible(fn (Referrer $record): bool => $record->hasPaymentDetails())
            ->modalHeading('Confirmed payment details')
            ->modalDescription('Shown in full to administrators who handle payouts; opening this is recorded in the audit log.')
            ->mountUsing(fn (Referrer $record) => Audit::log('referrer.payment_details_revealed', $record, admin: AdminContext::require()))
            ->schema(fn (Referrer $record): array => collect(PaymentDetails::describe($record->payment_details))
                ->map(fn (string $value, string $label) => TextEntry::make('detail_'.md5($label))->label($label)->state($value)->copyable())
                ->values()
                ->all())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function dashboardLink(): Action
    {
        return Action::make('dashboardLink')
            ->label('Dashboard link')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->authorize('manage')
            ->visible(fn (Referrer $record): bool => $record->hasDashboardLink())
            ->modalHeading('Private dashboard link')
            ->modalDescription('Anyone with this link sees the referrer’s figures (no customer data). Share it only with the referrer.')
            ->fillForm(function (Referrer $record): array {
                app(ReferrerService::class)->dashboardLinkViewed($record, AdminContext::require());

                return ['link' => $record->dashboardUrl()];
            })
            ->schema([
                TextInput::make('link')->label('Link')->readOnly()->copyable(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function sendDashboardLink(): Action
    {
        return Action::make('sendDashboardLink')
            ->label('Email dashboard link')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize('manage')
            ->visible(fn (Referrer $record): bool => $record->hasDashboardLink() && $record->canAttribute() && $record->email !== null)
            ->requiresConfirmation()
            ->modalHeading('Email the dashboard link?')
            ->modalDescription(fn (Referrer $record): string => 'The private dashboard link is emailed to '.$record->email.'.')
            ->modalSubmitActionLabel('Send')
            ->action(fn (Referrer $record) => ActionRunner::run(
                fn () => app(ReferrerService::class)->sendDashboardLink($record, AdminContext::require()),
                'Dashboard link emailed',
                "Couldn't email the link",
            ));
    }

    public static function replaceDashboardLink(): Action
    {
        return Action::make('replaceDashboardLink')
            ->label(fn (Referrer $record): string => $record->hasDashboardLink() ? 'Replace dashboard link' : 'Create dashboard link')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->authorize('manage')
            ->visible(fn (Referrer $record): bool => ! $record->isErased())
            ->requiresConfirmation()
            ->modalHeading(fn (Referrer $record): string => $record->hasDashboardLink() ? 'Replace the dashboard link?' : 'Create a dashboard link?')
            ->modalDescription(fn (Referrer $record): string => $record->hasDashboardLink()
                ? 'The current link stops working immediately. Copy or email the new one afterwards.'
                : 'A new private link is created. Copy or email it afterwards.')
            ->modalSubmitActionLabel('Continue')
            ->action(fn (Referrer $record) => ActionRunner::run(
                fn () => app(ReferrerService::class)->replaceDashboardLink($record, AdminContext::require()),
                'New dashboard link created',
                "Couldn't replace the link",
                'Use “Dashboard link” to copy it, or “Email dashboard link” to send it.',
            ));
    }

    public static function disableDashboardLink(): Action
    {
        return Action::make('disableDashboardLink')
            ->label('Disable dashboard link')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('manage')
            ->visible(fn (Referrer $record): bool => $record->hasDashboardLink())
            ->requiresConfirmation()
            ->modalHeading('Disable the dashboard link?')
            ->modalDescription('The link stops working immediately and the referrer cannot request it by email until you create a new one.')
            ->modalSubmitActionLabel('Disable')
            ->action(fn (Referrer $record) => ActionRunner::run(
                fn () => app(ReferrerService::class)->disableDashboardLink($record, AdminContext::require()),
                'Dashboard link disabled',
                "Couldn't disable the link",
            ));
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('manage')
            ->visible(fn (Referrer $record): bool => $record->is_active && ! $record->isErased())
            ->requiresConfirmation()
            ->modalHeading('Deactivate this referrer?')
            ->modalDescription('Their link and code stop attributing new orders and their dashboard link stops working. Orders already attributed keep their commission.')
            ->modalSubmitActionLabel('Deactivate')
            ->action(fn (Referrer $record) => ActionRunner::run(
                fn () => app(ReferrerService::class)->setActive($record, false, AdminContext::require()),
                'Referrer deactivated',
                "Couldn't deactivate the referrer",
            ));
    }

    public static function activate(): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('manage')
            ->visible(fn (Referrer $record): bool => ! $record->is_active && ! $record->isErased())
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Activate')
            ->action(fn (Referrer $record) => ActionRunner::run(
                fn () => app(ReferrerService::class)->setActive($record, true, AdminContext::require()),
                'Referrer activated',
                "Couldn't activate the referrer",
            ));
    }

    public static function erase(): Action
    {
        return Action::make('erasePersonalData')
            ->label('Erase personal data')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('manage')
            ->visible(fn (Referrer $record): bool => ! $record->isErased())
            ->requiresConfirmation()
            ->modalHeading('Erase this referrer’s personal data?')
            ->modalDescription('Name, email, phone and notes are removed for good, the referrer is deactivated and the dashboard link stops working. The commission ledger and order attribution are kept (accounting records). This cannot be undone.')
            ->modalSubmitActionLabel('Erase')
            ->action(fn (Referrer $record) => ActionRunner::run(
                fn () => app(ReferrerService::class)->erase($record, AdminContext::require()),
                'Personal data erased',
                "Couldn't erase the referrer",
            ));
    }
}
