<?php

namespace App\Domain\Orders;

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Domain\Notifications\AdminNotifier;
use App\Domain\Payments\CheckoutException;
use App\Domain\Payments\PaymentReference;
use App\Domain\Payments\Paystack\PaystackException;
use App\Domain\Payments\Paystack\PaystackGateway;
use App\Enums\EmailTemplateKey;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\RevisionStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Revision;
use Illuminate\Support\Facades\DB;

/**
 * Revisions are linked to the original order. Each service defines how many
 * revisions are included, the window to request them, an optional fee for
 * extra revisions and whether they are AI-assisted or handled manually.
 */
class RevisionService
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly AdminNotifier $notifier,
        private readonly PaystackGateway $paystack,
    ) {}

    /** @return array{allowed:bool, reason:?string, fee:int, included_remaining:int} */
    public function eligibility(Order $order): array
    {
        $fee = (int) data_get($order->service_snapshot, 'revision_fee', 0);
        $remaining = $order->revisionsRemaining();
        $deny = fn (string $reason) => ['allowed' => false, 'reason' => $reason, 'fee' => 0, 'included_remaining' => $remaining];

        if ($order->status !== OrderStatus::Delivered) {
            return $deny('Revisions can be requested once your document has been delivered.');
        }
        if ($order->revision_deadline_at && $order->revision_deadline_at->isPast()) {
            return $deny('The revision period for this order ended on '.$order->revision_deadline_at->format('j F Y').'.');
        }
        if ($order->revisions()->whereIn('status', [RevisionStatus::AwaitingPayment->value, RevisionStatus::Requested->value, RevisionStatus::Processing->value])->exists()) {
            return $deny('A revision is already in progress for this order.');
        }
        if ($remaining > 0) {
            return ['allowed' => true, 'reason' => null, 'fee' => 0, 'included_remaining' => $remaining];
        }
        if ($fee > 0) {
            return ['allowed' => true, 'reason' => null, 'fee' => $fee, 'included_remaining' => 0];
        }

        return $deny("You've used all the revisions included with this order.");
    }

    /**
     * @return array{revision: Revision, redirect_url: ?string}
     *
     * @throws CheckoutException
     */
    public function request(Order $order, string $text): array
    {
        $eligibility = $this->eligibility($order);
        if (! $eligibility['allowed']) {
            throw new CheckoutException((string) $eligibility['reason'], 'revision');
        }

        $mode = (string) data_get($order->service_snapshot, 'revision_mode', 'ai');

        [$revision, $payment] = DB::transaction(function () use ($order, $text, $eligibility, $mode) {
            Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $revision = Revision::query()->create([
                'order_id' => $order->id,
                'number' => (int) $order->revisions()->max('number') + 1,
                'request_text' => mb_substr(trim($text), 0, 5000),
                'status' => $eligibility['fee'] > 0 ? RevisionStatus::AwaitingPayment : RevisionStatus::Requested,
                'mode' => $mode === 'manual' ? 'manual' : 'ai',
                'fee_amount' => $eligibility['fee'],
                'currency' => $order->currency,
                'requested_at' => now(),
            ]);

            $payment = null;
            if ($eligibility['fee'] > 0) {
                $payment = Payment::query()->create([
                    'order_id' => $order->id,
                    'revision_id' => $revision->id,
                    'purpose' => 'revision',
                    'provider' => 'paystack',
                    'reference' => PaymentReference::generate('STR'),
                    'amount' => $eligibility['fee'],
                    'currency' => $order->currency,
                    'status' => PaymentRecordStatus::Initialized,
                    'customer_email' => $order->email,
                    'expires_at' => now()->addDay(),
                ]);
            } else {
                $order->increment('revisions_used');
            }

            return [$revision, $payment];
        });

        $this->notifier->revisionRequested($order, $revision->number);

        if (! $payment) {
            $this->mailer->send(EmailTemplateKey::RevisionReceived, $order->email, OrderEmailVariables::for($order) + ['revision_number' => (string) $revision->number], $order);
            $this->begin($revision);

            return ['revision' => $revision, 'redirect_url' => null];
        }

        try {
            $init = $this->paystack->initialize($order->email, $payment->amount, $payment->currency, $payment->reference, route('checkout.callback'), [
                'order_reference' => $order->reference,
                'revision' => $revision->number,
            ]);
        } catch (PaystackException) {
            $payment->forceFill(['status' => PaymentRecordStatus::Failed, 'failure_reason' => 'initialize_failed'])->save();
            $revision->forceFill(['status' => RevisionStatus::Cancelled])->save();
            throw new CheckoutException("We couldn't connect to our payment provider. Please try again in a moment.");
        }

        $payment->forceFill(['authorization_url' => $init['authorization_url'], 'access_code' => $init['access_code']])->save();

        return ['revision' => $revision, 'redirect_url' => $init['authorization_url']];
    }

    /** Start work on a requested (and, if needed, paid) revision. */
    public function begin(Revision $revision): void
    {
        if ($revision->status !== RevisionStatus::Requested) {
            return;
        }

        if ($revision->mode === 'ai') {
            $revision->forceFill(['status' => RevisionStatus::Processing, 'started_at' => now()])->save();
            app(PipelineDispatcher::class)->startRevision($revision);
        }
        // Manual revisions wait for an administrator (already notified).
    }
}
