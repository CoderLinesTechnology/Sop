<?php

use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\ResolvedRequirements;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Documents\Fixtures;

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
});

afterEach(fn () => Fixtures::removePrivateDisk());

it('renders the PDF and the DOCX from one model with identical text', function () {
    $model = Fixtures::model();
    $version = Fixtures::rendered(Fixtures::order(), $model);
    $layout = DocumentLayout::make($version->documentModel(), $version->template_snapshot);

    // PDF text in drawing order, hyphens kept (page numbers are the only extra lines).
    $pdfText = Fixtures::pdfText(Fixtures::pdfBytes($version), ['-raw']);
    $pdfBody = preg_replace('/^\s*\d+\s*$/m', '', $pdfText);

    // DOCX: real paragraphs of real text, one per block, in the same order.
    $paragraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')))
        ->pluck('text')->all();

    expect(Fixtures::compact($pdfBody))->toBe(Fixtures::compact($layout->text()))
        ->and($paragraphs)->toBe(array_column($layout->items, 'text'))
        ->and($paragraphs[0])->toBe('Statement of Purpose')
        ->and($paragraphs[1])->toBe('Daniel Essel')
        ->and(array_slice($paragraphs, 2))->toBe(array_column($model->blocks, 'text'));
});

it('stores both files encrypted in the private vault with clean names and checksums', function () {
    $version = Fixtures::rendered(Fixtures::order());

    $pdf = Fixtures::pdfBytes($version);
    $docx = Fixtures::docxBytes($version);
    $raw = Storage::disk('private')->get($version->pdf_path);

    expect($version->files_disk)->toBe('private')
        ->and($version->files_encrypted)->toBeTrue()
        // Random paths: no names, ids or order references.
        ->and($version->pdf_path)->toMatch('#^documents/\d{4}/\d{2}/[0-9a-f]{32}\.pdf\.enc$#')
        ->and($version->docx_path)->toMatch('#^documents/\d{4}/\d{2}/[0-9a-f]{32}\.docx\.enc$#')
        ->and($raw)->not->toStartWith('%PDF')
        ->and($pdf)->toStartWith('%PDF-')
        ->and($docx)->toStartWith("PK\x03\x04")
        ->and($version->pdf_size)->toBe(strlen($pdf))
        ->and($version->docx_size)->toBe(strlen($docx))
        ->and($version->pdf_sha256)->toBe(hash('sha256', $pdf))
        ->and($version->docx_sha256)->toBe(hash('sha256', $docx))
        ->and($version->pdf_filename)->toBe('Daniel_Essel_Statement_of_Purpose.pdf')
        ->and($version->docx_filename)->toBe('Daniel_Essel_Statement_of_Purpose.docx')
        ->and($version->page_count)->toBeGreaterThanOrEqual(1)
        ->and($version->rendered_at)->not->toBeNull()
        ->and(Storage::disk('public')->exists($version->pdf_path))->toBeFalse();
});

it('creates sequential versions with the snapshots and counts the pipeline needs', function () {
    $order = Fixtures::order();
    $first = Fixtures::rendered($order);
    $second = Fixtures::rendered($order, Fixtures::model(['title' => 'Statement of Purpose']), template: null);

    expect($first->version_number)->toBe(1)
        ->and($second->version_number)->toBe(2)
        ->and($second->document_id)->toBe($first->document_id)
        ->and($first->document->kind)->toBe('statement_of_purpose')
        ->and($first->status)->toBe('draft')
        ->and($first->source)->toBe('ai')
        ->and($first->word_count)->toBe($first->documentModel()->wordCount())
        ->and($first->char_count)->toBe($first->documentModel()->characterCount())
        ->and($first->plain_text)->toContain('Statement of Purpose')
        ->and($first->language_variant)->toBe('en-GB')
        ->and($first->template_snapshot['template_slug'])->toBe('standard-a4')
        ->and($first->template_snapshot['font_family'])->toBe('Times New Roman')
        ->and($first->requirements_snapshot['languageVariant'])->toBe('en-GB')
        ->and($first->document_template_id)->not->toBeNull();
});

