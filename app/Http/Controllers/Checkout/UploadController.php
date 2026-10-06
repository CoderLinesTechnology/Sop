<?php

namespace App\Http\Controllers\Checkout;

use App\Domain\Files\UploadRejected;
use App\Domain\Files\UploadService;
use App\Domain\Orders\CheckoutSession;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asynchronous uploads from the application form. Files belong to the
 * browser's checkout session until the draft order is created; only that
 * browser can list or remove them.
 */
class UploadController extends Controller
{
    public function store(Request $request, UploadService $uploads, string $slug): JsonResponse
    {
        $service = Service::query()->active()->where('slug', $slug)->firstOrFail();

        $request->validate([
            'file' => ['required', 'file'],
            'slot' => ['nullable', 'string', 'max:60'],
        ]);

        /** @var ServiceField|null $field */
        $field = $service->activeFields()->where('type', 'file')->where('key', (string) $request->input('slot'))->first()
            ?? $service->activeFields()->where('type', 'file')->where('key', 'other')->first();

        try {
            $upload = $uploads->storeForDraft($request->file('file'), $request, $service, $field);
        } catch (UploadRejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'uuid' => $upload->uuid,
            'name' => $upload->original_name,
            'size' => $upload->humanSize(),
            'slot' => $upload->field_key ?: 'other',
            'purpose' => $upload->purposeLabel(),
        ], 201);
    }

    public function destroy(Request $request, UploadService $uploads, string $uuid): JsonResponse
    {
        $upload = $uploads->findOwned($request, $uuid);

        // Files already attached to a draft order can be removed by the same browser before payment.
        if (! $upload) {
            $candidate = \App\Models\UploadedFile::query()->where('uuid', $uuid)->whereNotNull('order_id')->first();
            $order = $candidate ? Order::query()->find($candidate->order_id) : null;
            if ($order && CheckoutSession::owns($request, $order) && $order->status->isPrePayment()) {
                $upload = $candidate;
            }
        }

        abort_if($upload === null, 404);

        $uploads->delete($upload);

        return response()->json(['deleted' => true]);
    }
}
