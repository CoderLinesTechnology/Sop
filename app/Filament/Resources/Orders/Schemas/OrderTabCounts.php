<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Tab badge counts for the order page, loaded with a single query
 * (withCount sub-selects) the first time any badge is rendered.
 */
final class OrderTabCounts
{
    private const RELATIONS = [
        'answers', 'files', 'researchClaims', 'aiJobs', 'documentVersions', 'emails', 'payments', 'refunds',
        'revisions', 'informationRequests', 'notes',
    ];

    public static function get(Order $order, string $relation): ?int
    {
        if (! array_key_exists('notes_count', $order->getAttributes())) {
            $order->loadCount(self::RELATIONS);
        }

        $count = (int) $order->getAttribute(Str::snake($relation).'_count');

        return $count > 0 ? $count : null;
    }
}
