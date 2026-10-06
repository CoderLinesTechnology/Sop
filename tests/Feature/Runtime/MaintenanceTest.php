<?php

use App\Domain\Maintenance\ProcessInformationRequests;
use App\Domain\Maintenance\PruneAbandonedDrafts;
use App\Domain\Maintenance\PurgeExpiredOrderData;
use App\Domain\Orders\OrderAccess;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EmailMessage;
use App\Models\InformationRequest;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\Payment;
use App\Models\UploadedFile;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function storedUpload(?Order $order, array $attributes = []): UploadedFile
{
    $path = 'uploads/'.Str::uuid().'.bin';
    Storage::disk('private')->put($path, 'encrypted bytes');

    return UploadedFile::query()->create(array_merge([
        'uuid' => (string) Str::uuid(),
        'order_id' => $order?->id,
        'original_name' => 'ama-mensah-cv.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 15,
        'sha256' => hash('sha256', 'encrypted bytes'),
        'disk' => 'private',
        'path' => $path,
    ], $attributes));
}

function runTask(string $class): void
{
    app()->call([app($class), '__invoke']);
}

it('deletes abandoned drafts and their files, but keeps drafts with payment attempts', function () {
    Settings::set('orders.draft_expiry_hours', 48);

    $abandoned = Order::factory()->create(['status' => OrderStatus::FormSubmitted->value]);
    $abandonedFile = storedUpload($abandoned);
    $attempted = Order::factory()->create(['status' => OrderStatus::FormSubmitted->value]);
    Payment::query()->create(['order_id' => $attempted->id, 'purpose' => 'order', 'provider' => 'paystack',
        'reference' => 'STX-ATTEMPT-1', 'amount' => 8900, 'currency' => 'USD', 'status' => 'abandoned']);
    $orphan = storedUpload(null, ['created_at' => now()]);

    $this->travel(49)->hours();
    $recent = Order::factory()->create(['status' => OrderStatus::FormSubmitted->value]);

    runTask(PruneAbandonedDrafts::class);

    expect(Order::query()->find($abandoned->id))->toBeNull()
        ->and(Order::query()->find($attempted->id))->not->toBeNull()
        ->and(Order::query()->find($recent->id))->not->toBeNull()
        ->and(UploadedFile::withTrashed()->whereKey([$abandonedFile->id, $orphan->id])->count())->toBe(0);
    Storage::disk('private')->assertMissing($abandonedFile->path);
    Storage::disk('private')->assertMissing($orphan->path);
});

it('erases personal content once the retention period ends, and revokes links', function () {
    $order = Order::factory()->paid(OrderStatus::Delivered)->create([
        'delivered_at' => now(), 'retention_until' => now()->addDays(90), 'customer_phone' => '+233 24 123 4567',
    ]);
    $upload = storedUpload($order);
    OrderAnswer::query()->create(['order_id' => $order->id, 'field_key' => 'why_field', 'label' => 'Why this field?', 'type' => 'textarea', 'section' => 'story', 'value' => 'My grandmother ran a market stall in Kejetia.']);
    Storage::disk('private')->put('documents/final.pdf', '%PDF-1.7');
    $document = Document::query()->create(['order_id' => $order->id, 'kind' => 'personal_statement', 'title' => 'Personal Statement', 'status' => 'delivered']);
    $version = DocumentVersion::query()->create(['document_id' => $document->id, 'order_id' => $order->id, 'version_number' => 1, 'source' => 'ai', 'status' => 'delivered',
        'content' => ['blocks' => []], 'plain_text' => 'My grandmother ran a market stall...', 'files_disk' => 'private', 'pdf_path' => 'documents/final.pdf']);
    $active = Order::factory()->paid(OrderStatus::Writing)->create(['updated_at' => now()->subYear()]);
    $link = OrderAccess::statusUrl($order);

    runTask(PurgeExpiredOrderData::class);
    expect($order->refresh()->data_purged_at)->toBeNull(); // still within retention

    $this->travel(91)->days();
    runTask(PurgeExpiredOrderData::class);

    $order->refresh();
    expect($order->data_purged_at)->not->toBeNull()
        ->and($order->customer_phone)->toBeNull()
        ->and($order->reference)->not->toBeEmpty()
        ->and($order->total_amount)->toBe(8900)
        ->and(OrderAnswer::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and(UploadedFile::withTrashed()->whereKey($upload->id)->exists())->toBeFalse()
        ->and($version->refresh()->plain_text)->toBeNull()
        ->and($version->pdf_path)->toBeNull()
        ->and($active->refresh()->data_purged_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'order.data_purged')->count())->toBe(1);
    Storage::disk('private')->assertMissing($upload->path);
    Storage::disk('private')->assertMissing('documents/final.pdf');

    $this->get($link)->assertForbidden();
});

it('reminds once about unanswered questions, then continues when they expire', function () {
    Settings::set('orders.needs_info_reminder_hours', 24);
    Settings::set('orders.needs_info_timeout_action', 'manual_review');
    $order = Order::factory()->paid(OrderStatus::NeedsInformation)->create();
    $request = InformationRequest::query()->create([
        'order_id' => $order->id, 'source' => 'ai', 'status' => 'open',
        'questions' => [['key' => 'q1', 'question' => 'Which modules interest you most?']],
        'requested_at' => now(), 'expires_at' => now()->addHours(72),
    ]);

    $this->travel(25)->hours();
    runTask(ProcessInformationRequests::class);
    runTask(ProcessInformationRequests::class);

    expect($request->refresh()->reminder_sent_at)->not->toBeNull()
        ->and(EmailMessage::query()->where('order_id', $order->id)->where('template_key', 'information_reminder')->count())->toBe(1);

    $this->travel(48)->hours();
    runTask(ProcessInformationRequests::class);

    expect($request->refresh()->status)->toBe('expired')
        ->and($order->refresh()->status)->toBe(OrderStatus::ManualReview);
});
