<?php

namespace App\Filament\Support\Operations;

use App\Models\AdminUser;

/**
 * Administrator display names for columns that store a bare admin id
 * (document versions, information requests, ...). Loaded with one query per
 * request; the admin table is small.
 */
final class AdminNames
{
    private const CONTAINER_KEY = 'filament.operations.admin-names';

    public static function name(?int $id, string $fallback = 'An administrator'): ?string
    {
        if (! $id) {
            return null;
        }

        if (! app()->bound(self::CONTAINER_KEY)) {
            app()->instance(self::CONTAINER_KEY, AdminUser::query()->pluck('name', 'id')->all());
        }

        return app(self::CONTAINER_KEY)[$id] ?? $fallback;
    }
}
