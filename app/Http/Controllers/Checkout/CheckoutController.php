<?php

namespace App\Http\Controllers\Checkout;

use App\Domain\Orders\CheckoutSession;
use App\Domain\Orders\OrderAccess;
use App\Domain\Orders\OrderForm;
use App\Domain\Payments\CheckoutException;
use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\ConfirmationOutcome;
use App\Domain\Payments\PaymentConfirmationService;
use App\Domain\Payments\Paystack\PaystackException;
use App\Domain\Pricing\PriceCalculator;
use App\Enums\FieldSection;
use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Analytics;
use App\Support\Countries;
use App\Support\SecurityLog;
use App\Support\Seo;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Steps 2–4: review, payment and the return from Paystack.
 *
 * Draft orders are only reachable from the browser that created them. The
 * total shown here and the amount charged are both computed on the server.
 * Returning from Paystack never marks anything as paid by itself: the
 * callback asks Paystack's API to verify the transaction, exactly like the
 * webhook does, and the first one to succeed wins (idempotently).
 */
class CheckoutController extends Controller
{
    public function review(Request $request, PriceCalculator $prices, string $reference): View|RedirectResponse
    {
        $order = $this->draft($request, $reference);
        if ($order instanceof RedirectResponse) {
            return $order;
        }

        $order->load(['service', 'answers', 'files']);

        return view('site.checkout.review', [
            'order' => $order,
            'sections' => $this->answerSections($order),
            'quote' => $prices->quote($order->service, $request->session()->get('checkout.coupon'), $order->email, $order),
            'countryName' => Countries::name($order->country_code),
            'seo' => Seo::make('Review your application', index: false),
        ]);
    }

    public function payment(Request $request, PriceCalculator $prices, string $reference): View|RedirectResponse
    {
        $order = $this->draft($request, $reference);
        if ($order instanceof RedirectResponse) {
            return $order;
        }

        $order->load(['service', 'files']);
        $coupon = $request->session()->get('checkout.coupon', $order->coupon_code);

        return view('site.checkout.payment', [
            'order' => $order,
            'quote' => $prices->quote($order->service, $coupon, $order->email, $order),
            'coupon' => $coupon,
            'countryName' => Countries::name($order->country_code),
            'paymentError' => $request->session()->get('payment_error'),
            'seo' => Seo::make('Secure payment', index: false),
        ]);
    }

    /** Live price for the payment page (coupon preview). Never trusted at payment time. */
    public function quote(Request $request, PriceCalculator $prices, string $reference): JsonResponse
    {
        $order = $this->draft($request, $reference);
        if ($order instanceof RedirectResponse) {
            return response()->json(['message' => 'Your session has expired. Please start again.'], 409);
        }

        $data = $request->validate([
            'coupon' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
        ]);

        $key = 'coupon-failures:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, (int) Settings::get('security.rate_limit_coupon_attempts_per_hour', 15))) {
            return response()->json(['message' => 'Too many coupon attempts. Please try again later.'], 429);
        }

        $quote = $prices->quote($order->service, $data['coupon'] ?? null, $data['email'] ?? $order->email, $order);

        if (! empty($data['coupon'])) {
            if ($quote->couponStatus === 'invalid') {
                RateLimiter::hit($key, 3600);
                if (RateLimiter::attempts($key) === 10) {
                    SecurityLog::record('coupon_bruteforce', 'high', ['attempts' => 10]);
                }
                $request->session()->forget('checkout.coupon');
            } else {
                $request->session()->put('checkout.coupon', $quote->couponCode ?? $data['coupon']);
                if ($quote->couponApplied()) {
                    Analytics::record(AnalyticsEvent::COUPON_APPLIED, $request, ['service_id' => $order->service_id, 'order_id' => $order->id]);
                }
            }
        } else {
            $request->session()->forget('checkout.coupon');
        }

