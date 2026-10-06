<?php

namespace App\Http\Controllers\Site;

use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Enums\EmailTemplateKey;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Seo;
use App\Support\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Find my order": emails a fresh secure link when the email address and
 * order reference match. The response is identical either way, so it cannot
 * be used to discover orders.
 */
class OrderLookupController extends Controller
{
    public function show(): View
    {
        return view('site.orders.lookup', ['seo' => Seo::make('Find my order', index: false)]);
    }

    public function send(Request $request, TransactionalMailer $mailer): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'reference' => ['required', 'string', 'max:20'],
        ]);

        if (! SpamGuard::isBot($request)) {
            $order = Order::query()
                ->where('reference', strtoupper(trim($data['reference'])))
                ->where('email', strtolower(trim($data['email'])))
                ->submitted()
                ->first();

            if ($order) {
                $mailer->send(EmailTemplateKey::OrderLink, $order->email, OrderEmailVariables::for($order), $order);
            }
        }

        return back()->with('status', 'If the details match an order, we have emailed a secure link to that address. It may take a minute to arrive.');
    }
}
