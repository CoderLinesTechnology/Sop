<?php

namespace App\Http\Controllers\Site;

use App\Domain\Catalogue\Catalogue;
use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Domain\Notifications\AdminNotifier;
use App\Enums\EmailTemplateKey;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Support\Seo;
use App\Support\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(Catalogue $catalogue): View
    {
        $page = $catalogue->page('contact');

        return view('site.pages.contact', [
            'page' => $page,
            'faqs' => $catalogue->faqs('general', 5),
            'seo' => Seo::make($page?->seo_title ?: 'Contact & Support', $page?->seo_description)
                ->withBreadcrumbs([['Home', url('/')], ['Contact', route('contact.show')]]),
        ]);
    }

    public function store(Request $request, TransactionalMailer $mailer, AdminNotifier $notifier): RedirectResponse
    {
        if (SpamGuard::isBot($request)) {
            return back()->with('status', "Thanks — we've received your message.");
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'order_reference' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $reference = isset($data['order_reference']) ? strtoupper(trim($data['order_reference'])) : null;
        // Only link the order when the reference AND email match, so references can't be probed.
        $order = $reference ? Order::query()->where('reference', $reference)->where('email', strtolower($data['email']))->first() : null;

        $message = ContactMessage::query()->create([
            'name' => strip_tags($data['name']),
            'email' => strtolower($data['email']),
            'order_reference' => $reference,
            'order_id' => $order?->id,
            'subject' => isset($data['subject']) ? strip_tags($data['subject']) : null,
            'message' => $data['message'],
            'status' => 'new',
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        $notifier->supportMessage($message->email, $order);
        $mailer->send(EmailTemplateKey::SupportReceived, $message->email, [
            'customer_name' => OrderEmailVariables::firstName($message->name),
            'order_id' => $reference ?: 'general enquiry',
        ], $order);

        return back()->with('status', "Thanks — we've received your message and will reply by email as soon as possible.");
    }
}
