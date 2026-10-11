<?php

namespace App\Filament\Resources\ReferralPayouts;

use App\Domain\Referrals\PaymentDetails;
use App\Domain\Referrals\ReferralPayouts;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\ReferralPayout;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

/**
 * Deciding payout requests (referrals.payouts): reveal the payment details
 * (audited), mark paid with the transfer reference, or reject with a reason
 * the partner receives. Each is authorised against ReferralPayoutPolicy.
 */
final class ReferralPayoutActions
{
    public static function revealDetails(): Action
    {
        return Action::make('revealDetails')
            ->label('Show payment details')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->authorize('viewPaymentDetails')
            ->modalHeading('Payment details for this request')
            ->modalDescription(fn (ReferralPayout $record): ?string => ReferralPayoutResource::detailsWarning($record))
            ->mountUsing(fn (ReferralPayout $record) => Audit::log('referral_payout.details_revealed', $record, meta: ['referrer' => $record->referrer?->code]))
            ->schema(fn (ReferralPayout $record): array => collect(PaymentDetails::describe($record->payment_details))
                ->map(fn (string $value, string $label) => TextEntry::make('detail_'.md5($label))->label($label)->state($value)->copyable())
                ->values()
                ->all())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label('Mark as paid')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('decide')
            ->modalHeading('Record this payout')
            ->modalDescription(fn (ReferralPayout $record): string => 'Pay '.Format::money($record->amount, $record->currency).' to '.ReferralPayoutResource::maskedDetails($record).' first, then enter the transfer or mobile money reference. The partner is emailed.'
                .(($warning = ReferralPayoutResource::detailsWarning($record)) ? ' Warning: '.$warning : ''))
            ->schema([
                TextInput::make('payout_reference')
                    ->label('Transfer / MoMo reference')
                    ->required()
                    ->maxLength(100),
            ])
            ->modalSubmitActionLabel('Mark as paid')
            ->action(function (array $data, ReferralPayout $record, Action $action): void {
                ActionRunner::run(
                    fn () => app(ReferralPayouts::class)->markPaid($record, (string) $data['payout_reference'], AdminContext::require()),
                    'Payout recorded',
                    "Couldn't record the payout",
                    'The partner has been emailed.',
                    keepOpen: $action,
                );
                Cache::forget('admin:referrals:open-payouts');
                $record->refresh();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('decide')
            ->modalHeading('Reject this payout request')
            ->modalDescription('The amount becomes available to the partner again and they are emailed the reason.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (sent to the partner)')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->modalSubmitActionLabel('Reject request')
            ->action(function (array $data, ReferralPayout $record, Action $action): void {
                ActionRunner::run(
                    fn () => app(ReferralPayouts::class)->reject($record, (string) $data['reason'], AdminContext::require()),
                    'Payout request rejected',
                    "Couldn't reject the request",
                    keepOpen: $action,
                );
                Cache::forget('admin:referrals:open-payouts');
                $record->refresh();
            });
    }
}
