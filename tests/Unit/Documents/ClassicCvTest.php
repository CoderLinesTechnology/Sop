<?php

use App\Domain\Documents\ContactLine;
use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocxImporter;
use App\Domain\Documents\EntryLine;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\Qa\TextNormalizer;
use App\Domain\Documents\Renderers\DocxRenderer;
use App\Domain\Documents\Renderers\PdfRenderer;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateSnapshot;
use App\Models\DocumentTemplate;
use Database\Seeders\DocumentConfigurationSeeder;
use Smalot\PdfParser\Parser;
use Tests\Unit\Documents\Samples;

/** A fictional CV with every part the classic layout styles. */
function classicCvModel(array $overrides = []): DocumentModel
{
    return DocumentModel::fromArray($overrides + [
        'title' => 'Ama Mensah',
        'applicant_name' => 'Ama Mensah',
        'contact' => 'ama@example.com | +233 24 123 4567 | Accra, Ghana | linkedin.com/in/ama-example',
        'blocks' => [
            ['type' => 'heading', 'text' => 'Professional Summary'],
            ['type' => 'paragraph', 'text' => 'Science teacher with five years of classroom experience in junior high schools, known for practical lessons and steady examination results.'],
            ['type' => 'heading', 'text' => 'Professional Experience'],
            ['type' => 'subheading', 'text' => 'Science Teacher | Messiah Baptist JHS | 2023 – Present'],
            ['type' => 'bullet', 'text' => 'Raised the BECE science pass rate from 61% to 78% in two years.'],
            ['type' => 'bullet', 'text' => 'Run the after-school science club for 30 pupils.'],
            ['type' => 'subheading', 'text' => 'WASSCE | 2014 – 2017'],
            ['type' => 'subheading', 'text' => 'Volunteer Tutor | Accra Reading Club'],
            ['type' => 'heading', 'text' => 'Skills'],
            ['type' => 'bullet', 'text' => 'Teaching: lesson planning, assessment design'],
        ],
    ]);
}

/** The seeded "Classic Professional CV" snapshot for a CV (no database). */
function classicSnapshot(array $template = []): array
{
    return TemplateSnapshot::make(
        (new DocumentTemplate)->forceFill($template + DocumentConfigurationSeeder::classicCv()),
        new ResolvedRequirements(languageVariant: 'en-GB'),
        ['document_kind' => 'resume', 'document_type' => 'Resume / CV'],
    );
}

/** @return array{xml:array<string,string>, paragraphs:list<array>, bytes:string} */
function classicDocx(DocumentLayout $layout): array
{
    $bytes = app(DocxRenderer::class)->render($layout);
    $opened = app(DocxInspector::class)->open($bytes);
    expect($opened['ok'])->toBeTrue();

    return ['xml' => $opened['parts'], 'paragraphs' => app(DocxInspector::class)->paragraphs($opened['parts']['word/document.xml']), 'bytes' => $bytes];
}

/** WCAG 2 contrast ratio of a colour on white. */
function contrastOnWhite(string $hex): float
{
    $channel = function (int $value): float {
        $c = $value / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    $luminance = 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);

    return 1.05 / ($luminance + 0.05);
}

