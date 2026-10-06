<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use ResourceBundle;

/** ISO 3166 countries (names from ICU via ext-intl) and international dialling codes. */
final class Countries
{
    /** @return array<string, string> code => English name, sorted by name */
    public static function all(): array
    {
        return Cache::rememberForever('countries:en:v1', function () {
            $countries = [];
            $bundle = class_exists(ResourceBundle::class) ? ResourceBundle::create('en', 'ICUDATA-region') : null;
            $table = $bundle?->get('Countries');

            if ($table) {
                foreach ($table as $code => $name) {
                    if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, ['ZZ', 'EU', 'EZ', 'UN', 'QO', 'XA', 'XB', 'XK'], true)) {
                        $countries[$code] = (string) $name;
                    }
                }
            }

            if ($countries === []) {
                $countries = self::FALLBACK;
            }

            asort($countries, SORT_NATURAL | SORT_FLAG_CASE);

            return $countries;
        });
    }

    public static function name(?string $code): ?string
    {
        return $code ? (self::all()[strtoupper($code)] ?? null) : null;
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && array_key_exists(strtoupper($code), self::all());
    }

    /** Popular destinations shown first in the country select. */
    public const POPULAR = ['GB', 'US', 'CA', 'AU', 'IE', 'DE', 'NL', 'FR'];

    /** @var array<string, array{0:string,1:string}> code => [name, dial code] */
    public const DIAL_CODES = [
        'GH' => ['Ghana', '+233'], 'NG' => ['Nigeria', '+234'], 'KE' => ['Kenya', '+254'], 'ZA' => ['South Africa', '+27'],
        'UG' => ['Uganda', '+256'], 'TZ' => ['Tanzania', '+255'], 'RW' => ['Rwanda', '+250'], 'ET' => ['Ethiopia', '+251'],
        'EG' => ['Egypt', '+20'], 'MA' => ['Morocco', '+212'], 'CM' => ['Cameroon', '+237'], 'CI' => ["Côte d'Ivoire", '+225'],
        'SN' => ['Senegal', '+221'], 'ZM' => ['Zambia', '+260'], 'ZW' => ['Zimbabwe', '+263'], 'BW' => ['Botswana', '+267'],
        'MW' => ['Malawi', '+265'], 'GB' => ['United Kingdom', '+44'], 'IE' => ['Ireland', '+353'], 'US' => ['United States', '+1'],
        'CA' => ['Canada', '+1'], 'AU' => ['Australia', '+61'], 'NZ' => ['New Zealand', '+64'], 'IN' => ['India', '+91'],
        'PK' => ['Pakistan', '+92'], 'BD' => ['Bangladesh', '+880'], 'LK' => ['Sri Lanka', '+94'], 'NP' => ['Nepal', '+977'],
        'CN' => ['China', '+86'], 'HK' => ['Hong Kong', '+852'], 'SG' => ['Singapore', '+65'], 'MY' => ['Malaysia', '+60'],
        'ID' => ['Indonesia', '+62'], 'PH' => ['Philippines', '+63'], 'VN' => ['Vietnam', '+84'], 'TH' => ['Thailand', '+66'],
        'JP' => ['Japan', '+81'], 'KR' => ['South Korea', '+82'], 'AE' => ['United Arab Emirates', '+971'], 'SA' => ['Saudi Arabia', '+966'],
        'QA' => ['Qatar', '+974'], 'KW' => ['Kuwait', '+965'], 'TR' => ['Turkey', '+90'], 'DE' => ['Germany', '+49'],
        'FR' => ['France', '+33'], 'NL' => ['Netherlands', '+31'], 'BE' => ['Belgium', '+32'], 'CH' => ['Switzerland', '+41'],
        'AT' => ['Austria', '+43'], 'IT' => ['Italy', '+39'], 'ES' => ['Spain', '+34'], 'PT' => ['Portugal', '+351'],
        'SE' => ['Sweden', '+46'], 'NO' => ['Norway', '+47'], 'DK' => ['Denmark', '+45'], 'FI' => ['Finland', '+358'],
        'PL' => ['Poland', '+48'], 'CZ' => ['Czechia', '+420'], 'HU' => ['Hungary', '+36'], 'RO' => ['Romania', '+40'],
        'GR' => ['Greece', '+30'], 'UA' => ['Ukraine', '+380'], 'BR' => ['Brazil', '+55'], 'MX' => ['Mexico', '+52'],
        'AR' => ['Argentina', '+54'], 'CL' => ['Chile', '+56'], 'CO' => ['Colombia', '+57'], 'PE' => ['Peru', '+51'],
    ];

    public static function defaultDialCountry(?string $requestCountry): string
    {
        $requestCountry = strtoupper((string) $requestCountry);

        return array_key_exists($requestCountry, self::DIAL_CODES) ? $requestCountry : 'GH';
    }

    /** Minimal fallback when ext-intl data is unavailable. */
    private const FALLBACK = [
        'AU' => 'Australia', 'AT' => 'Austria', 'BE' => 'Belgium', 'CA' => 'Canada', 'CN' => 'China', 'DK' => 'Denmark',
        'FI' => 'Finland', 'FR' => 'France', 'DE' => 'Germany', 'GH' => 'Ghana', 'HK' => 'Hong Kong', 'IN' => 'India',
        'IE' => 'Ireland', 'IT' => 'Italy', 'JP' => 'Japan', 'KE' => 'Kenya', 'MY' => 'Malaysia', 'NL' => 'Netherlands',
        'NZ' => 'New Zealand', 'NG' => 'Nigeria', 'NO' => 'Norway', 'PL' => 'Poland', 'PT' => 'Portugal', 'SG' => 'Singapore',
        'ZA' => 'South Africa', 'KR' => 'South Korea', 'ES' => 'Spain', 'SE' => 'Sweden', 'CH' => 'Switzerland',
        'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States',
    ];
}
