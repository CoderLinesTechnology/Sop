<?php

use App\Domain\Ai\Writing\LengthChecker;
use App\Domain\Ai\Writing\StyleLinter;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\ResolvedRequirements;
use App\Enums\DocumentKind;

function paragraphs(string ...$texts): DocumentModel
{
    return new DocumentModel('T', null, null, array_map(fn ($t) => ['type' => 'paragraph', 'text' => $t], $texts));
}

it('finds banned phrases, dash overuse, repeated openings and stock transitions', function () {
    $doc = paragraphs(
        'I want to delve into machine learning — it matters — to me — a lot.',
        'I built a tool. I tested it. I shipped it. I learned from it.',
        'Furthermore, the course fits. Moreover, it is close to home.',
    );

    $types = array_column((new StyleLinter)->lint($doc, ['delve', 'in conclusion']), 'type');

    expect($types)->toContain('banned_phrase', 'dash_overuse', 'repeated_openings', 'consecutive_openings', 'stock_transitions')
        ->not->toContain('exclamation');
});

it('flags monotonous rhythm but not varied prose', function () {
    $flat = paragraphs('I like the long walk home. I like the quiet tall trees. I like the cold blue river. I like the old stone bridge. I like the small red boats. I like the soft green hills.');
    $varied = paragraphs('Rain. The walk home from the lab took forty minutes, and I spent most of them rethinking the error analysis I had just run. Then it clicked. I rewrote the loss function that night and the validation accuracy finally stopped oscillating.');

    expect(array_column((new StyleLinter)->lint($flat, []), 'type'))->toContain('monotonous_rhythm')
        ->and(array_column((new StyleLinter)->lint($varied, []), 'type'))->not->toContain('monotonous_rhythm');
});

it('checks spelling against the required English variant without touching names', function () {
    $doc = paragraphs('I want to analyze color data at the Center for Programme Studies.');

    $gb = collect((new StyleLinter)->lint($doc, [], 'en-GB', ['Center for Programme Studies']))->firstWhere('type', 'variant_spelling');
    $us = collect((new StyleLinter)->lint(paragraphs('I organised the colour programme.'), [], 'en-US'))->firstWhere('type', 'variant_spelling');

    expect($gb['message'])->toContain('"analyze" → "analyse"')->toContain('"color" → "colour"')->not->toContain('center')
        ->and($us['message'])->toContain('"organised" → "organized"')->toContain('"colour" → "color"')->toContain('"programme" → "program"');
});

it('reports word, character and section limit violations with targets', function () {
    $checker = new LengthChecker;
    $doc = paragraphs(str_repeat('word ', 120));

    $words = $checker->check($doc, new ResolvedRequirements(maxWords: 100, targetWords: 90), null, 90);
    expect($words['ok'])->toBeFalse()
        ->and($words['hard'])->toBeTrue()
        ->and($words['violations'][0]['type'])->toBe('max_words')
        ->and($words['counts']['words'])->toBe(120)
        ->and($words['targets']['words'])->toBe(90);

    $chars = $checker->check($doc, new ResolvedRequirements(maxCharacters: 500, targetWords: 80), null, 80);
    expect(array_column($chars['violations'], 'type'))->toBe(['max_characters'])
        ->and($chars['targets']['characters'])->toBe(480);

    $ok = $checker->check(paragraphs(str_repeat('word ', 85)), new ResolvedRequirements(maxWords: 100, targetWords: 90), null, 90);
    expect($ok['ok'])->toBeTrue();
});

