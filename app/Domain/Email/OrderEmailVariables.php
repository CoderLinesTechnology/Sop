<?php

namespace App\Domain\Email;

use App\Domain\Orders\OrderAccess;
use App\Models\Order;
use App\Support\Money;

/** Common template variables for order emails. */
final class OrderEmailVariables
{
    public static function for(Order $order): array
    {
        $order->loadMissing('service');
        [$min, $max] = $order->service?->deliveryWindow() ?? [20, 30];

        return [
            'customer_name' => self::firstName($order->customer_name ?: $order->applicant_name),
            'order_id' => $order->reference,
            'service_name' => $order->serviceName(),
            'institution' => $order->institution ?: 'your chosen institution',
            'programme' => $order->programme ?: 'your application',
            'order_link' => OrderAccess::statusUrl($order),
            'delivery_time' => 'about '.\App\Support\Settings::formatMinutesRange($min, $max),
            'amount_paid' => Money::format($order->total_amount, $order->currency),
        ];
    }

    public static function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? 'there' : (string) preg_split('/\s+/', $name)[0];
    }
}
