<?php

namespace App\Filament\Support\Catalogue;

use App\Support\Settings;
use DateTimeZone;
use Illuminate\Support\Arr;

/**
 * Types of the keys in App\Support\Settings::defaults(), used to turn the
 * settings form state back into correctly typed values and to detect what
 * actually changed.
 */
final class SettingsSchema
{
    /** Keys whose value is a list. */
    private const LISTS = ['orders.allowed_file_types', 'ai.banned_phrases', 'email.admin_notification_emails'];

    /** Keys whose value is a float rather than an integer. */
    private const FLOATS = ['ai.daily_budget_usd'];

    /** Keys whose value is an image path on the public media disk. */
    public const IMAGES = ['general.logo_path', 'seo.social_image'];

    /** File types the upload pipeline can validate and read. */
    public const FILE_TYPES = ['pdf' => 'PDF', 'docx' => 'Word (.docx)', 'txt' => 'Plain text', 'jpg' => 'JPEG (.jpg)', 'jpeg' => 'JPEG (.jpeg)', 'png' => 'PNG'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(Settings::defaults());
    }

    /** Current effective values, nested by group for the form ("general" => ["site_name" => ...]). */
    public static function formState(): array
    {
        $state = [];
        foreach (self::keys() as $key) {
            data_set($state, $key, Settings::get($key));
        }

        return $state;
    }

    /**
     * Flat, typed values for every key from the submitted form state. Keys
     * missing from the state keep their current value.
     *
     * @return array<string, mixed>
     */
    public static function fromFormState(array $state): array
    {
        $values = [];
        foreach (self::keys() as $key) {
            $values[$key] = Arr::has($state, $key)
                ? self::cast($key, data_get($state, $key))
                : Settings::get($key);
        }

        return $values;
    }

    /** @return array<string, mixed> current effective values, flat */
    public static function current(): array
    {
        $values = [];
        foreach (self::keys() as $key) {
            $values[$key] = Settings::get($key);
        }

        return $values;
    }

    public static function cast(string $key, mixed $value): mixed
    {
        $default = Settings::defaults()[$key] ?? null;

        if (in_array($key, self::LISTS, true)) {
            $items = array_map(fn ($item) => trim((string) $item), (array) ($value ?? []));

            return array_values(array_unique(array_filter($items, fn (string $item): bool => $item !== '')));
        }

        if (is_bool($default)) {
            return (bool) $value;
        }

        if (in_array($key, self::FLOATS, true)) {
            return is_numeric($value) ? round((float) $value, 2) : (float) $default;
        }

        if (is_int($default)) {
            return is_numeric($value) ? (int) $value : $default;
        }

        if (is_array($value)) {
            // A single-file upload dehydrates to a string; guard against arrays anyway.
            $value = array_values(array_filter($value))[0] ?? null;
        }

        $value = is_string($value) ? $value : ($value === null ? null : (string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, string> */
    public static function timezones(): array
    {
        $zones = DateTimeZone::listIdentifiers();

        return array_combine($zones, array_map(fn (string $zone): string => str_replace('_', ' ', $zone), $zones));
    }
}
