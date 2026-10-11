<?php

use App\Domain\Ai\Prompts\Schemas;
use App\Domain\Ai\Writing\DraftConverter;
use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocxImporter;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\Qa\TextNormalizer;
use App\Domain\Documents\Renderers\DocxRenderer;
use App\Domain\Documents\Renderers\PdfRenderer;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateSnapshot;
use Smalot\PdfParser\Parser;
use Tests\Unit\Documents\Samples;

/** A CV as the writer returns it: sections, entry lines and bullets. */
function cvModel(array $overrides = []): DocumentModel
{
    return DocumentModel::fromArray($overrides + [
        'title' => 'Resume / CV',
        'applicant_name' => 'Ama Mensah',
        'contact' => 'ama@example.com | +233 24 123 4567 | Accra, Ghana',
        'blocks' => [
            ['type' => 'heading', 'text' => 'Professional Summary'],
            ['type' => 'paragraph', 'text' => 'Science teacher with five years of classroom experience.'],
            ['type' => 'heading', 'text' => 'Professional Experience'],
            ['type' => 'subheading', 'text' => 'Science Teacher | Messiah Baptist JHS | 2023–Present'],
            ['type' => 'bullet', 'text' => 'Raised the BECE science pass rate from 61% to 78% in two years.'],
            ['type' => 'bullet', 'text' => 'Run the after-school science club for 30 pupils.'],
            ['type' => 'subheading', 'text' => 'Teaching Assistant | Accra Academy | 2020–2023'],
            ['type' => 'bullet', 'text' => 'Prepared laboratory practicals for 300 students each term.'],
            ['type' => 'heading', 'text' => 'Skills'],
            ['type' => 'bullet', 'text' => 'Teaching: lesson planning, assessment design'],
        ],
    ]);
}

function cvSnapshot(array $overrides = [], string $kind = 'resume'): array
{
    return Samples::snapshot($overrides + ['heading_rule' => $kind === 'resume', 'font_family' => 'Calibri', 'font_size' => 10.5, 'heading_font_size' => 12], ['document_kind' => $kind, 'document_type' => 'Resume / CV']);
}

/** @return array{xml:array<string,string>, paragraphs:list<array>} */
function docxOf(DocumentLayout $layout): array
{
    $opened = app(DocxInspector::class)->open(app(DocxRenderer::class)->render($layout));
    expect($opened['ok'])->toBeTrue();

    return ['xml' => $opened['parts'], 'paragraphs' => app(DocxInspector::class)->paragraphs($opened['parts']['word/document.xml'])];
}

function pdfBodyHtml(DocumentLayout $layout): string
{
    $method = new ReflectionMethod(PdfRenderer::class, 'bodyHtml');

    return $method->invoke(app(PdfRenderer::class), $layout, 'carlito');
}

it('keeps entry lines and bullets, turns unknown block types into paragraphs and strips typed list markers', function () {
    $model = DocumentModel::fromArray(['title' => 'CV', 'applicant_name' => null, 'blocks' => [
        ['type' => 'subheading', 'text' => 'Analyst | Hubtel | 2021–2023'],
        ['type' => 'bullet', 'text' => '•  Built fraud dashboards.'],
        ['type' => 'bullet', 'text' => '- Cut reporting time by half.'],
        ['type' => 'bullet', 'text' => '•'],
        ['type' => 'table', 'text' => 'Unknown types are kept as text.'],
        ['type' => 'paragraph', 'text' => '- A dash at the start of a paragraph stays.'],
    ]]);

    expect($model->blocks)->toBe([
        ['type' => 'subheading', 'text' => 'Analyst | Hubtel | 2021–2023'],
        ['type' => 'bullet', 'text' => 'Built fraud dashboards.'],
        ['type' => 'bullet', 'text' => 'Cut reporting time by half.'],
        ['type' => 'paragraph', 'text' => 'Unknown types are kept as text.'],
        ['type' => 'paragraph', 'text' => '- A dash at the start of a paragraph stays.'],
    ])
        // Bullets are body text for style checks and word counts; entry lines count as words but are not prose.
        ->and($model->paragraphs())->toBe(['Built fraud dashboards.', 'Cut reporting time by half.', 'Unknown types are kept as text.', '- A dash at the start of a paragraph stays.'])
        ->and($model->wordCount())->toBe(3 + 3 + 5 + 6 + 9)
        ->and(DocumentModel::BLOCK_TYPES)->toContain('subheading', 'bullet');
});

it('stores the contact line with the content only when there is one', function () {
    $with = cvModel();
    $without = cvModel(['contact' => null]);

    expect($with->toArray()['contact'])->toBe('ama@example.com | +233 24 123 4567 | Accra, Ghana')
        ->and(DocumentModel::fromArray($with->toArray())->contact)->toBe($with->contact)
        ->and($with->fullText())->toContain('ama@example.com')
        ->and($with->wordCount())->toBe($without->wordCount()) // never counted against limits
        ->and($without->toArray())->not->toHaveKey('contact')
        ->and(DocumentModel::fromArray(['title' => 'T', 'contact' => "a@b.co\nAccra", 'blocks' => []])->contact)->toBe('a@b.co Accra');
});

