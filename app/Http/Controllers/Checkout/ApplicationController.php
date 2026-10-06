<?php

namespace App\Http\Controllers\Checkout;

use App\Domain\Files\UploadRejected;
use App\Domain\Files\UploadService;
use App\Domain\Orders\CheckoutSession;
use App\Domain\Orders\DraftOrderService;
use App\Domain\Orders\OrderForm;
use App\Domain\Pricing\PriceCalculator;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Order;
use App\Models\Service;
use App\Support\Analytics;
use App\Support\Countries;
use App\Support\Seo;
use App\Support\Settings;
use App\Support\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Step 1 — "Let's get started": the adaptive application form rendered from
 * the service's configuration. Continuing creates (or updates) a draft order
 * and goes to the review step.
 */
class ApplicationController extends Controller
{
    public function create(Request $request, PriceCalculator $prices, UploadService $uploads, ?string $slug = null): View|RedirectResponse
    {
        $services = Service::query()->active()->ordered()->get();
        abort_if($services->isEmpty(), 404);

        $editing = $this->editableOrder($request);
        $service = $slug
            ? $services->firstWhere('slug', $slug)
            : ($editing?->service ?? $services->firstWhere('is_featured', true) ?? $services->first());

        if (! $service) {
            return redirect()->route('order.start');
        }

        if ($coupon = $request->query('coupon')) {
            $request->session()->put('checkout.coupon', mb_substr(strip_tags((string) $coupon), 0, 40));
        }

        CheckoutSession::token($request);
        $form = new OrderForm($service->load('activeFields'));

        $values = $editing && $editing->service_id === $service->id
            ? $editing->answers->pluck('value', 'field_key')->all()
            : [];

        $existingUploads = $uploads->draftUploads($request);
        if ($editing) {
            $existingUploads = $existingUploads->concat($editing->files()->get())->unique('id');
        }

        return view('site.order.start', [
            'services' => $services,
            'service' => $service,
            'form' => $form,
            'values' => $values,
            'uploads' => $existingUploads->map(fn ($file) => [
                'uuid' => $file->uuid,
                'name' => $file->original_name,
                'size' => $file->humanSize(),
                'slot' => $file->field_key ?: 'other',
                'purpose' => $file->purposeLabel(),
            ])->values(),
            'editing' => $editing,
            'quote' => $prices->quote($service),
            'countries' => Countries::all(),
            'dialCodes' => Countries::DIAL_CODES,
            'defaultDialCountry' => Countries::defaultDialCountry($request->header('CF-IPCountry')),
            'maxUploadMb' => intdiv(Settings::maxUploadBytes(), 1048576),
            'seo' => Seo::make('Start your application — '.$service->name, $service->short_description, index: false),
        ]);
    }

    public function store(Request $request, DraftOrderService $drafts, UploadService $uploads, string $slug): RedirectResponse
    {
        $service = Service::query()->active()->where('slug', $slug)->with('activeFields')->firstOrFail();

        if (SpamGuard::isBot($request)) {
            return redirect()->route('order.start', $slug);
        }

        $form = new OrderForm($service);
        $editing = $this->editableOrder($request);

        // Files posted directly (no-JavaScript fallback) are stored like async uploads.
        $uploadIds = array_values(array_filter(Arr::flatten((array) $request->input('upload_ids', [])), 'is_string'));
        foreach ((array) $request->file('files', []) as $slot => $files) {
            $field = $form->fileFields()->firstWhere('key', $slot);
            foreach (Arr::wrap($files) as $file) {
                try {
                    $uploadIds[] = $uploads->storeForDraft($file, $request, $service, $field)->uuid;
                } catch (UploadRejected $e) {
                    throw ValidationException::withMessages(['files.'.$slot => $e->getMessage()]);
                }
            }
        }

        $uploadedSlots = $this->uploadedSlots($request, $uploadIds, $editing);
        $answers = (array) $request->input('answers', []);

        // Phone numbers are entered as dial code + number.
        foreach ($form->inputFields()->where('type', \App\Enums\FieldType::Phone) as $field) {
            $number = trim((string) ($answers[$field->key] ?? ''));
            $dial = Countries::DIAL_CODES[strtoupper((string) $request->input('dial_code.'.$field->key))][1] ?? null;
            if ($number !== '' && $dial && ! str_starts_with($number, '+')) {
                $answers[$field->key] = $dial.' '.ltrim($number, '0 ');
            }
        }
        $request->merge(['answers' => $answers]);

        $validated = $request->validate(
            $form->rules($answers, $uploadedSlots),
            ['answers.*.required' => 'Please fill in :attribute.'],
            $form->attributeNames(),
        );

        $order = $drafts->save($service, $form, (array) ($validated['answers'] ?? []), $uploadIds, $request, $editing);

        Analytics::record(AnalyticsEvent::FORM_COMPLETE, $request, ['service_id' => $service->id, 'order_id' => $order->id]);

        return redirect()->route('checkout.review', $order->reference);
    }

    /** A draft order owned by this browser that the customer is editing (?order=REF). */
    private function editableOrder(Request $request): ?Order
    {
        $reference = $request->input('order', $request->query('order'));
        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $order = Order::query()->where('reference', strtoupper($reference))->first();

        return $order && CheckoutSession::owns($request, $order) && $order->status->isPrePayment() ? $order : null;
    }

    /** @return list<string> slots (file field keys) that have at least one file */
    private function uploadedSlots(Request $request, array $uploadIds, ?Order $editing): array
    {
        $files = \App\Models\UploadedFile::query()
            ->whereIn('uuid', $uploadIds)
            ->where('draft_token_hash', CheckoutSession::existingHash($request))
            ->pluck('field_key')
            ->all();

        if ($editing) {
            $files = [...$files, ...$editing->files()->pluck('field_key')->all()];
        }

        return array_values(array_unique(array_filter($files)));
    }
}
