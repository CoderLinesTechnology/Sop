<?php

use App\Domain\Delivery\DocumentDelivery;
use App\Domain\Email\EmailSender;
use App\Domain\Files\FileVault;
use App\Enums\EmailStatus;
use App\Enums\OrderStatus;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EmailMessage;
use App\Models\Order;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/** A mail transport that can be switched off to simulate a provider outage. */
class SwitchableTransport extends AbstractTransport
{
    public static bool $down = false;

    public static int $sent = 0;

    protected function doSend(SentMessage $message): void
    {
        if (self::$down) {
            throw new TransportException('Mailbox unavailable');
        }
        self::$sent++;
    }

    public function __toString(): string
    {
        return 'switchable://';
    }
}

/** A finished, QA-passed document version with encrypted PDF and DOCX files. */
function finishedVersion(Order $order): DocumentVersion
{
    $vault = app(FileVault::class);
    $pdf = $vault->put('documents', '%PDF-1.7 personal statement', 'pdf');
    $docx = $vault->put('documents', 'PK docx personal statement', 'docx');
    $document = Document::query()->create(['order_id' => $order->id, 'kind' => 'personal_statement', 'title' => 'Personal Statement', 'status' => 'ready']);

    return DocumentVersion::query()->create([
        'document_id' => $document->id, 'order_id' => $order->id, 'version_number' => 1, 'source' => 'ai', 'status' => 'ready',
        'files_disk' => $pdf['disk'], 'files_encrypted' => true,
        'pdf_path' => $pdf['path'], 'pdf_size' => $pdf['size'], 'pdf_filename' => 'Ama_Mensah_Personal_Statement.pdf',
        'docx_path' => $docx['path'], 'docx_size' => $docx['size'], 'docx_filename' => 'Ama_Mensah_Personal_Statement.docx',
        'qa_status' => 'passed',
    ]);
}

beforeEach(fn () => $this->seed(EmailTemplateSeeder::class));

it('emails both files and marks the order delivered once the provider accepts the message', function () {
    $order = Order::factory()->paid(OrderStatus::FinalReview)->create(['email' => 'ama@example.com']);
    $version = finishedVersion($order);

    $email = app(DocumentDelivery::class)->deliver($order, $version);

    expect($email->refresh()->status)->toBe(EmailStatus::Sent);

    /** @var Email $sent */
    $sent = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    $attachments = collect($sent->getAttachments())->mapWithKeys(fn ($part) => [$part->getFilename() => $part->getBody()]);
    expect($sent->getTo()[0]->getAddress())->toBe('ama@example.com')
        ->and($attachments->keys()->all())->toBe(['Ama_Mensah_Personal_Statement.pdf', 'Ama_Mensah_Personal_Statement.docx'])
        ->and($attachments['Ama_Mensah_Personal_Statement.pdf'])->toBe('%PDF-1.7 personal statement');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->delivered_at)->not->toBeNull()
        ->and($order->retention_until)->not->toBeNull();
});

it('marks the delivery failed after the last attempt, and resends on request', function () {
    Mail::extend('switchable', fn () => new SwitchableTransport);
    config(['mail.mailers.switchable' => ['transport' => 'switchable'], 'mail.default' => 'switchable']);
    SwitchableTransport::$down = true;
    SwitchableTransport::$sent = 0;

    $order = Order::factory()->paid(OrderStatus::FinalReview)->create();
    $version = finishedVersion($order);

    $email = app(DocumentDelivery::class)->deliver($order, $version);
    $email->refresh()->forceFill(['attempts' => 4, 'next_attempt_at' => null])->save();
    app(EmailSender::class)->send($email->id);

    expect($email->refresh()->status)->toBe(EmailStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::DeliveryFailed);

    SwitchableTransport::$down = false;
    app(DocumentDelivery::class)->resend($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and(SwitchableTransport::$sent)->toBe(1)
        ->and(EmailMessage::query()->where('order_id', $order->id)->where('status', EmailStatus::Sent->value)->count())->toBe(1);
});

/** The version came from an AI job whose strategy proposed these points. */
function versionWithProposals(Order $order, array $proposals): DocumentVersion
{
    $job = AiJob::query()->create(['order_id' => $order->id, 'kind' => AiJob::KIND_ORDER, 'dedupe_key' => 'test:'.$order->id, 'status' => 'completed', 'workflow_snapshot' => [], 'provider' => 'fake']);
    AiJobStep::query()->create(['ai_job_id' => $job->id, 'stage' => 'strategy', 'sequence' => 1, 'attempt' => 1, 'status' => 'completed', 'output' => ['strategy' => ['proposals_to_confirm' => $proposals]]]);
    $version = finishedVersion($order);
    $version->forceFill(['ai_job_id' => $job->id])->save();

    return $version;
}

it('lists the choices we made for the customer in the delivery email', function () {
    $order = Order::factory()->paid(OrderStatus::FinalReview)->create(['email' => 'ama@example.com']);
    $version = versionWithProposals($order, ['We chose the Vision Lab\'s waste-sorting project as your research focus because of your MyClean app.']);

    app(DocumentDelivery::class)->deliver($order, $version);

    $html = (string) app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHtmlBody();
    expect($html)->toContain('Before you submit, please check these choices we made for you')
        ->toContain('waste-sorting project as your research focus');
});

it('leaves the check section out when everything came from the customer', function () {
    $order = Order::factory()->paid(OrderStatus::FinalReview)->create(['email' => 'ama@example.com']);

    app(DocumentDelivery::class)->deliver($order, versionWithProposals($order, []));

    $html = (string) app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHtmlBody();
    expect($html)->not->toContain('Before you submit')->not->toContain('{{section');
});
