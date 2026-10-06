<?php

use App\Domain\Documents\CountryConventions;
use App\Domain\Documents\LanguageVariant;
use App\Domain\Documents\RequirementResolver;

it('exposes country conventions for the AI pipeline', function () {
    $nigeria = CountryConventions::for('ng');
    $us = CountryConventions::for('US');

    expect($nigeria)->toMatchArray([
        'country_code' => 'NG',
        'known' => true,
        'language_variant' => 'en-GB',
        'language_name' => 'British English',
        'date_format' => 'j F Y',
        'date_example' => '6 October 2026',
        'page_size' => 'A4',
        'letter_closing' => 'Yours sincerely,',
    ])
        ->and($nigeria['spelling'])->toContain('organise')
        ->and($us)->toMatchArray(['language_variant' => 'en-US', 'date_format' => 'F j, Y', 'date_example' => 'October 6, 2026', 'page_size' => 'Letter', 'letter_closing' => 'Sincerely,'])
        ->and(CountryConventions::for(null))->toMatchArray(['country_code' => null, 'known' => false, 'language_variant' => 'en-GB', 'page_size' => null]);
});

it('maps destinations to English variants and paper sizes', function (string $country, string $variant, ?string $paper) {
    expect(CountryConventions::languageVariant($country))->toBe($variant)
        ->and(CountryConventions::pageSize($country))->toBe($paper);
})->with([
    ['GB', 'en-GB', 'A4'], ['UK', 'en-GB', 'A4'], ['IE', 'en-IE', 'A4'], ['AU', 'en-AU', 'A4'], ['NZ', 'en-NZ', 'A4'],
    ['ZA', 'en-ZA', 'A4'], ['IN', 'en-IN', 'A4'], ['NG', 'en-GB', 'A4'], ['GH', 'en-GB', 'A4'], ['KE', 'en-GB', 'A4'],
    ['DE', 'en-GB', 'A4'], ['NL', 'en-GB', 'A4'], ['FR', 'en-GB', 'A4'], ['SE', 'en-GB', 'A4'],
    ['US', 'en-US', 'Letter'], ['CA', 'en-CA', 'Letter'], ['XX', 'en-GB', 'A4'],
]);

it('only assumes a platform on a well-established route', function () {
    expect(CountryConventions::defaultPlatform('GB', "Bachelor's", 'personal_statement'))->toBe('UCAS')
        ->and(CountryConventions::defaultPlatform('GB', 'masters', 'personal_statement'))->toBeNull()
        ->and(CountryConventions::defaultPlatform('GB', null, 'personal_statement'))->toBeNull()
        ->and(CountryConventions::defaultPlatform('US', 'undergraduate', 'personal_statement'))->toBeNull();
});

it('normalises language variants, degree levels and platforms', function () {
    expect(LanguageVariant::normalize('en_gb'))->toBe('en-GB')
        ->and(LanguageVariant::normalize('American English'))->toBe('en-US')
        ->and(LanguageVariant::normalize('Klingon'))->toBeNull()
        ->and(LanguageVariant::dateFormat('en-CA'))->toBe('F j, Y')
        ->and(LanguageVariant::dateFormat('en-AU'))->toBe('j F Y')
        ->and(RequirementResolver::degreeLevel("Master's"))->toBe('masters')
        ->and(RequirementResolver::degreeLevel('MSc'))->toBe('masters')
        ->and(RequirementResolver::degreeLevel('BSc (Hons)'))->toBe('undergraduate')
        ->and(RequirementResolver::degreeLevel('PhD / Doctorate'))->toBe('phd')
        ->and(RequirementResolver::degreeLevel('postgraduate_diploma'))->toBe('postgraduate_diploma')
        ->and(RequirementResolver::platformKey('The Common Application'))->toBe(RequirementResolver::platformKey('Common App'))
        ->and(RequirementResolver::platformKey('UCAS Hub'))->toBe('ucas');
});
