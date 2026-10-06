<?php

use App\Domain\Documents\DocumentFactory;
use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\FontRegistry;
use App\Domain\Documents\Renderers\PdfRenderer;
use App\Domain\Documents\RequirementResolver;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateResolver;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Tests\Feature\Documents\Fixtures;

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
});

afterEach(fn () => Fixtures::removePrivateDisk());

it('passes a freshly rendered version and records every check', function () {
    $version = Fixtures::rendered(Fixtures::order());
    $result = app(DocumentQa::class)->validate($version);
    $version->refresh();

    expect($result->passed)->toBeTrue($result->summary())
        ->and($version->qa_status)->toBe('passed')
        ->and($version->isDeliverable())->toBeTrue()
        ->and($version->qa_results['passed'])->toBeTrue()
        ->and($version->qa_results['mode'])->toBe('full')
        ->and(array_column($result->checks, 'check'))->toContain(
            'files_present', 'file_integrity', 'pdf_opens', 'pdf_size', 'pdf_page_limit', 'pdf_page_size', 'pdf_fonts_embedded',
            'pdf_glyphs', 'pdf_metadata', 'pdf_no_blank_pages', 'pdf_text_matches', 'docx_valid', 'docx_size', 'docx_editable',
            'docx_text_matches', 'docx_fonts', 'docx_page_numbers', 'docx_metadata', 'docx_language', 'word_limits',
            'character_limits', 'section_limits', 'language_variant', 'no_internal_content', 'no_branding',
        );
});

it('catches a PDF whose text was clipped or truncated', function () {
    $version = Fixtures::rendered(Fixtures::order());
    $model = $version->documentModel();

    // A PDF that lost its last paragraph (e.g. a page clipped by a rendering fault).
    $truncated = DocumentModel::fromArray(['blocks' => array_slice($model->blocks, 0, -1)] + $model->toArray());
    $pdf = app(PdfRenderer::class)->render(DocumentLayout::make($truncated, $version->template_snapshot));
    Fixtures::replaceStored($version, 'pdf', $pdf['bytes']);

    $result = app(DocumentQa::class)->validate($version);

    expect($result->passed)->toBeFalse()
        ->and($result->check('pdf_text_matches')['passed'])->toBeFalse()
        ->and($result->check('pdf_text_matches')['detail'])->toContain('missing text')
        ->and($result->check('docx_text_matches')['passed'])->toBeTrue()
        ->and($version->fresh()->qa_status)->toBe('failed')
        ->and($version->fresh()->isDeliverable())->toBeFalse();
});

it('catches a DOCX whose text was edited after rendering', function () {
    $version = Fixtures::rendered(Fixtures::order());
    $edited = Fixtures::withDocxPart(Fixtures::docxBytes($version), 'word/document.xml', fn (string $xml) => str_replace('Raft and Viewstamped Replication', 'Paxos', $xml));
    Fixtures::replaceStored($version, 'docx', $edited, updateChecksum: false);

    $result = app(DocumentQa::class)->validate($version);

    expect($result->check('docx_text_matches')['passed'])->toBeFalse()
        ->and($result->check('docx_text_matches')['detail'])->toContain('Paxos')
        ->and($result->check('file_integrity')['passed'])->toBeFalse()
        ->and($result->check('pdf_text_matches')['passed'])->toBeTrue();
});

it('catches text that appears in a different order or with extra content', function () {
    $version = Fixtures::rendered(Fixtures::order());
    $model = $version->documentModel();
    $blocks = $model->blocks;
    [$blocks[1], $blocks[3]] = [$blocks[3], $blocks[1]];
    $blocks[] = ['type' => 'paragraph', 'text' => 'Research note: check the faculty page before submitting.'];
    $wrong = DocumentModel::fromArray(['blocks' => $blocks] + $model->toArray());
    Fixtures::replaceStored($version, 'pdf', app(PdfRenderer::class)->render(DocumentLayout::make($wrong, $version->template_snapshot))['bytes']);

    $check = app(DocumentQa::class)->validate($version)->check('pdf_text_matches');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain('differs');
});

