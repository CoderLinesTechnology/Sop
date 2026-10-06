<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Domain\Payments\RefundService;
use App\Enums\Permission;
use App\Enums\RefundStatus;
use App\Filament\Resources\Orders\OrderInsights;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\Order;
use App\Models\Refund;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Refund all or part of an order (refunds.request). Administrators who can
 * also approve refunds may submit it to Paystack straight away; otherwise it
 * waits for approval in Orders → Refunds. The amount can never exceed the
 * payment's refundable balance (checked here and again, under a row lock,
 * by RefundService).
 */
final class RefundOrderAction
{
    public static function make(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->authorize('refund')
            ->visible(fn (Order $record): bool => ! OrderInsights::hasOpenRefund($record) && OrderInsights::refundableAmount($record) > 0)
            ->modalHeading(fn (Order $record): string => 'Refund order '.$record->reference)
            ->modalDescription(function (Order $record): string {
                $payment = OrderInsights::capturedPayment($record);
                $balance = $payment ? Format::money($payment->refundableAmount(), $payment->currency).' of '.Format::money($payment->amount, $payment->currency).' paid is refundable.' : '';

                return $balance.' '.(self::canApprove()
                    ? 'You can approve the refund now; it is submitted to Paystack immediately.'
                    : 'A finance administrator must approve the refund before it is paid out.');
            })
            ->fillForm(fn (Order $record): array => [
                'amount' => Money::toMajor(OrderInsights::refundableAmount($record)),
                'approve_now' => self::canApprove(),
            ])
            ->schema(fn (Order $record): array => [
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->maxValue(fn (): float => (float) Money::toMajor(OrderInsights::refundableAmount($record)))
                    ->step(0.01)
                    ->prefix(OrderInsights::capturedPayment($record)?->currency ?? $record->currency)
                    ->helperText('Defaults to the full refundable balance. Enter a smaller amount for a partial refund.'),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3),
                Toggle::make('approve_now')
                    ->label('Approve and submit to Paystack now')
                    ->visible(self::canApprove()),
            ])
            ->modalSubmitActionLabel('Create refund')
            ->action(function (array $data, Order $record, Action $action): void {
                $amount = (int) Money::toMinor($data['amount']);
                $approve = self::canApprove() && (bool) ($data['approve_now'] ?? false);

                ActionRunner::run(
                    function () use ($record, $amount, $data, $approve): Refund {
                        $refundable = OrderInsights::refundableAmount($record);
                        if ($amount <= 0 || $amount > $refundable) {
                            throw new RuntimeException('The refund amount must be between '.Format::money(1, $record->currency).' and '.Format::money($refundable, $record->currency).'.');
                        }

                        $service = app(RefundService::class);
                        $admin = AdminContext::require();
                        $refund = $service->request($record, $amount, trim($data['reason']), $admin);

                        return $approve ? $service->approve($refund, $admin) : $refund;
                    },
                    'Refund created',
                    "Couldn't create the refund",
                    fn (Refund $refund): string => match ($refund->status) {
                        RefundStatus::Processed => Format::money($refund->amount, $refund->currency).' has been refunded.',
                        RefundStatus::Processing, RefundStatus::Approved => Format::money($refund->amount, $refund->currency).' was submitted to Paystack.',
                        RefundStatus::Failed => 'Paystack rejected the refund: '.($refund->failure_reason ?: 'no reason given').'. Mark it processed manually once paid by other means.',
                        default => Format::money($refund->amount, $refund->currency).' is waiting for approval.',
                    },
                    keepOpen: $action,
                );
            });
    }

    private static function canApprove(): bool
    {
        return AdminContext::can(Permission::RefundsApprove);
    }
}