it('reads the last part of an entry line as dates only when it looks like dates', function (string $text, array $expected, string $display) {
    expect(EntryLine::parse($text))->toBe($expected)
        ->and(EntryLine::display(EntryLine::parse($text)))->toBe($display);
})->with([
    'role, organisation, dates' => ['Science Teacher | Messiah Baptist JHS | 2023–Present', ['role' => 'Science Teacher', 'organisation' => 'Messiah Baptist JHS', 'dates' => '2023–Present'], "Science Teacher | Messiah Baptist JHS\t[2023–Present]"],
    'months' => ['Analyst | Hubtel | Sept 2019 – Aug 2020', ['role' => 'Analyst', 'organisation' => 'Hubtel', 'dates' => 'Sept 2019 – Aug 2020'], "Analyst | Hubtel\t[Sept 2019 – Aug 2020]"],
    'role and dates' => ['WASSCE | 2014 – 2017', ['role' => 'WASSCE', 'organisation' => null, 'dates' => '2014 – 2017'], "WASSCE\t[2014 – 2017]"],
    'role and organisation' => ['Analyst | Hubtel', ['role' => 'Analyst', 'organisation' => 'Hubtel', 'dates' => null], 'Analyst | Hubtel'],
    'role only' => ['Volunteer Tutor', ['role' => 'Volunteer Tutor', 'organisation' => null, 'dates' => null], 'Volunteer Tutor'],
    'a place is not a date' => ['Analyst | Hubtel | Accra, 2021', ['role' => 'Analyst', 'organisation' => 'Hubtel | Accra, 2021', 'dates' => null], 'Analyst | Hubtel | Accra, 2021'],
    'four parts' => ['Analyst | Hubtel | Accra | 2021 – 2023', ['role' => 'Analyst', 'organisation' => 'Hubtel | Accra', 'dates' => '2021 – 2023'], "Analyst | Hubtel | Accra\t[2021 – 2023]"],
    'already bracketed' => ['Analyst | Hubtel | [2019 - 2023]', ['role' => 'Analyst', 'organisation' => 'Hubtel', 'dates' => '2019 - 2023'], "Analyst | Hubtel\t[2019 - 2023]"],
    'read back from a styled file' => ['Analyst | Hubtel [2021 – Present]', ['role' => 'Analyst', 'organisation' => 'Hubtel', 'dates' => '2021 – Present'], "Analyst | Hubtel\t[2021 – Present]"],
]);

it('shows the stored contact line as a two-line block with labels and marks the links, dropping nothing', function () {
    $line = 'ama@example.com | +233 24 123 4567 | Accra, Ghana | linkedin.com/in/ama-example | ama.dev/work';

    expect(ContactLine::runs($line, 'two_lines'))->toBe([
        ['text' => 'ama@example.com', 'link' => true],
        ['text' => " | Tel: +233 24 123 4567 | Accra, Ghana\n", 'link' => false],
        ['text' => 'LinkedIn: linkedin.com/in/ama-example', 'link' => true],
        ['text' => ' | ', 'link' => false],
        ['text' => 'Website: ama.dev/work', 'link' => true],
    ])
        // One line: exactly the stored text, links marked.
        ->and(implode('', array_column(ContactLine::runs($line), 'text')))->toBe($line)
        ->and(array_column(array_filter(ContactLine::runs($line), fn ($run) => $run['link']), 'text'))->toBe(['ama@example.com', 'linkedin.com/in/ama-example', 'ama.dev/work'])
        // Only a LinkedIn: one line; labels are not repeated when the stored line already has them.
        ->and(ContactLine::runs('linkedin.com/in/ama', 'two_lines'))->toBe([['text' => 'LinkedIn: linkedin.com/in/ama', 'link' => true]])
        ->and(ContactLine::runs('ama@example.com | Tel: +233 24 123 4567 | LinkedIn: linkedin.com/in/ama', 'two_lines'))
        ->toBe([['text' => 'ama@example.com', 'link' => true], ['text' => " | Tel: +233 24 123 4567\n", 'link' => false], ['text' => 'LinkedIn: linkedin.com/in/ama', 'link' => true]])
        // Unrecognised text keeps its own words.
        ->and(implode('', array_column(ContactLine::runs('Tel: on request | ama@example.com', 'two_lines'), 'text')))->toBe('Tel: on request | ama@example.com')
        // A block read back from a file is stored as one line.
        ->and(ContactLine::fromDisplay("ama@example.com | Tel: +233 24 123 4567\nLinkedIn: linkedin.com/in/ama"))->toBe('ama@example.com | Tel: +233 24 123 4567 | LinkedIn: linkedin.com/in/ama')
        ->and(ContactLine::fromDisplay('ama@example.com | Accra'))->toBe('ama@example.com | Accra');
});

