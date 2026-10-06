<?php

use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\Qa\PdfInspector;
use App\Domain\Documents\Qa\TextNormalizer;
use App\Domain\Documents\Renderers\DocxRenderer;
use App\Domain\Documents\Renderers\PdfRenderer;
use Tests\Unit\Documents\Samples;

it('renders identical text into both formats from one layout', function (array $snapshot) {
    $model = Samples::model(['blocks' => [
        ['type' => 'heading', 'text' => 'Why this programme'],
        ['type' => 'paragraph', 'text' => 'The programme’s focus on “resilient systems” — and its 2026 industry project — fits my goals & experience <exactly>.'],
        ['type' => 'paragraph', 'text' => "A second paragraph with a soft line break\nkept inside it."],
    ]]);
    $layout = DocumentLayout::make($model, Samples::snapshot($snapshot));

    $pdf = app(PdfRenderer::class)->render($layout);
    $docx = app(DocxRenderer::class)->render($layout);
    $opened = app(DocxInspector::class)->open($docx);
    $paragraphs = array_column(app(DocxInspector::class)->paragraphs($opened['parts']['word/document.xml']), 'text');
    // Remove one occurrence of each header/footer line (the name also appears in the body).
    $lines = explode("\n", Samples::pdftotext($pdf['bytes']));
    foreach ([...$layout->header, ...$layout->footer] as $marginal) {
        $index = array_search(DocumentLayout::marginalText($marginal, 1, 1), array_map('trim', $lines), true);
        if ($index !== false) {
            unset($lines[$index]);
        }
    }
    $pdfText = implode("\n", $lines);

    expect($pdf['page_count'])->toBe(1)
        ->and(app(PdfInspector::class)->inspect($pdf['bytes'])['ok'])->toBeTrue()
        ->and($opened['ok'])->toBeTrue()
        ->and($paragraphs)->toBe(array_column($layout->items, 'text'))
        ->and(TextNormalizer::compact($pdfText))->toBe(TextNormalizer::compact(implode("\n", $paragraphs)));
})->with([
    'standard' => [[]],
    'letter paper, sans, justified' => [['page_size' => 'Letter', 'font_family' => 'Arial', 'text_align' => 'justify', 'first_line_indent_mm' => 8, 'page_number_format' => 'Page {PAGE} of {NUMPAGES}']],
    'calibri, header and branding' => [['font_family' => 'Calibri', 'font_size' => 11, 'line_spacing' => 1.15, 'header_text' => '{applicant_name}', 'include_branding' => true, 'page_numbers' => 'top_right']],
]);
