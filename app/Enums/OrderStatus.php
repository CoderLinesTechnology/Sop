<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The order lifecycle. Transitions are enforced by OrderStateMachine; never
 * assign an order's status directly.
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    case New = 'NEW';
    case FormSubmitted = 'FORM_SUBMITTED';
    case PaymentPending = 'PAYMENT_PENDING';
    case PaymentConfirmed = 'PAYMENT_CONFIRMED';
    case Researching = 'RESEARCHING';
    case ResearchComplete = 'RESEARCH_COMPLETE';
    case Writing = 'WRITING';
    case QualityReview = 'QUALITY_REVIEW';
    case FinalReview = 'FINAL_REVIEW';
    case DeliveryPending = 'DELIVERY_PENDING';
    case Delivered = 'DELIVERED';
    case PaymentFailed = 'PAYMENT_FAILED';
    case PaymentExpired = 'PAYMENT_EXPIRED';
    case ProcessingFailed = 'PROCESSING_FAILED';
    case NeedsInformation = 'NEEDS_INFORMATION';
    case Cancelled = 'CANCELLED';
    case Refunded = 'REFUNDED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case DeliveryFailed = 'DELIVERY_FAILED';
    case ManualReview = 'MANUAL_REVIEW';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::FormSubmitted => 'Form submitted',
            self::PaymentPending => 'Payment pending',
            self::PaymentConfirmed => 'Payment confirmed',
            self::Researching => 'Researching',
            self::ResearchComplete => 'Research complete',
            self::Writing => 'Writing',
            self::QualityReview => 'Quality review',
            self::FinalReview => 'Final review',
            self::DeliveryPending => 'Delivery pending',
            self::Delivered => 'Delivered',
            self::PaymentFailed => 'Payment failed',
            self::PaymentExpired => 'Payment expired',
            self::ProcessingFailed => 'Processing failed',
            self::NeedsInformation => 'Needs information',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
            self::DeliveryFailed => 'Delivery failed',
            self::ManualReview => 'Manual review',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::PaymentConfirmed, self::Researching, self::ResearchComplete, self::Writing,
            self::QualityReview, self::FinalReview, self::DeliveryPending => 'info',
            self::NeedsInformation, self::ManualReview, self::PaymentPending => 'warning',
            self::PaymentFailed, self::ProcessingFailed, self::DeliveryFailed => 'danger',
            default => 'gray',
        };
    }

    /** Statuses in which the AI pipeline is (or should be) actively working. */
    public function isProcessing(): bool
    {
        return in_array($this, [
            self::PaymentConfirmed, self::Researching, self::ResearchComplete, self::Writing,
            self::QualityReview, self::FinalReview, self::DeliveryPending,
        ], true);
    }

    /** Draft statuses: the customer has not paid yet. */
    public function isPrePayment(): bool
    {
        return in_array($this, [
            self::New, self::FormSubmitted, self::PaymentPending, self::PaymentFailed, self::PaymentExpired,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Refunded], true);
    }

    /** Customer-facing wording; never exposes internal failure details. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::New, self::FormSubmitted => 'Awaiting payment',
            self::PaymentPending => 'Awaiting payment confirmation',
            self::PaymentFailed => 'Payment unsuccessful',
            self::PaymentExpired => 'Payment session expired',
            self::PaymentConfirmed => 'Payment confirmed',
            self::Researching, self::ResearchComplete => 'Researching your application',
            self::Writing => 'Writing your document',
            self::QualityReview, self::FinalReview => 'Final review',
            self::DeliveryPending => 'Preparing your files',
            self::Delivered => 'Delivered',
            self::NeedsInformation => 'We need one more detail',
            self::ProcessingFailed, self::ManualReview, self::DeliveryFailed => 'Still working on it',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }
}
