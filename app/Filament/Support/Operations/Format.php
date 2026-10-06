<?php

namespace App\Filament\Support\Operations;

use App\Support\Money;
use BackedEnum;
use DateTimeInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Consistent formatting for the operations side of the admin panel: money,
 * timestamps, durations, AI costs and safe rendering of structured data.
 */
final class Format
{
    /** Readable timestamp used across tables and infolists, e.g. "6 Oct 2026, 14:05". */
    public const DATETIME = 'j M Y, H:i';

    public const DATE = 'j M Y';

    public const PLACEHOLDER = '—';

    public static function money(?int $minor, ?string $currency): string
    {
        return $minor === null ? self::PLACEHOLDER : Money::format($minor, $currency);
    }

    /** AI provider costs are tracked in USD with sub-cent precision. */
    public static function usd(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return self::PLACEHOLDER;
        }

        $value = (float) $amount;

        return '$'.number_format($value, $value > 0 && $value < 1 ? 4 : 2);
    }

    public static function number(int|float|string|null $value, int $decimals = 0): string
    {
        return $value === null || $value === '' ? self::PLACEHOLDER : number_format((float) $value, $decimals);
    }

    /** A ratio between 0 and 1 rendered as a percentage. */
    public static function percent(?float $ratio, int $decimals = 1): string
    {
        return $ratio === null ? self::PLACEHOLDER : number_format($ratio * 100, $decimals).'%';
    }

    /** Minutes rendered as "25 min", "2 h 5 min" or "3 d 4 h". */
    public static function minutes(int|float|null $minutes): string
    {
        if ($minutes === null) {
            return self::PLACEHOLDER;
        }

        $minutes = (int) round($minutes);

        if ($minutes < 60) {
            return $minutes.' min';
        }

        if ($minutes < 1440) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            return $hours.' h'.($rest ? ' '.$rest.' min' : '');
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        return $days.' d'.($hours ? ' '.$hours.' h' : '');
    }

    /** Milliseconds rendered as "850 ms", "12.4 s" or "2 min 5 s". */
    public static function milliseconds(?int $ms): string
    {
        if ($ms === null) {
            return self::PLACEHOLDER;
        }

        if ($ms < 1000) {
            return $ms.' ms';
        }

        $seconds = $ms / 1000;
        if ($seconds < 60) {
            return number_format($seconds, 1).' s';
        }

        return intdiv((int) $seconds, 60).' min '.((int) $seconds % 60).' s';
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return self::PLACEHOLDER;
        }

        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }

    /** In the panel's time zone; accepts a date object or a raw database timestamp string. */
    public static function dateTime(DateTimeInterface|string|null $value): string
    {
        if (is_string($value) && $value !== '') {
            try {
                $value = Carbon::parse($value);
            } catch (Throwable) {
                return $value;
            }
        }

        return $value instanceof DateTimeInterface
            ? Carbon::instance($value)->setTimezone(FilamentTimezone::get())->format(self::DATETIME)
            : self::PLACEHOLDER;
    }

    /** "first_name" → "First name". */
    public static function humanKey(string|int $key): string
    {
        return is_int($key) ? '#'.($key + 1) : Str::ucfirst(trim(str_replace(['_', '-'], ' ', $key)));
    }

    /**
     * Only http(s) URLs are rendered as links; anything else (javascript:,
     * data:, relative junk from untrusted sources) is dropped.
     */
    public static function safeUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) ? $url : null;
    }

    /** Pretty-printed, escaped JSON for technical payloads. */
    public static function json(mixed $value): HtmlString
    {
        if ($value === null || $value === [] || $value === '') {
            return new HtmlString('<span style="opacity:.6">'.self::PLACEHOLDER.'</span>');
        }

        $json = is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new HtmlString(
            '<pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.5;margin:0;'
            .'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;max-height:28rem;overflow:auto">'
            .e((string) $json).'</pre>'
        );
    }

    /**
     * Readable rendering of nested arrays (applicant profiles, requirement
     * logs): keys become labels, lists become bullet lists. Every value is
     * escaped; nothing from the data is ever rendered as markup.
     */
    public static function structured(mixed $value): HtmlString
    {
        if ($value === null || $value === [] || $value === '') {
            return new HtmlString('<span style="opacity:.6">'.self::PLACEHOLDER.'</span>');
        }

        return new HtmlString('<div style="font-size:14px;line-height:1.55">'.self::renderValue($value, 0).'</div>');
    }

    private static function renderValue(mixed $value, int $depth): string
    {
        if ($depth > 6) {
            return e(Str::limit((string) json_encode($value), 300));
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value === null || $value === '') {
            return '<span style="opacity:.6">'.self::PLACEHOLDER.'</span>';
        }

        if (! is_array($value)) {
            return nl2br(e((string) $value));
        }

        if (array_is_list($value)) {
            $items = array_map(fn ($item) => '<li>'.self::renderValue($item, $depth + 1).'</li>', $value);

            return '<ul style="list-style:disc;padding-inline-start:1.25rem;margin:0">'.implode('', $items).'</ul>';
        }

        $rows = [];
        foreach ($value as $key => $item) {
            $rows[] = '<div style="margin:0 0 .5rem 0"><dt style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em;opacity:.7">'
                .e(self::humanKey($key)).'</dt><dd style="margin:0'.($depth > 0 ? ' 0 0 .75rem' : '').'">'
                .self::renderValue($item, $depth + 1).'</dd></div>';
        }

        return '<dl style="margin:0">'.implode('', $rows).'</dl>';
    }
}
