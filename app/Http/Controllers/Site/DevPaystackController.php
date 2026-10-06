<?php

namespace App\Http\Controllers\Site;

use App\Domain\Payments\Paystack\MockPaystackGateway;
use App\Domain\Payments\Paystack\PaystackGateway;
use App\Http\Controllers\Controller;
use App\Support\Money;
use App\Support\Runtime\AfterResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Development only (PAYSTACK_MODE=mock, never in production): a stand-in for
 * Paystack's hosted checkout so the full payment → webhook → verification →
 * processing flow can be exercised locally and in end-to-end tests.
 */
class DevPaystackController extends Controller
{
    public function show(PaystackGateway $gateway, string $reference): View
    {
        abort_unless($gateway instanceof MockPaystackGateway, 404);
        $transaction = $gateway->verify($reference);

        return view('dev.paystack-checkout', [
            'reference' => $reference,
            'amount' => Money::format((int) $transaction['amount'], $transaction['currency']),
            'email' => data_get($transaction, 'customer.email'),
        ]);
    }

    public function complete(Request $request, PaystackGateway $gateway, string $reference): RedirectResponse
    {
        abort_unless($gateway instanceof MockPaystackGateway, 404);

        $success = $request->input('outcome') === 'success';
        $transaction = $gateway->complete($reference, $success);

        if ($success) {
            // Like Paystack, deliver a signed webhook shortly after the redirect.
            AfterResponse::run('mock-paystack-webhook:'.$reference, fn () => $gateway->deliverWebhook('charge.success', $transaction), timeLimitSeconds: 60);
        }

        return redirect()->away($transaction['callback_url'].'?reference='.urlencode($reference).'&trxref='.urlencode($reference));
    }
}
