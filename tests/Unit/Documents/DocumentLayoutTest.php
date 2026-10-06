<?php

use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentModel;
use Tests\Unit\Documents\Samples;

it('orders an essay: title, subtitle, name, then the body', function () {
    $layout = DocumentLayout::make(Samples::model(), Samples::snapshot());

    expect(array_column($layout->items, 'role'))->toBe(['title', 'subtitle', 'applicant_name', 'heading', 'paragraph', 'paragraph'])
        ->and($layout->text())->toBe("Statement of Purpose\n\nMSc Computer Science\n\nDaniel Essel\n\nBackground\n\nFirst paragraph.\n\nSecond paragraph.")
        ->and($layout->isLetter)->toBeFalse()
        ->and($layout->footer)->toBe([['text' => '{PAGE}', 'align' => 'center', 'page_number' => true]])
        ->and($layout->header)->toBe([]);
});

it('places or hides the name and title as the template says', function () {
    $above = DocumentLayout::make(Samples::model(), Samples::snapshot(['applicant_name_position' => 'above_title']));
    $header = DocumentLayout::make(Samples::model(), Samples::snapshot(['applicant_name_position' => 'header', 'header_text' => '{document_type}']));
    $plain = DocumentLayout::make(Samples::model(), Samples::snapshot(['applicant_name_position' => 'none', 'show_title' => false, 'page_numbers' => 'none']));

    expect(array_slice(array_column($above->items, 'role'), 0, 2))->toBe(['applicant_name', 'title'])
        ->and(array_column($header->items, 'role'))->not->toContain('applicant_name')
        ->and($header->header[0])->toBe(['text' => 'Daniel Essel — Statement of Purpose', 'align' => 'right', 'page_number' => false])
        ->and(array_column($plain->items, 'role'))->toBe(['heading', 'paragraph', 'paragraph'])
        ->and($plain->hidden)->toBe(['title', 'subtitle', 'applicant_name'])
        ->and($plain->footer)->toBe([])
        ->and($plain->visibleApplicantName())->toBeNull()
        ->and($above->visibleApplicantName())->toBe('Daniel Essel');
});

it('lays out letters with the date first and formats ISO dates', function () {
    $model = DocumentModel::fromArray([
        'title' => 'Motivation Letter',
        'applicant_name' => 'Daniel Essel',
        'date' => '2026-10-06',
        'language_variant' => 'en-US',
        'blocks' => [
            ['type' => 'salutation', 'text' => 'Dear Admissions Committee,'],
            ['type' => 'paragraph', 'text' => 'Body.'],
            ['type' => 'closing', 'text' => 'Sincerely,'],
            ['type' => 'signature', 'text' => "Daniel Essel\nAccra, Ghana"],
        ],
    ]);
    $layout = DocumentLayout::make($model, Samples::snapshot(['applicant_name_position' => 'none', 'date_format' => 'F j, Y']));

    expect($layout->isLetter)->toBeTrue()
        ->and(array_column($layout->items, 'role'))->toBe(['date', 'title', 'salutation', 'paragraph', 'closing', 'signature'])
        ->and($layout->items[0]['text'])->toBe('October 6, 2026')
        ->and($layout->items[5]['text'])->toBe("Daniel Essel\nAccra, Ghana")
        ->and($layout->visibleApplicantName())->toBe('Daniel Essel') // shown in the signature
        ->and($layout->format(4)['keep_next'])->toBeTrue()
        ->and($layout->format(4)['after'])->toBeGreaterThanOrEqual(24.0)
        ->and($layout->format(5)['after'])->toBe(0.0);
});

it('formats each paragraph consistently for both renderers', function () {
    $layout = DocumentLayout::make(Samples::model(), Samples::snapshot(['first_line_indent_mm' => 10, 'text_align' => 'justify']));

    expect($layout->format(0))->toMatchArray(['size' => 14.0, 'bold' => true, 'align' => 'center', 'line' => 1.0, 'keep_next' => true])
        ->and($layout->format(1))->toMatchArray(['italic' => true, 'after' => 4.0])
        ->and($layout->format(2))->toMatchArray(['after' => 12.0])        // gap between title area and body
        ->and($layout->format(3))->toMatchArray(['bold' => true, 'before' => 0.0, 'keep_next' => true])
        ->and($layout->format(4))->toMatchArray(['align' => 'justify', 'indent_mm' => 10.0, 'line' => 1.5, 'after' => 8.0])
        ->and($layout->format(5))->toMatchArray(['after' => 0.0]);      // nothing trails the last paragraph
});

it('fills placeholders and tidies separators', function () {
    expect(DocumentLayout::fill('{document_type} — {programme}', ['document_type' => 'Statement of Purpose', 'programme' => null]))->toBe('Statement of Purpose')
        ->and(DocumentLayout::fill('{institution}: {programme} | {applicant_name}', ['institution' => null, 'programme' => 'MSc', 'applicant_name' => 'Ama']))->toBe('MSc | Ama')
        ->and(DocumentLayout::fill('Confidential {unknown} {DATE j-m-Y}', []))->toBe('Confidential DATE j-m-Y')
        ->and(DocumentLayout::pageNumberFormat('Page {pageno} of {nbpg}'))->toBe('Page {PAGE} of {NUMPAGES}')
        ->and(DocumentLayout::pageNumberFormat('Page'))->toBe('Page {PAGE}')
        ->and(DocumentLayout::pageNumberText('Page {PAGE} of {NUMPAGES}', 2, 3))->toBe('Page 2 of 3');
});

it('keeps header and footer clear of the body', function () {
    $layout = DocumentLayout::make(Samples::model(), Samples::snapshot(['margin_top_mm' => 12, 'header_text' => 'Running head', 'page_numbers' => 'top_right']));

    expect($layout->marginalDistance('header'))->toBe(4.0) // pulled up from 12.7 mm towards the edge
        ->and($layout->marginalDistance('footer'))->toBe(12.7)
        ->and(array_column($layout->header, 'text'))->toBe(['Running head', '{PAGE}']);
});
