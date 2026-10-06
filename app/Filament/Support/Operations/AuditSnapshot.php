<?php

namespace App\Filament\Support\Operations;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Before/after snapshots of admin-edited records for the audit log
 * (attributes plus the ids of related records such as a coupon's services).
 */
final class AuditSnapshot
{
    private const IGNORED = ['created_at', 'updated_at'];

    /**
     * @param  list<string>  $relations  belongs-to-many relations recorded as sorted id lists
     * @return array<string, mixed>
     */
    public static function of(Model $model, array $relations = []): array
    {
        $snapshot = [];
        foreach ($model->getAttributes() as $key => $value) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }
            $snapshot[$key] = self::normalize($model->getAttribute($key));
        }

        foreach ($relations as $relation) {
            $snapshot[$relation] = $model->{$relation}()->pluck($model->{$relation}()->getRelated()->getQualifiedKeyName())
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Only the keys whose values changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old != $new) {
                $changedBefore[$key] = $old;
                $changedAfter[$key] = $new;
            }
        }

        return [$changedBefore, $changedAfter];
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_float($value) => round($value, 4),
            default => $value,
        };
    }
}