it('normalises the presentation options, and anything without them keeps the original look', function () {
    $old = TemplateSnapshot::normalize(['font_family' => 'Calibri', 'context' => ['document_kind' => 'resume']]);
    $untouched = TemplateSnapshot::fromTemplate(Samples::template());
    $odd = TemplateSnapshot::normalize([
        'heading_color' => '2f5496', 'link_color' => 'red', 'heading_rule_color' => 'javascript:x', 'entry_detail_color' => '#59595',
        'contact_layout' => 'three_lines', 'bullet_align' => 'centre', 'title_uppercase' => '1',
    ]);
    $original = ['title_uppercase' => false, 'heading_color' => null, 'heading_uppercase' => false, 'heading_rule_color' => null,
        'contact_layout' => 'single_line', 'link_color' => null, 'entry_line_style' => false, 'entry_detail_color' => null, 'bullet_align' => null];

    expect(array_intersect_key($old, $original))->toBe($original)
        ->and(array_intersect_key($untouched, $original))->toBe($original)
        ->and($odd)->toMatchArray(['heading_color' => '#2F5496', 'link_color' => null, 'heading_rule_color' => null, 'entry_detail_color' => null, 'contact_layout' => 'single_line', 'bullet_align' => null, 'title_uppercase' => true])
        ->and(TemplateSnapshot::toTemplate(classicSnapshot())->entry_line_style)->toBeTrue()
        ->and(classicSnapshot())->toMatchArray([
            'font_family' => 'Times New Roman', 'font_size' => 12.0, 'line_spacing' => 1.0, 'title_font_size' => 16.0, 'heading_font_size' => 12.0,
            'title_uppercase' => true, 'heading_color' => '#2F5496', 'heading_uppercase' => true, 'heading_rule_color' => '#2F5496', 'heading_rule' => true,
            'contact_layout' => 'two_lines', 'link_color' => '#2F5496', 'entry_line_style' => true, 'entry_detail_color' => '#595959',
            'text_align' => 'justify', 'bullet_align' => 'left', 'page_numbers' => 'none', 'applicant_name_position' => 'none',
        ]);
});

it('lays out the classic CV: the name and headings in capitals, a two-line contact block, styled entry lines and ragged bullets', function () {
    $layout = DocumentLayout::make(classicCvModel(), classicSnapshot());
    $roles = array_column($layout->items, 'role');
    $at = fn (string $role, int $nth = 0) => array_keys($roles, $role, true)[$nth];

    expect(array_slice($roles, 0, 3))->toBe(['title', 'contact', 'heading'])
        ->and($layout->format(0))->toMatchArray(['caps' => true, 'size' => 16.0, 'bold' => true, 'align' => 'center'])
        ->and($layout->format($at('contact')))->toMatchArray(['align' => 'center', 'caps' => false])
        ->and($layout->items[$at('contact')]['text'])->toBe("ama@example.com | Tel: +233 24 123 4567 | Accra, Ghana\nLinkedIn: linkedin.com/in/ama-example")
        ->and($layout->format($at('heading')))->toMatchArray(['caps' => true, 'color' => '#2F5496', 'rule' => true, 'rule_color' => '#2F5496', 'size' => 12.0])
        ->and($layout->items[$at('subheading')])->toBe(['role' => 'subheading', 'text' => "Science Teacher | Messiah Baptist JHS\t[2023 – Present]",
            'runs' => [['text' => 'Science Teacher', 'color' => null], ['text' => ' | Messiah Baptist JHS', 'color' => '#595959']],
            'tab' => ['text' => '[2023 – Present]', 'color' => '#595959']])
        ->and($layout->items[$at('subheading', 1)]['text'])->toBe("WASSCE\t[2014 – 2017]")
        ->and($layout->items[$at('subheading', 2)])->not->toHaveKey('tab')
        ->and($layout->format($at('paragraph'))['align'])->toBe('justify')
        ->and($layout->format($at('bullet'))['align'])->toBe('left')
        // The text is unchanged apart from presentation: capitals are drawn, not written.
        ->and($layout->text())->toContain('Professional Summary')->not->toContain('PROFESSIONAL SUMMARY')
        ->and($layout->renderedText())->toContain("AMA MENSAH\n\nama@example.com")->toContain('PROFESSIONAL SUMMARY')->toContain('Science teacher with five years')
        ->and($layout->visibleApplicantName())->toBe('Ama Mensah')
        ->and(classicCvModel()->fullText())->not->toContain('Tel:')->not->toContain('[2023');
});

