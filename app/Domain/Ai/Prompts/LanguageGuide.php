<?php

namespace App\Domain\Ai\Prompts;

use App\Domain\Documents\ResolvedRequirements;

/**
 * Trusted, variant-specific spelling and convention guidance for the writing
 * prompts, plus the spelling preferences used by the style linter.
 */
final class LanguageGuide
{
    /** @var array<string, array{our:bool, re:bool, ise:bool, ll:bool, programme:bool, date:string}> */
    private const VARIANTS = [
        'en-GB' => ['our' => true, 're' => true, 'ise' => true, 'll' => true, 'programme' => true, 'date' => '4 October 2026'],
        'en-US' => ['our' => false, 're' => false, 'ise' => false, 'll' => false, 'programme' => false, 'date' => 'October 4, 2026'],
        'en-CA' => ['our' => true, 're' => true, 'ise' => false, 'll' => true, 'programme' => false, 'date' => 'October 4, 2026'],
        'en-AU' => ['our' => true, 're' => true, 'ise' => true, 'll' => true, 'programme' => false, 'date' => '4 October 2026'],
        'en-NZ' => ['our' => true, 're' => true, 'ise' => true, 'll' => true, 'programme' => true, 'date' => '4 October 2026'],
        'en-IE' => ['our' => true, 're' => true, 'ise' => true, 'll' => true, 'programme' => true, 'date' => '4 October 2026'],
        'en-ZA' => ['our' => true, 're' => true, 'ise' => true, 'll' => true, 'programme' => true, 'date' => '4 October 2026'],
        'en-IN' => ['our' => true, 're' => true, 'ise' => true, 'll' => true, 'programme' => true, 'date' => '4 October 2026'],
    ];

    public static function normalize(?string $variant): string
    {
        return isset(self::VARIANTS[$variant ?? '']) ? $variant : 'en-GB';
    }

    /** @return array{our:bool, re:bool, ise:bool, ll:bool, programme:bool, date:string} */
    public static function preferences(string $variant): array
    {
        return self::VARIANTS[self::normalize($variant)];
    }

    public static function describe(string $variant): string
    {
        $variant = self::normalize($variant);
        $p = self::VARIANTS[$variant];
        $name = ResolvedRequirements::LANGUAGE_VARIANTS[$variant];

        $rules = [
            $p['our'] ? '-our spellings (colour, behaviour, favour)' : '-or spellings (color, behavior, favor)',
            $p['re'] ? '-re endings (centre, theatre, fibre)' : '-er endings (center, theater, fiber)',
            $p['ise'] ? '-ise verbs used consistently (organise, recognise, specialise) and "analyse"' : '-ize verbs (organize, recognize, specialize) and "analyze"',
            $p['ll'] ? 'doubled consonants (travelled, modelling, enrolment)' : 'single consonants (traveled, modeling, enrollment)',
            $p['programme'] ? '"programme" for an academic programme (but "program" for software)' : '"program" for an academic program',
            'dates written like "'.$p['date'].'"',
        ];

        return "{$name} ({$variant}): ".implode('; ', $rules).'. Use vocabulary natural to this variant and keep it consistent throughout.';
    }
}
