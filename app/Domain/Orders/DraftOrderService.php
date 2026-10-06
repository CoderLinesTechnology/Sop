<?php

namespace App\Domain\Orders;

use App\Domain\Pricing\PriceCalculator;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\Service;
use App\Models\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or updates the customer's draft order when they continue to the
 * review step. The draft is bound to the browser (checkout cookie), carries a
 * snapshot of the service's policies, and is priced by the server.
 */
class DraftOrderService
{
    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly OrderStateMachine $states,
    ) {}

    /**
     * @param  array<string, mixed>  $answers  validated answers keyed by field key
     * @param  list<string>  $uploadIds  UUIDs of uploads made by this browser
     */
    public function save(Service $service, OrderForm $form, array $answers, array $uploadIds, Request $request, ?Order $existing = null): Order
    {
        $normalized = $form->normalize($answers);
        $mapped = $normalized['mapped'];
        $quote = $this->prices->quote($service);
        [$deliveryMin, $deliveryMax] = $service->deliveryWindow();

        return DB::transaction(function () use ($service, $normalized, $mapped, $quote, $deliveryMin, $deliveryMax, $uploadIds, $request, $existing) {
            $order = $existing ?? new Order;

            $order->fill([
                'service_id' => $service->id,
                'email' => (string) ($mapped['email'] ?? $order->email ?? ''),
                'customer_name' => $mapped['customer_name'] ?? null,
                'customer_phone' => $mapped['customer_phone'] ?? null,
                'applicant_name' => $mapped['customer_name'] ?? null,
                'institution' => $mapped['institution'] ?? null,
                'programme' => $mapped['programme'] ?? null,
                'degree_level' => isset($mapped['degree_level']) ? Str::limit((string) $mapped['degree_level'], 60, '') : null,
                'country_code' => $mapped['country_code'] ?? null,
                'intake' => isset($mapped['intake']) ? Str::limit((string) $mapped['intake'], 60, '') : null,
                'deadline' => $mapped['deadline'] ?? null,
                'essay_prompt' => $mapped['essay_prompt'] ?? null,
                'word_limit' => isset($mapped['word_limit']) ? (int) $mapped['word_limit'] : null,
                'currency' => $quote->currency,
                'subtotal_amount' => $quote->baseAmount,
                'promotion_id' => $quote->promotion?->id,
                'promotion_discount' => $quote->promotionDiscount,
                'total_amount' => $quote->total,
                'pricing_snapshot' => $quote->toSnapshot(),
                'service_snapshot' => [
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'document_kind' => $service->document_kind,
                    'delivery_min_minutes' => $deliveryMin,
                    'delivery_max_minutes' => $deliveryMax,
                    'revisions_included' => $service->revisions_included,
                    'revision_window_days' => $service->revision_window_days,
                    'revision_fee' => $service->revision_fee,
                    'revision_mode' => $service->revision_mode,
                    'default_word_limit' => $service->default_word_limit,
                    'ai_workflow_id' => $service->ai_workflow_id,
                    'document_template_id' => $service->document_template_id,
                ],
                'checkout_token_hash' => CheckoutSession::hash($request),
                'revisions_allowed' => $service->revisions_included,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'utm' => array_filter((array) $request->session()->get('utm', [])) ?: null,
            ]);

            // A changed draft invalidates any coupon/payment session started earlier.
            if ($order->exists) {
                $order->forceFill(['coupon_id' => null, 'coupon_code' => null, 'coupon_discount' => 0, 'payment_reference' => null]);
                if ($order->payment_status !== PaymentStatus::Paid) {
                    $order->payment_status = PaymentStatus::Unpaid;
                }
            }

            $order->save();

            if ($order->status === OrderStatus::New) {
                $this->states->transition($order, OrderStatus::FormSubmitted, 'customer');
            } elseif (in_array($order->status, [OrderStatus::PaymentPending, OrderStatus::PaymentFailed, OrderStatus::PaymentExpired], true)) {
                $this->states->transition($order, OrderStatus::FormSubmitted, 'customer', reason: 'Customer edited the application');
            }

            $this->syncAnswers($order, $normalized['answers']);
            $this->attachUploads($order, $uploadIds, CheckoutSession::hash($request));

            return $order->refresh();
        });
    }

    /** @param array<string, mixed> $answers */
    private function syncAnswers(Order $order, array $answers): void
    {
        $fields = $order->service->activeFields()->get()->keyBy('key');

        $order->answers()
            ->whereNotIn('field_key', array_keys($answers))
            ->where('field_key', 'not like', 'followup\_%')
            ->delete();

        foreach ($answers as $key => $value) {
            $field = $fields->get($key);
            if (! $field) {
                continue;
            }

            OrderAnswer::query()->updateOrCreate(
                ['order_id' => $order->id, 'field_key' => $key],
                ['label' => $field->label, 'type' => $field->type->value, 'section' => $field->section->value, 'value' => $value],
            );
        }
    }

    /** @param list<string> $uploadIds */
    private function attachUploads(Order $order, array $uploadIds, string $draftHash): void
    {
        $uploadIds = array_values(array_filter($uploadIds, fn ($id) => is_string($id) && Str::isUuid($id)));
        if ($uploadIds === []) {
            return;
        }

        UploadedFile::query()
            ->whereIn('uuid', $uploadIds)
            ->where('draft_token_hash', $draftHash)
            ->where(fn ($q) => $q->whereNull('order_id')->orWhere('order_id', $order->id))
            ->update(['order_id' => $order->id, 'attached_at' => now(), 'expires_at' => null, 'updated_at' => now()]);
    }
}