it('renders the classic CV to PDF: blue capitals over a blue rule, the contact block, grey organisation and right-aligned dates', function () {
    $layout = DocumentLayout::make(classicCvModel(), classicSnapshot());
    $html = (new ReflectionMethod(PdfRenderer::class, 'bodyHtml'))->invoke(app(PdfRenderer::class), $layout, 'timesnewroman');
    $pdf = app(PdfRenderer::class)->render($layout);
    $text = (new Parser)->parseContent($pdf['bytes'])->getText();

    expect($html)->toContain('>AMA MENSAH</p>')
        ->toContain('<span style="color: #2F5496">ama@example.com</span> | Tel: +233 24 123 4567 | Accra, Ghana<br>')
        ->toContain('<span style="color: #2F5496">LinkedIn: linkedin.com/in/ama-example</span>')
        ->toContain('color: #2F5496">PROFESSIONAL SUMMARY</p>')
        ->toContain('border-bottom: 0.5pt solid #2F5496')
        ->toContain('Science Teacher<span style="color: #595959"> | Messiah Baptist JHS</span></td>')
        ->toContain('text-align: right; white-space: nowrap; padding-left: 6pt;"><span style="color: #595959">[2023 – Present]</span></td>')
        ->and(substr_count($html, '<table autosize="1"'))->toBe(2) // only the entry lines with dates
        // Kept with the first bullet (a table cannot use page-break-after); the list goes on after it.
        ->and($html)->toContain('<div style="page-break-inside: avoid; margin: 0; padding: 0;"><table autosize="1"')
        ->and($html)->toContain('Raised the BECE science pass rate from 61% to 78% in two years.</li></ul></div><ul style="list-style-type: disc;')
        ->and(substr_count($html, '<div style="page-break-inside: avoid;'))->toBe(substr_count($html, '</div>'))
        ->and(substr_count($html, '<ul '))->toBe(3)
        ->and($pdf['page_count'])->toBe(1)
        // What QA reads back is exactly the layout as drawn.
        ->and(TextNormalizer::compact($text))->toBe(TextNormalizer::compact($layout->renderedText()));
});

it('never leaves an entry line alone at the bottom of a PDF page', function () {
    $blocks = [['type' => 'heading', 'text' => 'Experience']];
    for ($i = 1; $i <= 16; $i++) {
        $blocks[] = ['type' => 'subheading', 'text' => "Officer {$i} | Company {$i} Ltd | 20".(10 + $i).' – 20'.(11 + $i)];
        foreach ([0, 1, 2] as $b) {
            $blocks[] = ['type' => 'bullet', 'text' => "Delivered outcome {$b} for team {$i}, coordinating suppliers and reporting results every week."];
        }
    }
    // With this top margin the eleventh entry line used to end page 1 while its bullets started page 2.
    $layout = DocumentLayout::make(classicCvModel(['blocks' => $blocks, 'contact' => null]), classicSnapshot(['margin_top_mm' => 17]));
    $pages = (new Parser)->parseContent(app(PdfRenderer::class)->render($layout)['bytes'])->getPages();
    $lines = fn (int $page) => array_values(array_filter(array_map('trim', explode("\n", $pages[$page]->getText()))));

    $firstPage = $lines(0);

    expect(count($pages))->toBe(2)
        ->and($firstPage[count($firstPage) - 1])->toStartWith('Delivered outcome 2')
        ->and($lines(1)[0])->toStartWith('Officer ')
        ->and(TextNormalizer::compact(implode('', array_map(fn ($p) => $p->getText(), $pages))))->toBe(TextNormalizer::compact($layout->renderedText()));
});

