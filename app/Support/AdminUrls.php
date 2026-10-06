<?php

namespace App\Support;

use App\Models\Order;

/** Deep links into the admin panel that do not depend on resource classes. */
final class AdminUrls
{
    public static function base(): string
    {
        return url(trim((string) config('statementra.security.admin_path', 'admin'), '/'));
    }

    public static function order(Order $order): string
    {
        return self::base().'/orders/'.$order->public_id;
    }
}
