<?php

namespace App\Domain\Documents;

/**
 * Well-established writing and formatting conventions of the destination
 * country: English variant, date format and paper size. These are
 * conventions, not institutional requirements: RequirementResolver only falls
 * back to them when no instruction, research finding or admin rule says
 * otherwise. Static so the AI pipeline can use them directly in prompts:
 *
 *     CountryConventions::for('NG')['language_variant']; // "en-GB"
 */
final class CountryConventions
{
    /**
     * Countries whose English follows something other than the British
     * default. Every other country (including continental Europe, Nigeria,
     * Ghana, Kenya...) uses British-style English.
     */
    private const VARIANTS = [
        'US' => 'en-US', 'PR' => 'en-US', 'PH' => 'en-US', 'LR' => 'en-US',
        'CA' => 'en-CA',
        'GB' => 'en-GB',
        'IE' => 'en-IE',
        'AU' => 'en-AU',
        'NZ' => 'en-NZ',
        'ZA' => 'en-ZA',
        'IN' => 'en-IN',
    ];

    /** Countries where US Letter is the standard paper size; A4 everywhere else. */
    private const LETTER_COUNTRIES = ['US', 'CA'];

    /**
     * @return array{country_code:?string, known:bool, language_variant:string, language_name:string,
     *               spelling:string, date_format:string, date_example:string, page_size:?string,
     *               letter_closing:string, source:string}
     */
    public static function for(?string $countryCode): array
    {
        $code = self::normalizeCountry($countryCode);
        $variant = self::languageVariant($code);

        return [
            'country_code' => $code,
            'known' => $code !== null,
            'language_variant' => $variant,
            'language_name' => LanguageVariant::name($variant),
            'spelling' => LanguageVariant::spellingGuidance($variant),
            'date_format' => LanguageVariant::dateFormat($variant),
            'date_example' => date(LanguageVariant::dateFormat($variant), mktime(12, 0, 0, 10, 6, 2026)),
            'page_size' => self::pageSize($code),
            'letter_closing' => $variant === 'en-US' ? 'Sincerely,' : 'Yours sincerely,',
            'source' => 'convention',
        ];
    }

    public static function languageVariant(?string $countryCode): string
    {
        $code = self::normalizeCountry($countryCode);

        return $code !== null ? (self::VARIANTS[$code] ?? LanguageVariant::DEFAULT) : LanguageVariant::DEFAULT;
    }

    public static function dateFormat(?string $countryCode): string
    {
        return LanguageVariant::dateFormat(self::languageVariant($countryCode));
    }

    /** "Letter" or "A4"; null when the destination country is unknown (the template decides). */
    public static function pageSize(?string $countryCode): ?string
    {
        $code = self::normalizeCountry($countryCode);
        if ($code === null) {
            return null;
        }

        return in_array($code, self::LETTER_COUNTRIES, true) ? 'Letter' : 'A4';
    }

    /**
     * The application platform used by (virtually) every applicant on a
     * well-established route: UK undergraduate personal statements go through
     * UCAS. Returns null wherever several routes exist (e.g. US colleges use
     * the Common App, Coalition or their own forms), so nothing is assumed.
     */
    public static function defaultPlatform(?string $countryCode, ?string $degreeLevel, ?string $documentKind): ?string
    {
        $code = self::normalizeCountry($countryCode);

        if ($code === 'GB' && RequirementResolver::degreeLevel($degreeLevel) === 'undergraduate' && $documentKind === 'personal_statement') {
            return 'UCAS';
        }

        return null;
    }

    /** ISO 3166-1 alpha-2 code in upper case (UK → GB), or null. */
    public static function normalizeCountry(?string $countryCode): ?string
    {
        $code = strtoupper(trim((string) $countryCode));
        if ($code === 'UK') {
            return 'GB';
        }

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }
}
