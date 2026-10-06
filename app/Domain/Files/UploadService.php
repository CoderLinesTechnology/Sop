<?php

namespace App\Domain\Files;

use App\Domain\Orders\CheckoutSession;
use App\Enums\ExtractionStatus;
use App\Enums\FileScanStatus;
use App\Jobs\ExtractUploadText;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceField;
use App\Models\UploadedFile;
use App\Support\SecurityLog;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Accepts a customer upload: validate → scan → encrypt → store privately →
 * queue text extraction. Uploads made before payment belong to the browser's
 * checkout session and are attached to the order when it is created.
 */
final class UploadService
{
    public function __construct(
        private readonly UploadValidator $validator,
        private readonly MalwareScanner $scanner,
        private readonly FileVault $vault,
    ) {}

    /** @throws UploadRejected */
    public function storeForDraft(HttpUploadedFile $file, Request $request, Service $service, ?ServiceField $field = null, ?string $purpose = null): UploadedFile
    {
        $draftHash = CheckoutSession::hash($request);

        $existing = UploadedFile::query()->where('draft_token_hash', $draftHash)->whereNull('order_id')->count();
        if ($existing >= (int) Settings::get('orders.max_files_per_order', 10)) {
            throw new UploadRejected('You have reached the maximum number of files for one application.', 'too_many');
        }

        $upload = $this->store($file, $request, $service, $field, $purpose);
        $upload->forceFill([
            'draft_token_hash' => $draftHash,
            'expires_at' => now()->addHours((int) Settings::get('orders.draft_expiry_hours', 48)),
        ])->save();

        return $upload;
    }

    /** @throws UploadRejected */
    public function storeForOrder(HttpUploadedFile $file, Request $request, Order $order, ?ServiceField $field = null, ?string $purpose = null): UploadedFile
    {
        $upload = $this->store($file, $request, $order->service, $field, $purpose);
        $upload->forceFill(['order_id' => $order->id, 'attached_at' => now()])->save();

        return $upload;
    }

    /** @throws UploadRejected */
    private function store(HttpUploadedFile $file, Request $request, Service $service, ?ServiceField $field, ?string $purpose): UploadedFile
    {
        $allowed = $field ? $field->acceptedExtensions() : Settings::allowedUploadExtensions();

        try {
            $clean = $this->validator->validate($file, $allowed);
        } catch (UploadRejected $e) {
            if (in_array($e->reason, ['content_mismatch', 'docx_macro', 'docx_bomb', 'docx_dtd', 'docx_path'], true)) {
                SecurityLog::record('upload_rejected', 'medium', ['reason' => $e->reason, 'name' => mb_substr((string) $file->getClientOriginalName(), 0, 120)]);
            }
            throw $e;
        }

        $scan = $this->scanner->scan($clean['contents']);
        if ($scan['status'] === 'infected') {
            SecurityLog::record('malware_upload', 'high', ['signature' => $scan['result'], 'name' => $clean['name']]);
            throw new UploadRejected('This file was blocked by our security scan.', 'infected');
        }
        if ($scan['status'] === 'error' && config('statementra.scanning.fail_closed')) {
            throw new UploadRejected('We could not scan this file right now. Please try again in a moment.', 'scan_unavailable');
        }

        $stored = $this->vault->put('uploads', $clean['contents'], $clean['extension']);

        $upload = UploadedFile::query()->create([
            'uuid' => (string) Str::uuid(),
            'service_id' => $service->id,
            'field_key' => $field?->key,
            'purpose' => $purpose ?? $field?->uploadPurpose() ?? 'other',
            'original_name' => $clean['name'],
            'extension' => $clean['extension'],
            'mime_type' => $clean['mime'],
            'size_bytes' => $stored['size'],
            'sha256' => $stored['sha256'],
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'is_encrypted' => true,
            'encryption_key_id' => $stored['key_id'],
            'scan_status' => FileScanStatus::from($scan['status'] === 'error' ? 'error' : $scan['status']),
            'scan_engine' => $scan['engine'],
            'scan_result' => $scan['result'],
            'scanned_at' => $scan['status'] === 'skipped' ? null : now(),
            'extraction_status' => ExtractionStatus::Pending,
            'uploaded_ip' => $request->ip(),
        ]);

        ExtractUploadText::dispatch($upload->id);

        return $upload;
    }

    /** Uploads belonging to the current browser's checkout session (not yet attached). */
    public function draftUploads(Request $request): Collection
    {
        $hash = CheckoutSession::existingHash($request);
        if (! $hash) {
            return collect();
        }

        return UploadedFile::query()
            ->where('draft_token_hash', $hash)
            ->whereNull('order_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')
            ->get();
    }

    /** Find an upload by UUID only if it belongs to this browser (draft) or to the given order. */
    public function findOwned(Request $request, string $uuid, ?Order $order = null): ?UploadedFile
    {
        if (! Str::isUuid($uuid)) {
            return null;
        }

        $upload = UploadedFile::query()->where('uuid', $uuid)->first();
        if (! $upload) {
            return null;
        }

        if ($order && $upload->order_id === $order->id) {
            return $upload;
        }

        $hash = CheckoutSession::existingHash($request);

        return $hash && $upload->draft_token_hash && hash_equals($upload->draft_token_hash, $hash) ? $upload : null;
    }

    public function delete(UploadedFile $upload): void
    {
        $this->vault->delete($upload->path, $upload->disk);
        $upload->forceFill(['extracted_text' => null])->save();
        $upload->delete();
    }
}