it('catches blank pages', function () {
    $version = Fixtures::rendered(Fixtures::order());
    $layout = DocumentLayout::make($version->documentModel(), $version->template_snapshot);

    // Same text, followed by an empty page that carries only the page number.
    $mpdf = new Mpdf(['tempDir' => PdfRenderer::tempDir(), 'fontDir' => FontRegistry::mpdfFontDirs(), 'fontdata' => FontRegistry::mpdfFontData(), 'default_font' => 'timesnewroman']);
    $mpdf->SetTitle($layout->title);
    $mpdf->SetHTMLFooter('<div style="text-align:center">{PAGENO}</div>');
    $mpdf->WriteHTML(implode('', array_map(fn ($item) => '<p>'.e($item['text']).'</p>', $layout->items)).'<pagebreak />');
    Fixtures::replaceStored($version, 'pdf', $mpdf->Output('', Destination::STRING_RETURN));

    $result = app(DocumentQa::class)->validate($version);

    expect($result->check('pdf_no_blank_pages')['passed'])->toBeFalse()
        ->and($result->check('pdf_no_blank_pages')['detail'])->toMatch('/Blank page\(s\): \d+/')
        ->and($result->check('pdf_text_matches')['passed'])->toBeTrue();
});

it('re-checks word, character and page limits against the requirements', function () {
    $order = Fixtures::order();
    $requirements = new ResolvedRequirements(maxWords: 150, maxCharacters: 900, maxPages: 1, languageVariant: 'en-GB');
    $version = Fixtures::rendered($order, Fixtures::model(paragraphs: 8), $requirements);

    $result = app(DocumentQa::class)->validate($version);

    expect($result->passed)->toBeFalse()
        ->and($result->check('word_limits')['passed'])->toBeFalse()
        ->and($result->check('word_limits')['detail'])->toContain('at most 150 words')
        ->and($result->check('character_limits')['passed'])->toBeFalse()
        ->and($result->check('pdf_page_limit')['passed'])->toBeFalse()
        ->and($result->check('pdf_text_matches')['passed'])->toBeTrue();

    $within = Fixtures::rendered($order, Fixtures::model(paragraphs: 1, headings: false), new ResolvedRequirements(minWords: 50, maxWords: 150, maxPages: 1, languageVariant: 'en-GB'));
    expect(app(DocumentQa::class)->validate($within)->passed)->toBeTrue();
});

it('rejects research notes, AI citation markers and placeholders left in the text', function (string $leftover) {
    $model = Fixtures::model(['blocks' => [['type' => 'paragraph', 'text' => Fixtures::paragraph(3).' '.$leftover]]]);
    $result = app(DocumentQa::class)->validate(Fixtures::rendered(Fixtures::order(), $model));

    expect($result->check('no_internal_content')['passed'])->toBeFalse()
        ->and($result->passed)->toBeFalse();
})->with([
    'citation marker' => 'This is documented by the department.【4†source】',
    'tool citation' => 'The lab works on edge computing citeturn0search3.',
    'numeric citation' => 'The programme ranks highly [2].',
    'placeholder' => 'I admire the work of [Insert professor name].',
    'research note' => 'Research note: verify module list.',
]);

it('fails when the files are missing', function () {
    $order = Fixtures::order();
    $requirements = app(RequirementResolver::class)->resolve($order);
    $template = app(TemplateResolver::class)->resolve($order, $requirements);
    $version = app(DocumentFactory::class)->createVersion($order, Fixtures::model(), $template, $requirements);

    $result = app(DocumentQa::class)->validate($version);

    expect($result->passed)->toBeFalse()
        ->and($result->check('files_present')['passed'])->toBeFalse()
        ->and($version->fresh()->qa_status)->toBe('failed');
});

it('verifies the DOCX in LibreOffice when enabled', function () {
    config(['statementra.documents.verify_docx_with_libreoffice' => true]);
    $version = Fixtures::rendered(Fixtures::order());

    $result = app(DocumentQa::class)->validate($version);

    expect($result->check('docx_libreoffice'))->not->toBeNull()
        ->and($result->check('docx_libreoffice')['passed'])->toBeTrue($result->check('docx_libreoffice')['detail'])
        ->and($result->passed)->toBeTrue();
})->skip(fn () => ! is_executable('/usr/bin/soffice'), 'LibreOffice is not installed.');
