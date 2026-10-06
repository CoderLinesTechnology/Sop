<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'status' => OrderStatus::FormSubmitted->value,
            'payment_status' => PaymentStatus::Unpaid->value,
            'email' => fake()->unique()->safeEmail(),
            'customer_name' => fake()->name(),
            'applicant_name' => null,
            'currency' => 'USD',
            'subtotal_amount' => 8900,
            'promotion_discount' => 0,
            'coupon_discount' => 0,
            'total_amount' => 8900,
            'pricing_snapshot' => ['total' => 8900, 'currency' => 'USD'],
            'service_snapshot' => [
                'name' => 'Personal Statement',
                'document_kind' => 'personal_statement',
                'revision_window_days' => 14,
                'revision_fee' => 1500,
                'revision_mode' => 'ai',
            ],
            'institution' => 'University of Oxford',
            'programme' => 'MSc Computer Science',
            'degree_level' => 'masters',
            'country_code' => 'GB',
            'essay_prompt' => 'Why do you want to study this programme?',
            'revisions_allowed' => 1,
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    /**
     * A paid order with a matching, verified Paystack payment (the state
     * PaymentConfirmationService produces).
     */
    public function paid(OrderStatus $status = OrderStatus::PaymentConfirmed): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'payment_status' => PaymentStatus::Paid->value,
        ])->afterCreating(function (Order $order) {
            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'purpose' => 'order',
                'provider' => 'paystack',
                'reference' => 'STX-TEST-'.strtoupper(bin2hex(random_bytes(6))),
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => PaymentRecordStatus::Success,
                'customer_email' => $order->email,
                'paid_at' => now(),
                'verified_at' => now(),
            ]);
            $order->forceFill(['payment_reference' => $payment->reference])->save();
        });
    }
}