        return response()->json($quote->toPublicArray());
    }

    public function pay(Request $request, CheckoutService $checkout, string $reference): Response
    {
        $order = $this->draft($request, $reference);
        if ($order instanceof RedirectResponse) {
            return $order;
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'coupon' => ['nullable', 'string', 'max:40'],
            'confirm_accuracy' => ['accepted'],
            'create_account' => ['nullable', 'boolean'],
        ], [
            'confirm_accuracy.accepted' => 'Please confirm that the information you provided is accurate.',
        ]);

        try {
            $result = $checkout->start($order, $data['email'], $data['coupon'] ?? null, (bool) ($data['create_account'] ?? false), $request);
        } catch (CheckoutException $e) {
            return back()->withInput()->withErrors([$e->field === 'coupon' ? 'coupon' : 'payment' => $e->getMessage()]);
        }

        $request->session()->forget('checkout.coupon');

        if ($result['free']) {
            OrderAccess::grant($request, $order->refresh());

            return redirect()->route('orders.show', $order->public_id);
        }

        // 303 so the browser follows with GET to Paystack's hosted checkout.
        return redirect()->away($result['redirect_url'], 303);
    }

    /** The customer returns from Paystack: verify server-to-server, then show the order. */
    public function callback(Request $request, PaymentConfirmationService $confirmations): View|RedirectResponse
    {
        $reference = (string) ($request->query('reference') ?? $request->query('trxref') ?? '');
        $payment = preg_match('/^[A-Za-z0-9._=-]{6,100}$/', $reference)
            ? Payment::query()->where('reference', $reference)->first()
            : null;

        if (! $payment) {
            return redirect()->route('home');
        }

        $order = $payment->order;
        $ownsCheckout = CheckoutSession::owns($request, $order) || OrderAccess::canAccess($request, $order);

        try {
            $outcome = $confirmations->confirmByReference($reference, 'callback');
        } catch (PaystackException) {
            $outcome = ConfirmationOutcome::Pending;
        }

        if ($outcome->isPaid()) {
            if (! $ownsCheckout) {
                // Paid, but this browser didn't start the checkout: the secure link is in the customer's email.
                return view('site.checkout.confirmed-elsewhere', ['seo' => Seo::make('Payment received', index: false)]);
            }

            OrderAccess::grant($request, $order);

            return redirect()->route($payment->purpose === 'revision' ? 'orders.show' : 'orders.show', $order->public_id);
        }

        if ($outcome === ConfirmationOutcome::Pending) {
            return view('site.checkout.confirming', [
                'retryUrl' => $request->fullUrl(),
                'seo' => Seo::make('Confirming your payment', index: false),
            ]);
        }

        if ($outcome === ConfirmationOutcome::Mismatch) {
            return view('site.checkout.confirmed-elsewhere', [
                'seo' => Seo::make('Payment received', index: false),
                'review' => true,
            ]);
        }

        if ($payment->purpose === 'revision' && $ownsCheckout) {
            OrderAccess::grant($request, $order);

            return redirect()->route('orders.show', $order->public_id)->with('status', 'Your payment was not completed, so the revision has not started.');
        }

        if (! $ownsCheckout) {
            return redirect()->route('home');
        }

        return redirect()
            ->route('checkout.payment', $order->reference)
            ->with('payment_error', "Your payment wasn't completed. No money was taken — please try again or use another payment method.");
    }

    /** The draft order for this browser, or a redirect if it is not (or no longer) payable here. */
    private function draft(Request $request, string $reference): Order|RedirectResponse
    {
        $order = Order::query()->where('reference', strtoupper($reference))->first();

        if (! $order || ! CheckoutSession::owns($request, $order)) {
            return redirect()->route('order.start')->with('status', 'Your session has expired. Please start your application again.');
        }

        if (! $order->status->isPrePayment()) {
            OrderAccess::grant($request, $order);

            return redirect()->route('orders.show', $order->public_id);
        }

        return $order;
    }

    /** @return array<string, array{title:string, icon:string, items:list<array{label:string,value:string}>}> */
    private function answerSections(Order $order): array
    {
        // Follow the form's field order and show option labels, not stored values.
        $fields = (new OrderForm($order->service))->inputFields()->keyBy('key');
        $position = $fields->keys()->flip();
        $answers = $order->answers->sortBy(fn ($answer) => $position[$answer->field_key] ?? PHP_INT_MAX)->values();

        $sections = [];
        foreach ($answers as $answer) {
            $section = $answer->section instanceof FieldSection ? $answer->section : FieldSection::Additional;
            $key = in_array($section, [FieldSection::Story, FieldSection::Additional], true) ? 'additional' : $section->value;
            $sections[$key] ??= ['title' => $section->reviewTitle(), 'icon' => $section->icon(), 'anchor' => $section->value, 'items' => []];

            $value = $answer->type === 'country' ? (Countries::name((string) $answer->value) ?? $answer->displayValue()) : $answer->displayValue();
            if ($answer->type === 'date' && $answer->value) {
                $value = Carbon::parse($answer->value)->format('j F Y');
            }

            $choices = $fields->get($answer->field_key)?->choices() ?? [];
            if ($choices !== []) {
                $value = implode(', ', array_map(fn ($v) => $choices[(string) $v] ?? (string) $v, (array) $answer->value));
            }

            $sections[$key]['items'][] = [
                'label' => $answer->label,
                'value' => $value,
                'long' => $answer->type === FieldType::Textarea->value || mb_strlen($value) > 80,
            ];
        }

        // Display order: personal, application, additional.
        return array_filter([
            'details' => $sections['details'] ?? null,
            'application' => $sections['application'] ?? null,
            'additional' => $sections['additional'] ?? null,
        ]);
    }
}
