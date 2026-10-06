<?php

namespace App\Domain\Orders;

use App\Domain\Files\FileVault;
use App\Models\AdminUser;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\UploadedFile;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Erases an order's personal content once its retention period ends (or on a
 * customer's erasure request): uploaded files, generated documents, answers,
 * the applicant profile, AI inputs/outputs and stored email bodies.
 *
 * What remains is the minimum needed for accounting and disputes: reference,
 * service, amounts, payment records, customer email and the status history.
 * Purging bumps access_version, so every outstanding order link stops working.
 */
class OrderDataPurger
{
    public function __construct(private readonly FileVault $vault) {}

    public function purge(Order $order, string $reason = 'retention', ?AdminUser $admin = null): void
    {
        if ($order->data_purged_at !== null) {
            return;
        }

        $files = [];

        DB::transaction(function () use ($order, &$files) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->data_purged_at !== null) {
                return;
            }

            UploadedFile::withTrashed()->where('order_id', $locked->id)->get()
                ->each(function (UploadedFile $upload) use (&$files) {
                    $files[] = [$upload->path, $upload->disk];
                    $upload->forceDelete();
                });

            DocumentVersion::query()->where('order_id', $locked->id)->get()
                ->each(function (DocumentVersion $version) use (&$files) {
                    foreach ([$version->pdf_path, $version->docx_path] as $path) {
                        if ($path) {
                            $files[] = [$path, $version->files_disk];
                        }
                    }
                    $version->forceFill([
                        'content' => null, 'plain_text' => null, 'title' => null,
                        'pdf_path' => null, 'docx_path' => null, 'pdf_sha256' => null, 'docx_sha256' => null,
                        'template_snapshot' => null, 'requirements_snapshot' => null, 'qa_results' => null, 'notes' => null,
                    ])->save();
                });

            $locked->answers()->delete();
            $locked->applicant()->delete();
            $locked->informationRequests()->update(['questions' => null, 'answers' => null]);
            $locked->revisions()->update(['request_text' => null, 'admin_note' => null]);
            $locked->emails()->update(['html_body' => null, 'text_body' => null, 'attachments' => null]);
            $locked->feedback()->update(['improve' => null]);

            DB::table('ai_job_steps')
                ->whereIn('ai_job_id', $locked->aiJobs()->select('id'))
                ->update(['input_summary' => null, 'output' => null]);

            $locked->forceFill([
                'customer_name' => null,
                'customer_phone' => null,
                'applicant_name' => null,
                'essay_prompt' => null,
                'ip_address' => null,
                'user_agent' => null,
                'checkout_token_hash' => null,
                'access_version' => $locked->access_version + 1,
                'data_purged_at' => now(),
            ])->save();
        });

        // Storage is not transactional: remove bytes only after the rows are gone.
        foreach ($files as [$path, $disk]) {
            try {
                $this->vault->delete($path, $disk);
            } catch (Throwable $e) {
                Log::warning('Purge: could not delete stored file', ['order' => $order->reference, 'error' => $e->getMessage()]);
            }
        }

        Audit::log('order.data_purged', $order->refresh(), meta: ['reason' => $reason, 'files_deleted' => count($files)], admin: $admin);
    }
}
