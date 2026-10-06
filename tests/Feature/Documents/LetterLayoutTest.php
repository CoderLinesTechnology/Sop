<?php

use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\Qa\DocxInspector;
use Illuminate\Support\Carbon;
use Tests\Feature\Documents\Fixtures;

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
    Carbon::setTestNow('2026-10-06 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    Fixtures::removePrivateDisk();
});

it('lays out a motivation letter with date, subject, salutation, closing and signature', function () {
    $version = Fixtures::rendered(Fixtures::motivationOrder(), Fixtures::letter());
    $layout = DocumentLayout::make($version->documentModel(), $version->template_snapshot);

    $expectedOrder = [
        '6 October 2026',
        'Motivation Letter — MSc Data Science',
        'Dear Admissions Committee,',
        Fixtures::paragraph(3),
        Fixtures::paragraph(3, 3),
        Fixtures::paragraph(2, 7),
        'Yours sincerely,',
        'Daniel Essel',
    ];
    $docxParagraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')))->pluck('text')->all();
    $pdfText = Fixtures::pdfText(Fixtures::pdfBytes($version), ['-raw']);

    expect($version->template_snapshot['template_slug'])->toBe('motivation-letter-european')
        ->and($version->documentModel()->date)->toBe('6 October 2026')
        ->and($layout->isLetter)->toBeTrue()
        ->and(array_column($layout->items, 'role'))->toBe(['date', 'title', 'salutation', 'paragraph', 'paragraph', 'paragraph', 'closing', 'signature'])
        ->and($docxParagraphs)->toBe($expectedOrder)
        ->and(Fixtures::compact($pdfText))->toBe(Fixtures::compact(implode("\n", $expectedOrder)))
        ->and(Fixtures::pdfFonts(Fixtures::pdfBytes($version)))->toContain('Carlito')
        ->and(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/styles.xml'))->toContain('w:ascii="Calibri"')
        ->and(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/footer1.xml'))->toBe('') // no page numbers on a letter
        ->and(app(DocumentQa::class)->validate($version)->passed)->toBeTrue();
});

it('formats letters for the destination: US date style and Letter paper', function () {
    $version = Fixtures::rendered(Fixtures::motivationOrder(['country_code' => 'US', 'institution' => 'Columbia University']), Fixtures::letter());

    expect($version->documentModel()->date)->toBe('October 6, 2026')
        ->and($version->language_variant)->toBe('en-US')
        ->and($version->template_snapshot['page_size'])->toBe('Letter')
        ->and($version->template_snapshot['applied_overrides'])->toHaveKey('page_size')
        ->and(Fixtures::pdfInfo(Fixtures::pdfBytes($version)))->toContain('(letter)')
        ->and(app(DocumentQa::class)->validate($version)->passed)->toBeTrue();
});

it('keeps the letter date the writer provided, formatting ISO dates', function () {
    $iso = Fixtures::rendered(Fixtures::motivationOrder(), Fixtures::letter(['date' => '2026-11-02']));
    $written = Fixtures::rendered(Fixtures::motivationOrder(), Fixtures::letter(['date' => 'Munich, 2 November 2026']));

    expect(DocumentLayout::make($iso->documentModel(), $iso->template_snapshot)->items[0])->toBe(['role' => 'date', 'text' => '2 November 2026'])
        ->and(DocumentLayout::make($written->documentModel(), $written->template_snapshot)->items[0]['text'])->toBe('Munich, 2 November 2026');
});

it('keeps the closing with the signature', function () {
    $version = Fixtures::rendered(Fixtures::motivationOrder(), Fixtures::letter());
    $document = Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml');

    preg_match('#<w:p><w:pPr>((?:(?!</w:pPr>).)*)</w:pPr><w:r>(?:(?!</w:r>).)*<w:t xml:space="preserve">Yours sincerely,</w:t>#', $document, $closing);

    expect($closing[1] ?? '')->toContain('<w:keepNext w:val="1"/>')
        ->and($closing[1] ?? '')->toContain('w:after="480"'); // room before the typed name
});
