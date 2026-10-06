<?php

use App\Domain\Ai\Writing\LengthChecker;
use App\Domain\Ai\Writing\StyleLinter;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\ResolvedRequirements;

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