it('converts the writer\'s draft blocks, splitting a bullet block that holds several items', function () {
    $model = DraftConverter::toModel(['title' => 'CV', 'blocks' => [
        ['type' => 'heading', 'text' => '## Professional Experience'],
        ['type' => 'subheading', 'text' => '**Analyst | Hubtel | 2021–2023**'],
        ['type' => 'bullet', 'text' => "- Built fraud dashboards.\n- Cut reporting time by half."],
        ['type' => 'quote', 'text' => 'Kept as a paragraph.'],
    ]], 'Resume / CV', 'en-GB', 'Ama Mensah');

    expect($model->blocks)->toBe([
        ['type' => 'heading', 'text' => 'Professional Experience'],
        ['type' => 'subheading', 'text' => 'Analyst | Hubtel | 2021–2023'],
        ['type' => 'bullet', 'text' => 'Built fraud dashboards.'],
        ['type' => 'bullet', 'text' => 'Cut reporting time by half.'],
        ['type' => 'paragraph', 'text' => 'Kept as a paragraph.'],
    ])
        ->and($model->contact)->toBeNull()
        // What the models see of a draft never includes the contact line.
        ->and(json_encode(DraftConverter::forPrompt(cvModel())))->not->toContain('ama@example.com')->not->toContain('123 4567');
});

it('offers the new block types in the structured-output schema', function () {
    $schema = Schemas::for('writing')['schema'];

    expect($schema['properties']['blocks']['items']['properties']['type']['enum'])
        ->toBe(['heading', 'subheading', 'paragraph', 'bullet', 'salutation', 'closing', 'signature']);
});

it('puts the contact line under the name, or after the title when the name is in the page header', function () {
    $below = DocumentLayout::make(cvModel(), cvSnapshot(['applicant_name_position' => 'below_title']));
    $above = DocumentLayout::make(cvModel(), cvSnapshot(['applicant_name_position' => 'above_title', 'show_title' => false]));
    $header = DocumentLayout::make(cvModel(), cvSnapshot(['applicant_name_position' => 'header']));
    $none = DocumentLayout::make(cvModel(['contact' => null]), cvSnapshot(['applicant_name_position' => 'below_title']));

    expect(array_slice(array_column($below->items, 'role'), 0, 4))->toBe(['title', 'applicant_name', 'contact', 'heading'])
        ->and(array_slice(array_column($above->items, 'role'), 0, 3))->toBe(['applicant_name', 'contact', 'heading'])
        ->and(array_slice(array_column($header->items, 'role'), 0, 3))->toBe(['title', 'contact', 'heading'])
        ->and(array_column($none->items, 'role'))->not->toContain('contact')
        // Front matter: name and contact line close together, aligned like the name, then the gap before the body.
        ->and($below->format(1))->toMatchArray(['after' => 4.0, 'align' => 'center'])
        ->and($below->format(2))->toMatchArray(['bold' => false, 'line' => 1.0, 'align' => 'center', 'after' => 12.0, 'size' => 10.5]);
});

it('formats entry lines, bullets and the rule under CV section headings', function () {
    $cv = DocumentLayout::make(cvModel(), cvSnapshot(['applicant_name_position' => 'header', 'paragraph_spacing_pt' => 8]));
    $roles = array_column($cv->items, 'role');
    $at = fn (string $role, int $nth = 0) => array_keys($roles, $role, true)[$nth];

    expect($cv->format($at('heading'))['rule'])->toBeTrue()
        ->and($cv->format($at('subheading')))->toMatchArray(['bold' => true, 'size' => 11.0, 'before' => 0.0, 'after' => 2.0, 'keep_next' => true, 'rule' => false])
        ->and($cv->format($at('subheading', 1)))->toMatchArray(['before' => 4.0])     // a new entry after bullets
        ->and($cv->format($at('bullet')))->toMatchArray(['after' => 2.0, 'bold' => false, 'indent_mm' => 0.0])
        ->and($cv->format($at('bullet', 1)))->toMatchArray(['after' => 8.0])          // the list ends: paragraph gap
        ->and($cv->startsList($at('bullet')))->toBeTrue()
        ->and($cv->endsList($at('bullet')))->toBeFalse()
        ->and($cv->endsList($at('bullet', 1)))->toBeTrue()
        ->and($cv->subheadingSize())->toBe(11.0);

    // Other documents keep plain headings.
    $statement = DocumentLayout::make(Samples::model(), Samples::snapshot());
    expect($statement->format(3)['rule'])->toBeFalse()
        ->and(DocumentLayout::make(cvModel(), Samples::snapshot(['heading_font_size' => 12, 'font_size' => 12]))->subheadingSize())->toBe(12.0);
});

