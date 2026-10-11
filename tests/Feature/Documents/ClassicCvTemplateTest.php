<?php

use App\Domain\Documents\DocumentFactory;
use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\Qa\TextNormalizer;
use App\Domain\Documents\RequirementResolver;
use App\Domain\Documents\TemplateResolver;
use App\Models\DocumentTemplate;
use App\Models\Order;
use App\Models\OrderAnswer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Smalot\PdfParser\Parser;
use Tests\Feature\Documents\Fixtures;

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
    Carbon::setTestNow('2026-10-08 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    Fixtures::removePrivateDisk();
});

function classicResumeOrder(): Order
{
    $order = Fixtures::order([
        'email' => 'kofi.classic@example.com',
        'customer_name' => 'Kofi Owusu',
        'applicant_name' => 'Kofi Owusu',
        'customer_phone' => '+233 24 555 0101',
        'institution' => null,
        'programme' => null,
        'service_snapshot' => ['name' => 'Resume / CV', 'document_kind' => 'resume', 'default_word_limit' => 800],
    ]);
    foreach ([['city', 'City', 'text', 'Takoradi, Ghana'], ['linkedin', 'LinkedIn profile', 'url', 'https://www.linkedin.com/in/kofi-owusu-example']] as [$key, $label, $type, $value]) {
        OrderAnswer::query()->create(['order_id' => $order->id, 'field_key' => $key, 'label' => $label, 'type' => $type, 'section' => 'details', 'value' => $value]);
    }

    return $order;
}

function classicResumeContent(): DocumentModel
{
    return DocumentModel::fromArray([
        'title' => 'Kofi Owusu',
        'language_variant' => 'en-GB',
        'blocks' => [
            ['type' => 'heading', 'text' => 'Professional Summary'],
            ['type' => 'paragraph', 'text' => 'Logistics coordinator with four years of experience planning deliveries for a regional distributor.'],
            ['type' => 'heading', 'text' => 'Professional Experience'],
            ['type' => 'subheading', 'text' => 'Logistics Coordinator | Western Distributors Ltd | 2022 – Present'],
            ['type' => 'bullet', 'text' => 'Plan daily delivery routes for a fleet of twelve trucks.'],
            ['type' => 'bullet', 'text' => 'Cut late deliveries by a third in the first year.'],
            ['type' => 'heading', 'text' => 'Education'],
            ['type' => 'subheading', 'text' => 'BSc Logistics | Takoradi Technical University | 2017 – 2021'],
        ],
    ]);
}

it('adds the presentation columns with defaults that keep every existing template as it was', function () {
    $template = Fixtures::template('house-style')->refresh();

    expect(Schema::hasColumns('document_templates', ['title_uppercase', 'heading_color', 'heading_uppercase', 'heading_rule_color', 'contact_layout', 'link_color', 'entry_line_style', 'entry_detail_color', 'bullet_align']))->toBeTrue()
        ->and($template->only(['title_uppercase', 'heading_color', 'heading_uppercase', 'heading_rule_color', 'contact_layout', 'link_color', 'entry_line_style', 'entry_detail_color', 'bullet_align']))
        ->toBe(['title_uppercase' => false, 'heading_color' => null, 'heading_uppercase' => false, 'heading_rule_color' => null, 'contact_layout' => 'single_line', 'link_color' => null, 'entry_line_style' => false, 'entry_detail_color' => null, 'bullet_align' => null])
        // Seeded templates other than the classic CV keep the original look.
        ->and(DocumentTemplate::query()->where('slug', '!=', 'classic-professional-cv')->where(fn ($q) => $q->where('title_uppercase', true)->orWhere('heading_uppercase', true)->orWhere('entry_line_style', true)->orWhereNotNull('heading_color')->orWhere('contact_layout', '!=', 'single_line'))->count())->toBe(0);
});

it('makes the Classic Professional CV the default for resume orders and keeps the executive template available', function () {
    $resolver = app(TemplateResolver::class);
    $resume = classicResumeOrder();

    expect($resolver->resolve($resume, app(RequirementResolver::class)->resolve($resume))->slug)->toBe('classic-professional-cv')
        ->and(DocumentTemplate::query()->where('slug', 'executive-resume-cv')->value('is_active'))->toBeTrue()
        ->and($resolver->resolve(Fixtures::order(), app(RequirementResolver::class)->resolve(Fixtures::order()))->slug)->not->toBe('classic-professional-cv');
});

it('renders a resume order with the classic template in both files and passes file QA', function () {
    $order = classicResumeOrder();
    $version = Fixtures::rendered($order, classicResumeContent());
    $layout = DocumentLayout::make($version->documentModel(), $version->template_snapshot);
    $paragraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')));
    $pdfText = (new Parser)->parseContent(Fixtures::pdfBytes($version))->getText();
    $qa = app(DocumentQa::class)->validate($version);

    expect($version->template_snapshot)->toMatchArray(['template_slug' => 'classic-professional-cv', 'font_family' => 'Times New Roman', 'heading_rule' => true, 'contact_layout' => 'two_lines'])
        // The stored content is untouched: presentation only.
        ->and($version->content['contact'])->toBe('kofi.classic@example.com | +233 24 555 0101 | Takoradi, Ghana | linkedin.com/in/kofi-owusu-example')
        ->and($version->content['blocks'])->toBe(classicResumeContent()->blocks)
        ->and($version->word_count)->toBe(classicResumeContent()->wordCount())
        ->and($paragraphs->pluck('text')->first())->toBe('Kofi Owusu')
        ->and($paragraphs->firstWhere('style', 'Contact')['text'])->toBe("kofi.classic@example.com | Tel: +233 24 555 0101 | Takoradi, Ghana\nLinkedIn: linkedin.com/in/kofi-owusu-example")
        ->and($paragraphs->where('style', 'Heading2')->pluck('text')->all())->toBe(["Logistics Coordinator | Western Distributors Ltd\t[2022 – Present]", "BSc Logistics | Takoradi Technical University\t[2017 – 2021]"])
        ->and(Fixtures::compact($pdfText))->toBe(Fixtures::compact($layout->renderedText()))
        ->and($pdfText)->toContain('KOFI OWUSU')->toContain('PROFESSIONAL EXPERIENCE')->toContain('[2022 – Present]');

    $failed = collect($qa->checks)->reject(fn ($c) => $c['passed'])->all();
    expect($failed)->toBe([])
        ->and($qa->passed)->toBeTrue();
});

