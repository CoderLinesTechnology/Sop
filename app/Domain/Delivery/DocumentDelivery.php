<?php

namespace App\Domain\Delivery;

use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Domain\Notifications\AdminNotifier;
use App\Domain\Orders\OrderAccess;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\EmailTemplateKey;
use App\Enums\OrderStatus;
use App\Enums\RevisionStatus;
use App\Models\DocumentVersion;
use App\Models\EmailMessage;
use App\Models\Order;
use App\Models\Revision;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Email is the primary delivery channel. The finished PDF and DOCX are
 * attached (decrypted in memory from the private vault) together with a
 * signed link to the secure order page. The order only becomes DELIVERED
 * once the email provider has accepted the message; if every retry fails it
 * becomes DELIVERY_FAILED and administrators are alerted and can resend.
 */
class DocumentDelivery
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly OrderStateMachine $states,
        private readonly AdminNotifier $notifier,
    ) {}

    public function deliver(Order $order, DocumentVersion $version, ?Revision $revision = null): EmailMessage
    {
        if (! $version->isDeliverable()) {
            throw new RuntimeException('Document version has not passed file validation.');
        }

        if (! $revision && in_array($order->status, [OrderStatus::FinalReview, OrderStatus::DeliveryFailed, OrderStatus::ManualReview, OrderStatus::ProcessingFailed], true)) {
            $this->states->transitionIfAllowed($order, OrderStatus::DeliveryPending, 'system');
        }

        $variables = OrderEmailVariables::for($order) + [
            'document_link' => OrderAccess::statusUrl($order),
            'pdf_link' => OrderAccess::downloadUrl($order, $version, 'pdf'),
            'docx_link' => OrderAccess::downloadUrl($order, $version, 'docx'),
            'revisions_remaining' => (string) $order->revisionsRemaining(),
            'revision_deadline' => ($order->revision_deadline_at ?? now()->addDays((int) data_get($order->service_snapshot, 'revision_window_days', 14)))->format('j F Y'),
            'revision_number' => (string) ($revision?->number ?? ''),
        ];

        $email = $this->mailer->send(
            $revision ? EmailTemplateKey::RevisionCompleted : EmailTemplateKey::DocumentReady,
            $order->email,
            $variables,
            $order,
            $this->attachments($version),
            ['purpose' => 'delivery', 'document_version_id' => $version->id, 'revision_id' => $revision?->id],
        );

        if (! $email) {
            throw new RuntimeException('Delivery email template is disabled.');
        }

        return $email;
    }

    /** Called by SendEmailMessage once the provider accepted a delivery email. */
    public function markDelivered(EmailMessage $email): void
    {
        $order = $email->order;
        if (! $order) {
            return;
        }

        $firstDelivery = $order->delivered_at === null;

        DB::transaction(function () use ($email, $order) {
            $version = DocumentVersion::query()->find(data_get($email->meta, 'document_version_id'));
            if ($version) {
                $version->forceFill(['status' => 'final'])->save();
                $version->document?->forceFill(['status' => 'delivered', 'current_version_id' => $version->id])->save();
            }

            if ($revisionId = data_get($email->meta, 'revision_id')) {
                Revision::query()->whereKey($revisionId)->update([
                    'status' => RevisionStatus::Completed->value,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            $order->refresh();
            if (in_array($order->status, [OrderStatus::DeliveryPending, OrderStatus::DeliveryFailed, OrderStatus::ManualReview], true)) {
                $this->states->transition($order, OrderStatus::Delivered, 'system', reason: 'Delivery email accepted by provider');
            }

            if ($order->revision_deadline_at === null) {
                $days = (int) data_get($order->service_snapshot, 'revision_window_days', 14);
                $order->forceFill([
                    'revision_deadline_at' => now()->addDays($days),
                    'retention_until' => now()->addDays((int) Settings::get('orders.retention_days', 90)),
                ])->save();
            }
        });

        if ($firstDelivery && ! data_get($email->meta, 'revision_id')) {
            $this->notifier->documentReady($order);
        }
    }

    /** Called when every send attempt for a delivery email failed. */
    public function markFailed(EmailMessage $email, string $reason): void
    {
        $order = $email->order;
        if (! $order) {
            return;
        }

        if (! data_get($email->meta, 'revision_id')) {
            $this->states->transitionIfAllowed($order, OrderStatus::DeliveryFailed, 'system', 'Delivery email failed: '.mb_substr($reason, 0, 180));
        }

        $this->notifier->deliveryFailed($order, 'The document email to '.$order->email.' could not be sent after several attempts: '.mb_substr($reason, 0, 300));
    }

    /** Admin "resend": deliver the current version again. */
    public function resend(Order $order): EmailMessage
    {
        $version = $order->documentVersions()->whereNotNull('pdf_path')->where('qa_status', 'passed')->first();
        if (! $version) {
            throw new RuntimeException('There is no validated document to send for this order.');
        }

        Audit::log('order.document_resent', $order, meta: ['document_version' => $version->uuid]);

        return $this->deliver($order, $version);
    }

    /** @return list<array{disk:string,path:string,encrypted:bool,filename:string,mime:string,size:int}> */
    private function attachments(DocumentVersion $version): array
    {
        if (! Settings::get('email.attach_documents', true)) {
            return [];
        }

        $limit = (int) config('statementra.email.max_attachment_mb', 15) * 1048576;
        if (((int) $version->pdf_size + (int) $version->docx_size) > $limit) {
            return []; // too large to attach: the email still links to the secure download page
        }

        return [
            [
                'disk' => $version->files_disk,
                'path' => $version->pdf_path,
                'encrypted' => $version->files_encrypted,
                'filename' => $version->pdf_filename ?: 'Statementra_Document.pdf',
                'mime' => 'application/pdf',
                'size' => (int) $version->pdf_size,
            ],
            [
                'disk' => $version->files_disk,
                'path' => $version->docx_path,
                'encrypted' => $version->files_encrypted,
                'filename' => $version->docx_filename ?: 'Statementra_Document.docx',
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'size' => (int) $version->docx_size,
            ],
        ];
    }
}
