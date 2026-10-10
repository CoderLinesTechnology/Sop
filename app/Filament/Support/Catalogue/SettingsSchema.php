<?php

namespace App\Filament\Support\Catalogue;

use App\Support\Settings;
use DateTimeZone;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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

    /** Keys with a dedicated field on the settings page (anything else gets a generic input). */
    public const HANDLED = [
        'general.site_name', 'general.tagline', 'general.logo_path', 'general.contact_email', 'general.support_email',
        'general.currency', 'general.timezone', 'general.social_linkedin', 'general.social_x', 'general.social_instagram',
        'general.social_youtube',
        'orders.delivery_min_minutes', 'orders.delivery_max_minutes', 'orders.max_file_size_mb', 'orders.allowed_file_types',
        'orders.max_files_per_order', 'orders.order_link_days', 'orders.payment_expiry_hours', 'orders.draft_expiry_hours',
        'orders.needs_info_reminder_hours', 'orders.needs_info_timeout_hours', 'orders.needs_info_timeout_action',
        'orders.retention_days',
        'payments.allow_free_orders',
        'ai.daily_budget_usd', 'ai.banned_phrases',
        'email.from_name', 'email.from_address', 'email.reply_to', 'email.admin_notification_emails', 'email.attach_documents',
        'email.document_link_days',
        'security.admin_session_minutes', 'security.rate_limit_uploads_per_hour', 'security.rate_limit_orders_per_hour',
        'security.rate_limit_coupon_attempts_per_hour',
        'seo.default_title', 'seo.title_suffix', 'seo.default_description', 'seo.social_image', 'seo.google_site_verification',
        'seo.bing_site_verification', 'seo.og_locale', 'seo.ai_search_crawlers', 'seo.ai_training_crawlers', 'seo.indexnow_enabled',
        'analytics.enabled', 'analytics.respect_gpc', 'analytics.retention_days',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(Settings::defaults());
    }

    /**
     * Keys added to Settings::defaults() after this page was written. They are
     * edited with a generic input until they get a dedicated one. System keys
     * (heartbeat token and timestamps) are never edited as plain values.
     *
     * @return list<string>
     */
    public static function unhandledKeys(): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $key): bool => ! in_array($key, self::HANDLED, true) && ! str_starts_with($key, 'system.'),
        ));
    }

    /** A generic input matching the type of the key's default value. */
    public static function genericField(string $key): Field
    {
        $default = Settings::defaults()[$key] ?? null;
        $label = Str::of($key)->after('.')->replace('_', ' ')->ucfirst()->toString();

        return match (true) {
            is_bool($default) => Toggle::make($key)->label($label),
            is_int($default) => TextInput::make($key)->label($label)->integer(),
            is_float($default) => TextInput::make($key)->label($label)->numeric(),
            is_array($default) => TagsInput::make($key)->label($label),
            default => TextInput::make($key)->label($label)->maxLength(1000),
        };
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

        if (in_array($key, self::LISTS, true) || is_array($default)) {
            $items = array_map(fn ($item) => trim((string) $item), (array) ($value ?? []));

            return array_values(array_unique(array_filter($items, fn (string $item): bool => $item !== '')));
        }

        if (is_bool($default)) {
            return (bool) $value;
        }

        if (in_array($key, self::FLOATS, true) || is_float($default)) {
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
