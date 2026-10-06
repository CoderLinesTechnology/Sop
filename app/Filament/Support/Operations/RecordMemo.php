<?php

namespace App\Filament\Support\Operations;

use Closure;
use WeakMap;

/**
 * Per-request memoisation keyed by a model instance (held weakly, so nothing
 * outlives the request). Used for derived data on admin pages, such as an
 * order's audit trail or refundable balance, without storing fake relations
 * on the model (domain services may refresh() the same instance).
 */
final class RecordMemo
{
    /** @var WeakMap<object, array<string, mixed>>|null */
    private static ?WeakMap $store = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(object $record, string $key, Closure $callback): mixed
    {
        self::$store ??= new WeakMap;

        $values = self::$store[$record] ?? [];

        if (! array_key_exists($key, $values)) {
            $values[$key] = $callback();
            self::$store[$record] = $values;
        }

        return $values[$key];
    }

    /** Forget everything memoised for a record (e.g. after an action changed it). */
    public static function forget(object $record): void
    {
        if (self::$store !== null) {
            unset(self::$store[$record]);
        }
    }
}
