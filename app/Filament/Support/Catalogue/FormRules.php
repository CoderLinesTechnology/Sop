<?php

namespace App\Filament\Support\Catalogue;

use Closure;
use Filament\Schemas\Components\Utilities\Get;

/** Small reusable validation rules for admin forms. */
final class FormRules
{
    /**
     * The value must not be lower than another field's value, when both are
     * filled (Laravel's gte rule fails when the other field is blank).
     */
    public static function notLessThan(string $otherPath, string $message): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $otherPath, $message): void {
            $other = $get($otherPath);

            if (is_numeric($value) && is_numeric($other) && (float) $value < (float) $other) {
                $fail($message);
            }
        };
    }

    /** The value must be strictly greater than another field's value, when both are filled. */
    public static function greaterThan(string $otherPath, string $message): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $otherPath, $message): void {
            $other = $get($otherPath);

            if (is_numeric($value) && is_numeric($other) && (float) $value <= (float) $other) {
                $fail($message);
            }
        };
    }
}
