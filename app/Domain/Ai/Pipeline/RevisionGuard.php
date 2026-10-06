<?php

namespace App\Domain\Ai\Pipeline;

use App\Domain\Orders\FulfillmentDenied;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RevisionStatus;
use App\Models\Order;
use App\Models\Revision;

/**
 * Checks before every revision stage: the order is still paid (not refunded
 * or cancelled), the revision is still open, and a revision with a fee has a
 * verified payment.
 */
final class RevisionGuard
{
    /** @throws FulfillmentDenied */
    public static function assert(Order $order, ?Revision $revision): void
    {
        $order->refresh();

        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
            throw new FulfillmentDenied('Order is '.$order->status->value.'.', 'order_closed');
        }

        if (! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            throw new FulfillmentDenied('Order is not paid.', 'not_paid');
        }

        if (! $revision) {
            throw new FulfillmentDenied('Revision not found.', 'revision_missing');
        }

        if (in_array($revision->status, [RevisionStatus::Cancelled, RevisionStatus::Rejected], true)) {
            throw new FulfillmentDenied('Revision is '.$revision->status->value.'.', 'revision_closed');
        }

        if ((int) $revision->fee_amount > 0) {
            $paid = $revision->payments()
                ->where('purpose', 'revision')
                ->where('status', PaymentRecordStatus::Success->value)
                ->whereNotNull('verified_at')
                ->where('amount', (int) $revision->fee_amount)
                ->exists();

            if (! $paid) {
                throw new FulfillmentDenied('The revision fee has not been verified.', 'revision_not_paid');
            }
        }
    }
}