it('uses the page size, embedded fonts and neutral metadata of the template', function () {
    $gb = Fixtures::rendered(Fixtures::order(['country_code' => 'GB']));
    $us = Fixtures::rendered(Fixtures::order(['country_code' => 'US', 'institution' => 'Carnegie Mellon University']));

    $gbInfo = Fixtures::pdfInfo(Fixtures::pdfBytes($gb));
    $usInfo = Fixtures::pdfInfo(Fixtures::pdfBytes($us));
    $fonts = Fixtures::pdfFonts(Fixtures::pdfBytes($gb));

    expect($gbInfo)->toMatch('/Page size:\s+595\.\d+ x 841\.\d+ pts \(A4\)/')
        ->and($usInfo)->toMatch('/Page size:\s+612 x 792 pts \(letter\)/')
        ->and($us->template_snapshot['template_slug'])->toBe('us-letter')
        ->and($gbInfo)->toMatch('/Title:\s+Statement of Purpose/')
        ->and($gbInfo)->toMatch('/Author:\s+Daniel Essel/')
        ->and($gbInfo)->toMatch('/Producer:\s+mPDF\s*$/m')
        ->and($gbInfo)->not->toContain('Keywords')
        ->and($gbInfo)->not->toContain('Creator')
        ->and($fonts)->toContain('LiberationSerif')
        ->and($fonts)->not->toMatch('/\bno\s+(yes|no)\s+(yes|no)\s+\d+/'); // every font embedded

    $docx = Fixtures::docxBytes($gb);
    $styles = Fixtures::docxPart($docx, 'word/styles.xml');
    $app = Fixtures::docxPart($docx, 'docProps/app.xml');
    $footer = Fixtures::docxPart($docx, 'word/footer1.xml');
    $document = Fixtures::docxPart($docx, 'word/document.xml');

    expect($styles)->toContain('w:ascii="Times New Roman"')
        ->and($styles)->toContain('<w:lang w:val="en-GB"')
        ->and($app)->not->toContain('PHPWord')
        ->and($footer)->toContain('PAGE')
        ->and($document)->toContain('<w:pgSz w:orient="portrait" w:w="11906" w:h="16838"/>')
        ->and($document)->toContain('w:top="1440"')
        ->and($document)->not->toContain('<w:drawing')
        ->and(Fixtures::docxPart(Fixtures::docxBytes($us), 'word/document.xml'))->toContain('w:w="12240" w:h="15840"');
});

it('applies requirement overrides at render time and records them in the snapshot', function () {
    $order = Fixtures::order();
    $requirements = new ResolvedRequirements(pageSize: 'Letter', fontFamily: 'Helvetica', fontSize: 11, marginsMm: 20, lineSpacing: 2.0, languageVariant: 'en-GB');
    $version = Fixtures::rendered($order, requirements: $requirements);
    $snapshot = $version->template_snapshot;

    expect($snapshot['page_size'])->toBe('Letter')
        ->and($snapshot['font_family'])->toBe('Arial')
        ->and($snapshot['font_size'])->toEqual(11.0)
        ->and($snapshot['margin_left_mm'])->toEqual(20.0)
        ->and($snapshot['line_spacing'])->toEqual(2.0)
        ->and(array_keys($snapshot['applied_overrides']))->toContain('page_size', 'font_family', 'font_size', 'margin_top_mm', 'line_spacing')
        ->and($snapshot['applied_overrides']['font_family'])->toMatchArray(['template' => 'Times New Roman', 'applied' => 'Arial'])
        ->and(Fixtures::pdfFonts(Fixtures::pdfBytes($version)))->toContain('LiberationSans')
        ->and(Fixtures::pdfInfo(Fixtures::pdfBytes($version)))->toContain('(letter)')
        ->and(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml'))->toContain('w:line="480"')
        ->and(app(DocumentQa::class)->validate($version)->passed)->toBeTrue();
});

it('wraps long words and URLs without clipping and adds no trailing blank page', function () {
    $model = Fixtures::model(['blocks' => [
        ['type' => 'paragraph', 'text' => 'My portfolio is at https://www.example.org/projects/distributed-systems/consensus/raft-vs-viewstamped-replication/benchmarks/2026/results?format=full&lang=en-GB and is updated weekly.'],
        ['type' => 'paragraph', 'text' => 'Pneumonoultramicroscopicsilicovolcanoconiosis'.str_repeat('X', 90).' ends the paragraph.'],
        ['type' => 'paragraph', 'text' => Fixtures::paragraph(6)],
    ]]);
    $version = Fixtures::rendered(Fixtures::order(), $model);
    $result = app(DocumentQa::class)->validate($version);

    expect($result->check('pdf_text_matches')['passed'])->toBeTrue()
        ->and($result->check('pdf_no_blank_pages')['passed'])->toBeTrue()
        ->and($result->passed)->toBeTrue()
        ->and($version->page_count)->toBe(1);
});

it('keeps headings with the following paragraph and spaces both files alike', function () {
    $version = Fixtures::rendered(Fixtures::order());
    $document = Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml');

    // Headings use the built-in Heading 1 style and keep with the next paragraph.
    expect($document)->toMatch('#<w:pStyle w:val="Heading1"/><w:keepNext w:val="1"/><w:keepLines w:val="1"/>#')
        ->and($document)->toMatch('#<w:pStyle w:val="Title"/><w:keepNext w:val="1"/>#')
        ->and($document)->toContain('w:after="160" w:line="360" w:lineRule="auto"') // 8 pt after, 1.5 lines
        ->and($document)->not->toContain('w:type="page"');                       // no manual page breaks
});

it('keeps literal braces in the text instead of turning them into page numbers', function () {
    $model = Fixtures::model(['blocks' => [['type' => 'paragraph', 'text' => 'Template engines use markers such as {PAGENO}, {nb} and {nbpg}; I built one in my second year.']]]);
    $version = Fixtures::rendered(Fixtures::order(), $model);

    expect(Fixtures::compact(Fixtures::pdfText(Fixtures::pdfBytes($version))))->toContain('{PAGENO},{nb}and{nbpg}')
        ->and(app(DocumentQa::class)->validate($version)->passed)->toBeTrue();
});