it('renders the classic CV to DOCX with real heading styles, a right tab stop for the dates and coloured runs', function () {
    $layout = DocumentLayout::make(classicCvModel(), classicSnapshot());
    ['xml' => $xml, 'paragraphs' => $paragraphs] = classicDocx($layout);
    $document = $xml['word/document.xml'];
    $styles = $xml['word/styles.xml'];
    preg_match('#<w:style w:type="paragraph" w:styleId="Heading1">.*?</w:style>#s', $styles, $heading1);
    preg_match('#<w:style w:type="paragraph" w:styleId="Title">.*?</w:style>#s', $styles, $title);
    preg_match('#<w:p><w:pPr><w:pStyle w:val="Contact"/>.*?</w:p>#s', $document, $contact);
    preg_match('#<w:p><w:pPr><w:pStyle w:val="Heading2"/>.*?</w:p>#s', $document, $entry);
    $textWidth = (int) round((210 - 19 - 19) * 1440 / 25.4);

    expect(array_column($paragraphs, 'text'))->toBe(array_column($layout->items, 'text'))       // text as written (caps are a font effect)
        ->and(array_column($paragraphs, 'list'))->toBe(array_map(fn ($item) => $item['role'] === 'bullet', $layout->items))
        ->and($heading1[0])->toContain('<w:color w:val="2F5496"/>')->toContain('<w:caps w:val="1"/>')
        ->and($heading1[0])->toContain('<w:pBdr><w:bottom w:val="single" w:sz="4" w:color="2F5496" w:space="1"/></w:pBdr>')
        ->and($title[0])->toContain('<w:caps w:val="1"/>')->toContain('<w:jc w:val="center"/>')
        ->and($styles)->toContain('w:ascii="Times New Roman"')
        // The contact block: one paragraph, a line break inside a run, links in blue.
        ->and($contact[0])->toContain('<w:r><w:rPr><w:sz w:val="24"/><w:szCs w:val="24"/><w:b w:val="0"/><w:bCs w:val="0"/><w:i w:val="0"/><w:iCs w:val="0"/></w:rPr><w:br/></w:r>')
        ->and(substr_count($contact[0], '<w:color w:val="2F5496"/>'))->toBe(2)
        // Entry line: the dates at a right tab stop on the right margin, organisation and dates in grey.
        ->and($entry[0])->toContain('<w:tabs><w:tab w:val="right" w:leader="none" w:pos="'.$textWidth.'"/></w:tabs>')
        ->and($entry[0])->toContain('<w:tab/></w:r>')
        ->and(substr_count($entry[0], '<w:color w:val="595959"/>'))->toBe(2)
        ->and($document)->not->toContain("\u{E001}")->not->toContain("\u{E002}");
});

it('reads a classic CV back from its DOCX and renders the same text again', function () {
    $layout = DocumentLayout::make(classicCvModel(), classicSnapshot());
    ['bytes' => $docx] = classicDocx($layout);

    $import = app(DocxImporter::class)->import($docx, 'Resume / CV', 'en-GB', 'Ama Mensah');
    $again = DocumentLayout::make($import['model'], classicSnapshot());

    expect($import['model']->contact)->toBe('ama@example.com | Tel: +233 24 123 4567 | Accra, Ghana | LinkedIn: linkedin.com/in/ama-example')
        ->and($import['model']->blocks[3])->toBe(['type' => 'subheading', 'text' => 'Science Teacher | Messiah Baptist JHS [2023 – Present]'])
        ->and($import['model']->blocks[0])->toBe(['type' => 'heading', 'text' => 'Professional Summary'])
        ->and($again->text())->toBe($layout->text())
        ->and($again->renderedText())->toBe($layout->renderedText());
});

it('uses text colours that stay readable on white (WCAG AA, 4.5:1)', function () {
    $template = DocumentConfigurationSeeder::classicCv();

    foreach (['heading_color', 'link_color', 'entry_detail_color'] as $color) {
        expect(contrastOnWhite($template[$color]))->toBeGreaterThanOrEqual(4.5);
    }
    // The lighter grey sometimes used for this style would not be.
    expect(contrastOnWhite('#7F7F7F'))->toBeLessThan(4.5);
});
