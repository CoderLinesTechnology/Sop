<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Domain\Delivery\DocumentDelivery;
use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Enums\EmailTemplateKey;
use App\Filament\Resources\Orders\OrderInsights;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\OperationsAudit;
use App\Models\EmailMessage;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** Customer emails an administrator can (re)send (emails.manage). */
final class CustomerEmailActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::resendDocumentEmail(), self::resendOrderLink()];
    }

    public static function resendDocumentEmail(): Action
    {
        return Action::make('resendDocumentEmail')
            ->label('Resend document email')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize('manageEmails')
            ->visible(fn (Order $record): bool => OrderInsights::deliverableVersions($record)->isNotEmpty())
            ->requiresConfirmation()
            ->modalHeading('Resend the document email?')
            ->modalDescription(fn (Order $record): string => 'Emails the latest validated version (PDF and DOCX) to '.$record->email.' again. Delivery status appears in the Emails tab.')
            ->modalSubmitActionLabel('Resend')
            ->action(fn (Order $record) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.document_resent', $record, fn () => app(DocumentDelivery::class)->resend($record)),
                'Sending the document email',
                "Couldn't resend the document email",
                'Delivery status updates in the Emails tab.',
            ));
    }

    public static function resendOrderLink(): Action
    {
        return Action::make('resendOrderLink')
            ->label('Resend order link')
            ->icon(Heroicon::OutlinedLink)
            ->authorize('manageEmails')
            ->visible(fn (Order $record): bool => OrderInsights::isSubmitted($record))
            ->requiresConfirmation()
            ->modalHeading('Resend the order link?')
            ->modalDescription(fn (Order $record): string => 'Emails '.$record->email.' a fresh secure link to their order page. Previously sent links keep working; rotate the link to revoke them.')
            ->modalSubmitActionLabel('Send link')
            ->action(fn (Order $record) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.link_resent', $record, fn () => self::sendOrderLink($record)),
                'Order link sent',
                "Couldn't send the order link",
            ));
    }

    /** Send the "order link" email with a newly signed link for the order's current access version. */
    public static function sendOrderLink(Order $order): EmailMessage
    {
        $email = app(TransactionalMailer::class)->send(
            EmailTemplateKey::OrderLink,
            $order->email,
            OrderEmailVariables::for($order),
            $order,
            meta: ['purpose' => 'order_link'],
        );

        return $email ?? throw new RuntimeException('The order link email template is disabled.');
    }
}
