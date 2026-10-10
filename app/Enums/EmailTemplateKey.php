<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Transactional emails with admin-editable templates. */
enum EmailTemplateKey: string implements HasLabel
{
    case PaymentReceived = 'payment_received';
    case InformationRequired = 'information_required';
    case InformationReminder = 'information_reminder';
    case ProcessingDelay = 'processing_delay';
    case DocumentReady = 'document_ready';
    case RevisionReceived = 'revision_received';
    case RevisionCompleted = 'revision_completed';
    case RefundProcessed = 'refund_processed';
    case OrderLink = 'order_link';
    case MagicLink = 'magic_link';
    case NewsletterConfirm = 'newsletter_confirm';
    case SupportReceived = 'support_received';

    public function getLabel(): string
    {
        return match ($this) {
            self::PaymentReceived => 'Payment received / request received',
            self::InformationRequired => 'Additional information required',
            self::InformationReminder => 'Additional information reminder',
            self::ProcessingDelay => 'Processing delay',
            self::DocumentReady => 'Document ready',
            self::RevisionReceived => 'Revision received',
            self::RevisionCompleted => 'Revision completed',
            self::RefundProcessed => 'Refund processed',
            self::OrderLink => 'Order link (resend)',
            self::MagicLink => 'Account sign-in link',
            self::NewsletterConfirm => 'Newsletter confirmation',
            self::SupportReceived => 'Support request received',
        };
    }

    /** Essential emails cannot be disabled by administrators. */
    public function isEssential(): bool
    {
        return ! in_array($this, [self::InformationReminder, self::ProcessingDelay, self::RevisionReceived, self::SupportReceived], true);
    }

    /**
     * Variables available in this template, besides the global ones
     * ({{site_name}}, {{support_email}}, {{site_url}}).
     *
     * @return list<string>
     */
    public function variables(): array
    {
        $order = ['customer_name', 'order_id', 'service_name', 'institution', 'programme', 'order_link'];

        return match ($this) {
            self::PaymentReceived => [...$order, 'amount_paid', 'delivery_time'],
            self::InformationRequired, self::InformationReminder => [...$order, 'questions'],
            self::ProcessingDelay => [...$order, 'delivery_time'],
            self::DocumentReady => [...$order, 'document_link', 'pdf_link', 'docx_link', 'revisions_remaining', 'revision_deadline', 'points_to_check'],
            self::RevisionReceived => [...$order, 'revision_number'],
            self::RevisionCompleted => [...$order, 'revision_number', 'document_link'],
            self::RefundProcessed => [...$order, 'refund_amount'],
            self::OrderLink => $order,
            self::MagicLink => ['login_link', 'expires_minutes'],
            self::NewsletterConfirm => ['confirm_link'],
            self::SupportReceived => ['customer_name', 'order_id'],
        };
    }
}
