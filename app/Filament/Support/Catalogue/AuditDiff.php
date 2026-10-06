<?php

namespace App\Filament\Support\Catalogue;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/** Before/after snapshots for audit entries, limited to what actually changed. */
final class AuditDiff
{
    private const IGNORED = ['created_at', 'updated_at'];

    /**
     * Snapshot of a model's attributes, normalised (enums and dates as
     * scalars, JSON columns decoded) so it can be compared and stored.
     *
     * @param  list<string>|null  $only
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model, ?array $only = null): array
    {
        $attributes = $model->attributesToArray();
        if ($only !== null) {
            $attributes = array_intersect_key($attributes, array_flip($only));
        }

        return array_map(self::normalise(...), array_diff_key($attributes, array_flip(self::IGNORED)));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} changed keys only
     */
    public static function changes(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }

            $from = self::normalise($before[$key] ?? null);
            $to = self::normalise($after[$key] ?? null);

            if (self::comparable($from) !== self::comparable($to)) {
                $old[$key] = $from;
                $new[$key] = $to;
            }
        }

        return [$old, $new];
    }

    public static function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_array($value) => array_map(self::normalise(...), $value),
            default => $value,
        };
    }

    /** Loose comparison key: "12" equals 12, "1.50" equals 1.5, false equals 0. */
    private static function comparable(mixed $value): string
    {
        if (is_bool($value)) {
            $value = (int) $value;
        }
        if (is_numeric($value)) {
            $value = (string) (0 + $value);
        }
        if ($value === '') {
            $value = null;
        }

        return json_encode($value) ?: '';
    }
}
