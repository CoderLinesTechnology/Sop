<?php

namespace App\Domain\Documents;

/**
 * English language variants supported by the document engine (the keys of
 * ResolvedRequirements::LANGUAGE_VARIANTS) and the conventions that follow
 * from them: spelling guidance, date format and the Word proofing language.
 */
final class LanguageVariant
{
    public const DEFAULT = 'en-GB';

    /** Variants that write dates month-first ("October 6, 2026"). */
    private const MONTH_FIRST = ['en-US', 'en-CA'];

    private const ALIASES = [
        'british' => 'en-GB', 'british english' => 'en-GB', 'uk' => 'en-GB', 'gb' => 'en-GB', 'en-uk' => 'en-GB',
        'english (uk)' => 'en-GB', 'uk english' => 'en-GB',
        'american' => 'en-US', 'american english' => 'en-US', 'us' => 'en-US', 'usa' => 'en-US',
        'english (us)' => 'en-US', 'us english' => 'en-US',
        'canadian' => 'en-CA', 'canadian english' => 'en-CA',
        'australian' => 'en-AU', 'australian english' => 'en-AU',
        'new zealand' => 'en-NZ', 'new zealand english' => 'en-NZ',
        'irish' => 'en-IE', 'irish english' => 'en-IE',
        'south african' => 'en-ZA', 'south african english' => 'en-ZA',
        'indian' => 'en-IN', 'indian english' => 'en-IN',
    ];

    /** Normalise free-form input ("en_gb", "British English", "US") to a supported variant code, or null. */
    public static function normalize(?string $value): ?string
    {
        $value = strtolower(trim(str_replace('_', '-', (string) $value)));
        if ($value === '') {
            return null;
        }

        foreach (array_keys(ResolvedRequirements::LANGUAGE_VARIANTS) as $code) {
            if ($value === strtolower($code)) {
                return $code;
            }
        }

        return self::ALIASES[$value] ?? null;
    }

    public static function isSupported(?string $variant): bool
    {
        return $variant !== null && array_key_exists($variant, ResolvedRequirements::LANGUAGE_VARIANTS);
    }

    public static function name(string $variant): string
    {
        return ResolvedRequirements::LANGUAGE_VARIANTS[$variant] ?? ResolvedRequirements::LANGUAGE_VARIANTS[self::DEFAULT];
    }

    /** PHP date() format for a formal date line: "6 October 2026" or "October 6, 2026". */
    public static function dateFormat(string $variant): string
    {
        return in_array($variant, self::MONTH_FIRST, true) ? 'F j, Y' : 'j F Y';
    }

    /** Short spelling guidance for writers and prompts. */
    public static function spellingGuidance(string $variant): string
    {
        return match ($variant) {
            'en-US' => 'American spelling (e.g. organize, color, center, program, traveled).',
            'en-CA' => 'Canadian spelling: -our and -re endings (colour, centre) with -ize verbs (organize) and "program".',
            'en-AU' => 'Australian spelling: British-style -ise, -our and -re (organise, colour, centre).',
            'en-NZ' => 'New Zealand spelling: British-style -ise, -our and -re (organise, colour, centre).',
            'en-IE' => 'Irish spelling: British-style -ise, -our and -re (organise, colour, centre, programme).',
            'en-ZA' => 'South African spelling: British-style -ise, -our and -re (organise, colour, centre, programme).',
            'en-IN' => 'Indian English spelling: British-style -ise, -our and -re (organise, colour, centre, programme).',
            default => 'British spelling (e.g. organise, colour, centre, programme, travelled).',
        };
    }
}