it('checks required sections and their own limits, excluding headings when the portal shows them', function () {
    $requirements = new ResolvedRequirements(
        maxCharacters: 4000,
        requiredSections: [
            ['heading' => 'Why do you want to study this course?', 'min_characters' => 350],
            ['heading' => 'How has your learning prepared you?', 'min_characters' => 350],
        ],
        limitsIncludeHeadings: false,
    );
    $doc = new DocumentModel('T', null, null, [
        ['type' => 'heading', 'text' => 'Why do you want to study this course?'],
        ['type' => 'paragraph', 'text' => str_repeat('Because I love it. ', 30)],
        ['type' => 'heading', 'text' => 'How has your learning prepared you'],
        ['type' => 'paragraph', 'text' => 'Briefly.'],
    ]);

    $report = (new LengthChecker)->check($doc, $requirements, null, 600);

    expect(array_column($report['violations'], 'type'))->toBe(['section_min_characters'])
        ->and($report['violations'][0]['section'])->toBe('How has your learning prepared you?')
        ->and($report['counts']['characters'])->toBe($requirements->countCharacters($doc));

    $missing = (new LengthChecker)->check(paragraphs('No headings at all.'), $requirements, null, 600);
    expect(array_column($missing['violations'], 'type'))->toContain('missing_section');
});

it('uses a soft target band only when nothing official limits the length', function () {
    $checker = new LengthChecker;

    expect(array_column($checker->check(paragraphs(str_repeat('word ', 200)), new ResolvedRequirements(targetWords: 650), null, 650)['violations'], 'type'))->toBe(['target_words'])
        ->and($checker->check(paragraphs(str_repeat('word ', 200)), new ResolvedRequirements(targetWords: 650), null, 650)['hard'])->toBeFalse()
        ->and($checker->check(paragraphs(str_repeat('word ', 600)), new ResolvedRequirements(targetWords: 650), null, 650)['ok'])->toBeTrue();
});

it('allows one em dash but flags a second, and catches semicolon runs, ellipses and self-praise', function () {
    $one = paragraphs('I rebuilt the parser — twice — before it held.');
    $single = paragraphs('I rebuilt the parser — and it finally held under load.');
    $loose = paragraphs(
        'I tested the pump; it failed; I fixed the seal; it held; the farm used it all season.',
        'The results were mixed... but promising.',
        'I am passionate about renewable energy and I am a quick learner.',
    );

    $types = fn ($doc) => array_column((new StyleLinter)->lint($doc, []), 'type');

    expect($types($one))->toContain('dash_overuse')
        ->and($types($single))->not->toContain('dash_overuse')
        ->and($types($loose))->toContain('semicolon_overuse', 'ellipsis', 'empty_claim');
});

it('flags long, even sentences and heavy, stiff wording, but not plain human prose', function () {
    $stiff = paragraphs(
        'For MyClean, a waste collection platform connecting households, businesses, and recyclers in Accra, I designed the architecture for its Flutter mobile application and the backend systems that now serve its paying subscribers across the city.',
        'My undergraduate programme in Information and Communications Technology Education provided foundational preparation in computing, mathematics, and educational methodology for subsequent research.',
        'Its smartphone setting connects directly to my mobile development experience, while its human-centered evaluation focus matches the methodological training I am seeking.',
        'Doctoral training would help me distinguish evidence that a system works from evidence that supports a broader claim about its behavior and its limitations.',
        'Pursuing these questions would require learning to choose appropriate evaluation measures, design defensible comparisons, and interpret the limits of the resulting evidence.',
        'At Indiana University Bloomington, I hope to develop the judgment needed to pursue these questions independently and contribute meaningfully to the laboratory community.',
    );
    $plain = paragraphs(
        'I built the backend for MyClean, a waste collection app in Accra. People pay for it today. That still surprises me.',
        'But a working app is not research. What I want to learn is how to test whether the model inside an app is right, and why it fails when it does.',
        'The Computer Vision Lab runs a project on how people rate photos taken with a phone. That fits me, since most of my own work is phone apps. What I have never done is run a fair study with real users. That is the part I came to learn.',
        'As a teaching assistant, I helped students in programming classes get their code to run. I liked it. In the lab, I would like to do the same.',
    );

    $types = fn ($doc) => array_column((new StyleLinter)->lint($doc, [], 'en-US', ['Indiana University Bloomington']), 'type');

    expect($types($stiff))->toContain('long_sentence', 'long_average', 'few_short_sentences', 'long_words', 'stiff_wording')
        // Plain human prose passes every check, including repeated openings.
        ->and($types($plain))->toBe([]);
});

