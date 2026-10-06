<?php

namespace App\Domain\Maintenance;

use App\Domain\Files\FileVault;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\UploadedFile;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Heartbeat task: removes what abandoned checkouts leave behind.
 *
 *  - Drafts that never reached payment (no payment record at all) are deleted
 *    with their answers and uploads after orders.draft_expiry_hours.
 *  - Uploads never attached to an order expire after the same period.
 *  - Files of uploads the customer removed are deleted for good.
 *
 * Drafts with a payment attempt are kept for the payment audit trail; their
 * personal content is erased later by PurgeExpiredOrderData.
 */
class PruneAbandonedDrafts
{
    public function __construct(private readonly FileVault $vault) {}

    public function __invoke(): void
    {
        $cutoff = now()->subHours(max(1, (int) Settings::get('orders.draft_expiry_hours', 48)));

        Order::query()
            ->whereIn('status', [OrderStatus::New->value, OrderStatus::FormSubmitted->value])
            ->where('payment_status', PaymentStatus::Unpaid->value)
            ->whereNull('fulfillment_started_at')
            ->whereDoesntHave('payments')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (Order $order) {
                $this->deleteUploads(UploadedFile::withTrashed()->where('order_id', $order->id)->get());
                $order->delete();
            });

        $this->deleteUploads(UploadedFile::withTrashed()
            ->whereNull('order_id')
            ->where(fn ($q) => $q->where('expires_at', '<', now())->orWhere(fn ($q) => $q->whereNull('expires_at')->where('created_at', '<', $cutoff)))
            ->orderBy('id')->limit(500)->get());

        $this->deleteUploads(UploadedFile::onlyTrashed()->whereNotNull('path')->orderBy('id')->limit(500)->get());
    }

    /** @param  Collection<int, UploadedFile>  $uploads */
    private function deleteUploads(Collection $uploads): void
    {
        foreach ($uploads as $upload) {
            try {
                $this->vault->delete($upload->path, $upload->disk);
                $upload->forceDelete();
            } catch (Throwable $e) {
                Log::warning('Could not delete expired upload', ['upload' => $upload->uuid, 'error' => $e->getMessage()]);
            }
        }
    }
}
