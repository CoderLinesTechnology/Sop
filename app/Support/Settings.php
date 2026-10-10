<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Administrator-managed site settings with code defaults.
 *
 * Keys use "group.name" notation. Values are cached as a single array and the
 * cache is flushed whenever a setting changes, so every web node and worker
 * sees admin changes immediately without a deploy.
 */
final class Settings
{
    private const CACHE_KEY = 'site_settings:v1';

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    /** Defaults for every setting, grouped. */
    public static function defaults(): array
    {
        return [
            'general.site_name' => 'Statementra',
            'general.tagline' => 'Your story. Researched. Written. Refined.',
            'general.logo_path' => null,
            'general.contact_email' => 'hello@statementra.com',
            'general.support_email' => 'support@statementra.com',
            'general.currency' => 'GHS',
            'general.timezone' => 'UTC',
            'general.social_linkedin' => null,
            'general.social_x' => null,
            'general.social_instagram' => null,
            'general.social_youtube' => null,

            'orders.delivery_min_minutes' => 20,
            'orders.delivery_max_minutes' => 30,
            'orders.max_file_size_mb' => 10,
            'orders.allowed_file_types' => ['pdf', 'docx', 'txt', 'jpg', 'png'],
            'orders.max_files_per_order' => 10,
            'orders.order_link_days' => 30,
            'orders.payment_expiry_hours' => 24,
            'orders.draft_expiry_hours' => 48,
            'orders.needs_info_reminder_hours' => 24,
            'orders.needs_info_timeout_hours' => 72,
            'orders.needs_info_timeout_action' => 'proceed',
            'orders.retention_days' => 90,

            'payments.allow_free_orders' => true,

            'ai.daily_budget_usd' => 250,
            'ai.banned_phrases' => [
                'delve', 'tapestry', 'testament to', 'in today\'s fast-paced world', 'ever since i was a child',
                'i have always been passionate', 'unwavering', 'embark on a journey', 'realm of', 'navigating the',
                'a myriad of', 'foster', 'synergy', 'cutting-edge', 'leverage my', 'holistic', 'paramount',
                'it is worth noting', 'in conclusion', 'i am writing to express', 'dynamic landscape',
            ],

            'email.from_name' => 'Statementra',
            'email.from_address' => null,
            'email.reply_to' => null,
            'email.admin_notification_emails' => [],
            'email.attach_documents' => true,
            'email.document_link_days' => 14,

            'security.admin_session_minutes' => 120,
            'security.rate_limit_uploads_per_hour' => 40,
            'security.rate_limit_orders_per_hour' => 12,
            'security.rate_limit_coupon_attempts_per_hour' => 15,

            'seo.default_title' => 'Statementra — Personalized application documents, researched and written for you',
            'seo.title_suffix' => ' | Statementra',
            'seo.default_description' => 'Get a personalized personal statement, statement of purpose, motivation letter or scholarship essay — researched around your programme, written around your real experience and delivered to your email in about 20–30 minutes.',
            'seo.social_image' => null,
            'seo.google_site_verification' => null,

            'analytics.enabled' => true,
            'analytics.respect_gpc' => true,
            'analytics.retention_days' => 395,
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : ($default ?? self::defaults()[$key] ?? null);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $stored = [];
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, function () {
                if (! Schema::hasTable('site_settings')) {
                    return [];
                }

                return SiteSetting::query()->pluck('value', 'key')->all();
            });
        } catch (Throwable) {
            // Database unavailable (e.g. during install); fall back to defaults.
        }

        return self::$memo = array_replace(self::defaults(), $stored);
    }

    /** @return array<string, mixed> settings of one group, keyed without the group prefix */
    public static function group(string $group): array
    {
        $prefix = $group.'.';
        $values = [];
        foreach (self::all() as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $values[substr($key, strlen($prefix))] = $value;
            }
        }

        return $values;
    }

    public static function set(string $key, mixed $value, ?int $adminId = null): void
    {
        SiteSetting::query()->updateOrCreate(
            ['key' => $key],
            ['group' => Arr::first(explode('.', $key)), 'value' => $value, 'updated_by_admin_id' => $adminId],
        );
        self::flush();
    }

    /** @param array<string, mixed> $values */
    public static function setMany(array $values, ?int $adminId = null): void
    {
        foreach ($values as $key => $value) {
            SiteSetting::query()->updateOrCreate(
                ['key' => $key],
                ['group' => Arr::first(explode('.', $key)), 'value' => $value, 'updated_by_admin_id' => $adminId],
            );
        }
        self::flush();
    }

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    // -------------------------------------------------------------- typed helpers

    public static function siteName(): string
    {
        return (string) self::get('general.site_name', 'Statementra');
    }

    public static function supportEmail(): string
    {
        return (string) self::get('general.support_email');
    }

    public static function currency(): string
    {
        return strtoupper((string) self::get('general.currency', 'GHS'));
    }

    /** @return list<string> */
    public static function allowedUploadExtensions(): array
    {
        $allowed = array_map('strtolower', (array) self::get('orders.allowed_file_types'));

        // Never allow anything outside the set the upload validator understands.
        return array_values(array_intersect($allowed, ['pdf', 'docx', 'txt', 'jpg', 'jpeg', 'png']));
    }

    public static function maxUploadBytes(): int
    {
        return max(1, min(25, (int) self::get('orders.max_file_size_mb', 10))) * 1024 * 1024;
    }

    /** @return list<string> */
    public static function adminNotificationEmails(): array
    {
        return array_values(array_filter((array) self::get('email.admin_notification_emails', [])));
    }

    public static function formatMinutesRange(int $min, int $max): string
    {
        $format = function (int $minutes): string {
            if ($minutes >= 120 && $minutes % 60 === 0) {
                return ($minutes / 60).' hours';
            }

            return $minutes.' minutes';
        };

        if ($min === $max) {
            return $format($min);
        }

        if ($max < 120) {
            return $min.'–'.$max.' minutes';
        }

        return $format($min).' – '.$format($max);
    }
}
