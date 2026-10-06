<?php

use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\WordCounter;
use Tests\Feature\Documents\Fixtures;

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
});

afterEach(fn () => Fixtures::removePrivateDisk());

it('renders the three UCAS questions as headings with no title or page numbers', function () {
    $version = Fixtures::rendered(Fixtures::ucasOrder(), Fixtures::ucasStatement([1300, 1300, 1200]));
    $paragraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')));
    $result = app(DocumentQa::class)->validate($version);

    expect($version->template_snapshot['template_slug'])->toBe('ucas-personal-statement')
        ->and($paragraphs->where('style', 'Heading1')->pluck('text')->all())->toBe(Fixtures::UCAS_QUESTIONS)
        ->and($paragraphs->pluck('text')->all())->not->toContain('Personal Statement', 'Amara Okafor')
        ->and(Fixtures::pdfText(Fixtures::pdfBytes($version)))->not->toContain('Personal Statement')
        ->and(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/footer1.xml'))->toBe('')
        ->and(Fixtures::pdfFonts(Fixtures::pdfBytes($version)))->toContain('LiberationSans')
        ->and($version->pdf_filename)->toBe('Amara_Okafor_Personal_Statement.pdf')
        ->and($result->passed)->toBeTrue($result->summary())
        ->and($result->check('section_limits')['detail'])->toContain('All 3 required sections');
});

it('counts only the answers towards the 4,000-character limit', function () {
    $model = Fixtures::ucasStatement([1300, 1300, 1290]);
    $answers = WordCounter::characters($model->answerText());
    $withQuestions = WordCounter::characters($model->bodyText());

    $result = app(DocumentQa::class)->validate(Fixtures::rendered(Fixtures::ucasOrder(), $model));

    expect($answers)->toBeLessThanOrEqual(4000)
        ->and($withQuestions)->toBeGreaterThan(4000)
        ->and($result->check('character_limits')['passed'])->toBeTrue()
        ->and($result->check('character_limits')['detail'])->toContain('answers only');

    $tooLong = app(DocumentQa::class)->validate(Fixtures::rendered(Fixtures::ucasOrder(), Fixtures::ucasStatement([1600, 1600, 1500])));
    expect($tooLong->check('character_limits')['passed'])->toBeFalse();
});

it('enforces the 350-character minimum for every answer', function () {
    $result = app(DocumentQa::class)->validate(Fixtures::rendered(Fixtures::ucasOrder(), Fixtures::ucasStatement([1500, 200, 1500])));

    expect($result->check('section_limits')['passed'])->toBeFalse()
        ->and($result->check('section_limits')['detail'])->toContain('minimum 350')
        ->and($result->check('section_limits')['detail'])->toContain(Fixtures::UCAS_QUESTIONS[1]);
});

it('reports a missing UCAS question', function () {
    $result = app(DocumentQa::class)->validate(Fixtures::rendered(Fixtures::ucasOrder(), Fixtures::ucasStatement([1800, 1800])));

    expect($result->check('section_limits')['passed'])->toBeFalse()
        ->and($result->check('section_limits')['detail'])->toContain('missing section')->toContain(Fixtures::UCAS_QUESTIONS[2]);
});