it('asks for an editorial pass only for two or more fixable problems, or a high one', function () {
    $f = fn (string $type, string $severity = 'medium') => ['type' => $type, 'severity' => $severity, 'message' => '', 'excerpt' => null];

    expect(StyleLinter::needsEditorialPass([$f('long_sentence'), $f('stiff_wording')]))->toBeTrue()
        ->and(StyleLinter::needsEditorialPass([$f('banned_phrase', 'high')]))->toBeTrue()
        ->and(StyleLinter::needsEditorialPass([$f('long_sentence')]))->toBeFalse()
        ->and(StyleLinter::needsEditorialPass([$f('repeated_openings'), $f('consecutive_openings'), $f('variant_spelling')]))->toBeFalse()
        ->and(StyleLinter::needsEditorialPass([$f('long_words', 'low'), $f('stiff_wording', 'low')]))->toBeFalse();
});

it('keeps technical register for CVs and research proposals, and matches protected names as whole words', function () {
    $technical = paragraphs(
        'This proposal examines robust trajectory optimisation for autonomous agricultural vehicles operating under uncertain terrain conditions in smallholder farming environments.',
        'The methodological approach combines stochastic model predictive control with probabilistic terrain classification derived from multispectral imagery and onboard inertial measurements.',
        'Experimental validation will compare controller performance across representative field conditions using established benchmarking protocols and statistically defensible evaluation criteria.',
        'The investigation will additionally document operational deployment constraints observed throughout repeated seasonal trials with participating agricultural cooperatives.',
    );
    $types = fn ($doc, $kind) => array_column((new StyleLinter)->lint($doc, [], 'en-GB', [], $kind), 'type');

    expect($types($technical, DocumentKind::ResearchProposal))->not->toContain('stiff_wording')->not->toContain('long_words')->not->toContain('long_average')
        ->and($types($technical, DocumentKind::Resume))->not->toContain('long_words')
        ->and($types($technical, DocumentKind::PersonalStatement))->toContain('long_words');

    // A programme called "MA" must not cut "human" or "transformative" apart.
    $statement = paragraphs(str_repeat('The work was transformative for human teams and I want to study it in the MA. ', 6));
    expect(array_column((new StyleLinter)->lint($statement, [], 'en-GB', ['MA'], DocumentKind::PersonalStatement), 'type'))->toContain('stiff_wording');
});

it('accepts a wider soft band for a CV, so a concise CV is not sent back for lengthening', function () {
    $checker = new LengthChecker;
    $requirements = new ResolvedRequirements(targetWords: 500);

    // Essay band (60-130% of the target): 280 words is "far" from 500 and would cost a length-fit call.
    expect(array_column($checker->check(paragraphs(str_repeat('word ', 280)), $requirements, null, 500)['violations'], 'type'))->toBe(['target_words'])
        // CV band (50-160%): the same CV is fine, and so is a full two-page one.
        ->and($checker->check(paragraphs(str_repeat('word ', 280)), $requirements, null, 500, [0.5, 1.6])['ok'])->toBeTrue()
        ->and($checker->check(paragraphs(str_repeat('word ', 760)), $requirements, null, 500, [0.5, 1.6])['ok'])->toBeTrue()
        ->and($checker->check(paragraphs(str_repeat('word ', 200)), $requirements, null, 500, [0.5, 1.6])['hard'])->toBeFalse()
        ->and(array_column($checker->check(paragraphs(str_repeat('word ', 200)), $requirements, null, 500, [0.5, 1.6])['violations'], 'type'))->toBe(['target_words']);
});