it('decides the heading rule once, from the document kind, when the snapshot is made', function () {
    $requirements = new ResolvedRequirements(languageVariant: 'en-GB');
    $template = Samples::template();

    expect(TemplateSnapshot::make($template, $requirements, ['document_kind' => 'resume'])['heading_rule'])->toBeTrue()
        ->and(TemplateSnapshot::make($template, $requirements, ['document_kind' => 'personal_statement'])['heading_rule'])->toBeFalse()
        ->and(TemplateSnapshot::make($template, $requirements, ['document_kind' => 'cover_letter'])['heading_rule'])->toBeFalse()
        // Snapshots stored before the option existed (even of CVs) keep rendering without it.
        ->and(TemplateSnapshot::normalize(['context' => ['document_kind' => 'resume']])['heading_rule'])->toBeFalse();
});

it('renders bullets as one real list per run and draws the heading rule in the PDF', function () {
    $layout = DocumentLayout::make(cvModel(), cvSnapshot(['applicant_name_position' => 'header']));
    $html = pdfBodyHtml($layout);
    $pdf = app(PdfRenderer::class)->render($layout);
    // Extracted text without the page header (the name) and footer (the page number).
    $lines = array_filter(explode("\n", (new Parser)->parseContent($pdf['bytes'])->getText()), fn (string $line) => ! in_array(trim($line), ['Ama Mensah', '1'], true));
    $text = implode("\n", $lines);

    expect(substr_count($html, '<ul '))->toBe(3)                 // two experience lists and the skills list
        ->and(substr_count($html, '<li '))->toBe(4)
        ->and(substr_count($html, '</ul>'))->toBe(3)
        ->and(substr_count($html, 'border-bottom: 0.5pt solid'))->toBe(3)   // one per section heading
        ->and($html)->toContain('<p style="font-family: carlito; font-size: 11.00pt')  // entry line
        ->and($pdf['page_count'])->toBe(1)
        // The bullet is drawn, not typed: the extracted text is exactly the approved content.
        ->and($text)->not->toContain('•')
        ->and(TextNormalizer::compact($text))->toBe(TextNormalizer::compact($layout->text()));

    // Documents without the new blocks render the same markup as before: no lists, no rules.
    $statementHtml = pdfBodyHtml(DocumentLayout::make(Samples::model(), Samples::snapshot()));
    expect($statementHtml)->not->toContain('<ul')->not->toContain('border-bottom');
});

it('renders bullets as a Word list, entry lines as Heading 2 and the CV heading rule as a bottom border', function () {
    $layout = DocumentLayout::make(cvModel(), cvSnapshot(['applicant_name_position' => 'header']));
    ['xml' => $xml, 'paragraphs' => $paragraphs] = docxOf($layout);
    $document = $xml['word/document.xml'];
    $styles = $xml['word/styles.xml'];

    preg_match('#<w:style w:type="paragraph" w:styleId="Heading1">.*?</w:style>#s', $styles, $heading1);

    expect(array_column($paragraphs, 'text'))->toBe(array_column($layout->items, 'text'))
        ->and(array_column($paragraphs, 'list'))->toBe(array_map(fn ($item) => $item['role'] === 'bullet', $layout->items))
        ->and(array_column($paragraphs, 'style'))->toContain('Heading2', 'Contact')
        ->and(substr_count($document, '<w:numPr>'))->toBe(4)
        ->and($document)->not->toContain('•')                     // no typed bullets
        ->and($heading1[0])->toContain('<w:pBdr><w:bottom w:val="single" w:sz="4" w:color="000000" w:space="1"/></w:pBdr>');

    // A statement keeps its plain Heading 1 and has no list.
    ['xml' => $plain] = docxOf(DocumentLayout::make(Samples::model(), Samples::snapshot()));
    expect($plain['word/styles.xml'])->not->toContain('<w:pBdr>')
        ->and($plain['word/document.xml'])->not->toContain('<w:numPr>')
        ->and($plain['word/styles.xml'])->not->toContain('w:styleId="Heading2"');
});

it('reads an uploaded CV back into the same blocks: lists as bullets, Heading 2 as entry lines, the contact line', function () {
    $layout = DocumentLayout::make(cvModel(), cvSnapshot(['applicant_name_position' => 'below_title']));
    $docx = app(DocxRenderer::class)->render($layout);

    $import = app(DocxImporter::class)->import($docx, 'Resume / CV', 'en-GB', 'Ama Mensah');

    expect($import['model']->blocks)->toBe(cvModel()->blocks)
        ->and($import['model']->contact)->toBe('ama@example.com | +233 24 123 4567 | Accra, Ghana')
        ->and($import['has_title'])->toBeTrue()
        ->and($import['has_applicant_name'])->toBeTrue();
});