it('re-renders versions made before the presentation options with their original look', function () {
    $order = classicResumeOrder();
    $requirements = app(RequirementResolver::class)->resolve($order);
    $executive = DocumentTemplate::query()->where('slug', 'executive-resume-cv')->sole();
    $snapshot = app(DocumentFactory::class)->snapshot($order, $executive, $requirements);
    // A snapshot stored before this change has none of the new keys.
    $old = array_diff_key($snapshot, array_flip(['title_uppercase', 'heading_color', 'heading_uppercase', 'heading_rule_color', 'contact_layout', 'link_color', 'entry_line_style', 'entry_detail_color', 'bullet_align']));
    $model = DocumentModel::fromArray(['contact' => 'kofi.classic@example.com | +233 24 555 0101'] + classicResumeContent()->toArray());
    $version = app(DocumentRenderer::class)->render(app(DocumentFactory::class)->storeVersion($order, $model, $old, $executive->id, $requirements, 'ai'));

    $styles = Fixtures::docxPart(Fixtures::docxBytes($version), 'word/styles.xml');
    $document = Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml');

    expect($styles)->not->toContain('<w:caps w:val="1"/>')->not->toContain('2F5496')
        ->and($styles)->toContain('<w:pBdr><w:bottom w:val="single" w:sz="4" w:color="000000" w:space="1"/></w:pBdr>')
        ->and($document)->toContain('kofi.classic@example.com | +233 24 555 0101')->not->toContain('Tel:')->not->toContain('<w:tab/>')
        ->and((new Parser)->parseContent(Fixtures::pdfBytes($version))->getText())->not->toContain('PROFESSIONAL SUMMARY');
});

it('starts motivation and cover letters like real letters: the date, then the salutation, with no title or programme line', function (string $kind, array $order, string $slug) {
    // The model carries a title and a "Programme — Institution" subtitle, as the formatting stage writes them.
    $letter = Fixtures::letter(['subtitle' => 'MSc Data Science — Technical University of Munich']);
    $version = Fixtures::rendered(Fixtures::motivationOrder($order + ['service_snapshot' => ['name' => 'Letter', 'document_kind' => $kind, 'default_word_limit' => 600]]), $letter);
    $layout = DocumentLayout::make($version->documentModel(), $version->template_snapshot);
    $docx = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')))->pluck('text');
    $pdfText = (new Parser)->parseContent(Fixtures::pdfBytes($version))->getText();

    expect($version->template_snapshot['template_slug'])->toBe($slug)
        ->and(array_slice(array_column($layout->items, 'role'), 0, 2))->toBe(['date', 'salutation'])
        ->and($docx->take(2)->all())->toBe([$layout->items[0]['text'], 'Dear Admissions Committee,'])
        ->and($layout->hidden)->toBe(['title', 'subtitle', 'applicant_name'])
        ->and(TextNormalizer::compact($pdfText))->not->toContain(TextNormalizer::compact('Motivation Letter'))->not->toContain(TextNormalizer::compact('Technical University of Munich'))
        ->and($docx->all())->not->toContain('Motivation Letter — MSc Data Science')->not->toContain('MSc Data Science — Technical University of Munich')
        ->and(app(DocumentQa::class)->validate($version)->passed)->toBeTrue();
})->with([
    'motivation letter' => ['motivation_letter', [], 'motivation-letter-european'],
    'cover letter' => ['cover_letter', ['country_code' => 'US'], 'modern-business-cover-letter'],
]);

it('keeps the title of essays, and of letters rendered before the change', function () {
    $statement = Fixtures::rendered(Fixtures::order(), Fixtures::model());
    expect(array_column(DocumentLayout::make($statement->documentModel(), $statement->template_snapshot)->items, 'role')[0])->toBe('title');

    // A letter version stored with the old setting re-renders exactly as it was.
    $order = Fixtures::motivationOrder();
    $requirements = app(RequirementResolver::class)->resolve($order);
    $template = DocumentTemplate::query()->where('slug', 'motivation-letter-european')->sole();
    $template->show_title = true; // as seeded before this change (not saved)
    $old = app(DocumentRenderer::class)->render(app(DocumentFactory::class)->createVersion($order, Fixtures::letter(), $template, $requirements));
    $rerendered = app(DocumentRenderer::class)->render($old->fresh());

    expect(array_column(DocumentLayout::make($rerendered->documentModel(), $rerendered->template_snapshot)->items, 'role'))->toContain('title')
        ->and(collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($rerendered), 'word/document.xml')))->pluck('text')->all())
        ->toContain('Motivation Letter — MSc Data Science');
});
