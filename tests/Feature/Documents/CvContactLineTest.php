<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\Llm\LlmRequest;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Delivery\DocumentDelivery;
use App\Domain\Documents\ContactLine;
use App\Domain\Documents\DocumentAdminOperations;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\Qa\DocxInspector;
use App\Enums\AiJobStatus;
use App\Enums\EmailStatus;
use App\Models\AdminUser;
use App\Models\AiJobStep;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\EmailMessage;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\Revision;
use App\Support\Settings;
use Database\Seeders\AiConfigurationSeeder;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Smalot\PdfParser\Parser;
use Tests\Feature\Ai\Support\PipelineFixtures;
use Tests\Feature\Documents\Fixtures;

uses(PipelineFixtures::class);

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
    Carbon::setTestNow('2026-10-08 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    Fixtures::removePrivateDisk();
});

const CV_EMAIL = 'ama.cv.private@example.com';
const CV_PHONE = '+233 20 555 0199';
const CV_LINKEDIN = 'linkedin.com/in/ama-mensah-cv-9431';

/** @param list<array{0:string,1:string,2:string,3:mixed}> $answers [key, label, type, value] */
function orderWithContact(string $kind, array $answers = []): Order
{
    $order = Fixtures::order([
        'email' => CV_EMAIL,
        'customer_name' => 'Ama Mensah',
        'applicant_name' => 'Ama Mensah',
        'customer_phone' => CV_PHONE,
        'country_code' => 'GB', // the destination: never shown as where the customer lives
        'service_snapshot' => ['name' => 'Resume / CV', 'document_kind' => $kind, 'default_word_limit' => 800],
    ]);
    foreach ($answers as [$key, $label, $type, $value]) {
        OrderAnswer::query()->create(['order_id' => $order->id, 'field_key' => $key, 'label' => $label, 'type' => $type, 'section' => 'details', 'value' => $value]);
    }

    return $order;
}

function cvContent(): DocumentModel
{
    return DocumentModel::fromArray([
        'title' => 'Resume / CV',
        'language_variant' => 'en-GB',
        'blocks' => [
            ['type' => 'heading', 'text' => 'Professional Summary'],
            ['type' => 'paragraph', 'text' => 'Data analyst with two years of experience building payment dashboards.'],
            ['type' => 'heading', 'text' => 'Professional Experience'],
            ['type' => 'subheading', 'text' => 'Data Analyst | Hubtel | 2021–2023'],
            ['type' => 'bullet', 'text' => 'Built fraud detection dashboards for mobile payments.'],
            ['type' => 'bullet', 'text' => 'Reported weekly on payment failures to the operations team.'],
            ['type' => 'heading', 'text' => 'Education'],
            ['type' => 'subheading', 'text' => 'BSc Computer Science | University of Ghana | 2021'],
        ],
    ]);
}

it('adds the customer\'s own contact line under the name of a CV and renders it in both files', function () {
    $order = orderWithContact('resume', [
        ['city', 'City', 'text', 'Accra'],
        ['country_of_residence', 'Country you live in', 'country', 'GH'],
        ['linkedin', 'LinkedIn profile', 'text', 'https://www.linkedin.com/in/ama-mensah-cv-9431/?trk=public_profile'],
        ['website', 'Website', 'text', 'see my LinkedIn'], // not an address: ignored
    ]);

    // The one-line contact line of the executive layout (the classic CV layout is covered in ClassicCvTemplateTest).
    $executive = DocumentTemplate::query()->where('slug', 'executive-resume-cv')->sole();
    $version = Fixtures::rendered($order, cvContent(), template: $executive);
    $expected = CV_EMAIL.' | '.CV_PHONE.' | Accra, Ghana | '.CV_LINKEDIN;
    $paragraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')));
    $pdfText = (new Parser)->parseContent(Fixtures::pdfBytes($version))->getText();
    $qa = app(DocumentQa::class)->validate($version);

    expect($version->content['contact'])->toBe($expected)
        ->and($version->content['blocks'])->toBe(cvContent()->blocks) // the body is untouched
        ->and($version->word_count)->toBe(cvContent()->wordCount())  // and limits never count the contact line
        ->and($version->template_snapshot['heading_rule'])->toBeTrue()
        ->and($paragraphs->pluck('text')->all())->toContain($expected)
        ->and($paragraphs->firstWhere('text', $expected)['style'])->toBe('Contact')
        ->and($paragraphs->where('list', true)->pluck('text')->all())->toBe(['Built fraud detection dashboards for mobile payments.', 'Reported weekly on payment failures to the operations team.'])
        ->and(Fixtures::compact($pdfText))->toContain(Fixtures::compact($expected))
        ->and($pdfText)->not->toContain('United Kingdom')
        ->and(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/styles.xml'))->toContain('<w:pBdr><w:bottom w:val="single" w:sz="4" w:color="000000" w:space="1"/></w:pBdr>');

    $failed = collect($qa->checks)->reject(fn ($c) => $c['passed'])->all();
    expect($failed)->toBe([])
        ->and(collect($qa->checks)->firstWhere('check', 'docx_text_matches')['detail'])->toContain('bullet points as Word list items');
});

it('builds the contact line only from fields that clearly hold contact details', function () {
    expect(ContactLine::build(CV_EMAIL, CV_PHONE))->toBe(CV_EMAIL.' | '.CV_PHONE)
        ->and(ContactLine::build('not-an-email', 'call me'))->toBeNull()
        ->and(ContactLine::build(null, null, [
            'location' => ['type' => 'text', 'value' => 'Kumasi'],
            'linkedin' => ['type' => 'text', 'value' => 'https://twitter.com/ama'],          // not LinkedIn
            'website' => ['type' => 'text', 'value' => 'https://ama.dev/work/?utm_source=x'], // query removed
        ]))->toBe('Kumasi | ama.dev/work')
        ->and(ContactLine::build(null, null, [
            'portfolio' => ['type' => 'text', 'value' => 'javascript:alert(1)'],
            'linkedin_url' => ['type' => 'text', 'value' => 'uk.linkedin.com/in/ama'],
        ]))->toBe('linkedin.com/in/ama');
});

it('gives no contact line to documents other than CVs', function (string $kind) {
    $order = orderWithContact($kind, [['linkedin', 'LinkedIn profile', 'text', CV_LINKEDIN]]);
    $model = $kind === 'cover_letter' ? Fixtures::letter() : Fixtures::model();

    $version = Fixtures::rendered($order, $model);
    $docx = Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml');

    expect($version->content)->not->toHaveKey('contact')
        ->and($version->template_snapshot['heading_rule'])->toBeFalse()
        ->and($docx)->not->toContain(CV_EMAIL)->not->toContain('555 0199')->not->toContain('<w:numPr>')
        ->and(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/styles.xml'))->not->toContain('<w:pBdr>');
})->with(['personal_statement', 'statement_of_purpose', 'cover_letter', 'motivation_letter']);

it('keeps the contact line and lists through an administrator\'s text edit', function () {
    $order = orderWithContact('resume');
    $version = Fixtures::rendered($order, cvContent());
    $admin = AdminUser::factory()->create();

    $edited = DocumentModel::fromArray(['contact' => $version->content['contact']] + cvContent()->toArray());
    $new = app(DocumentAdminOperations::class)->editText($version, $edited, $admin);

    expect($new->content['contact'])->toBe(CV_EMAIL.' | '.CV_PHONE)
        ->and(collect($new->content['blocks'])->pluck('type')->all())->toContain('subheading', 'bullet')
        ->and($new->qa_status)->toBe('passed');
});

it('never sends the contact details to the AI provider, while the delivered CV carries them', function () {
    Settings::flush();
    FakeProvider::reset();
    config(['statementra.ai.provider' => 'fake']);
    $this->seed(AiConfigurationSeeder::class);
    $this->mock(DocumentDelivery::class, function (MockInterface $mock) {
        $mock->shouldReceive('deliver')->andReturnUsing(fn (Order $order, DocumentVersion $version, ?Revision $revision = null) => EmailMessage::query()->create([
            'order_id' => $order->id, 'template_key' => 'document_ready', 'to_email' => $order->email, 'subject' => 'Your document is ready',
            'status' => EmailStatus::Queued, 'meta' => ['purpose' => 'delivery', 'document_version_id' => $version->id],
        ]));
    });

    // The writer answers in CV form: sections, an entry line and bullets.
    FakeProvider::queue('writing', fn (LlmRequest $request, Closure $default) => ['blocks' => [
        ['type' => 'heading', 'text' => 'Professional Summary'],
        ['type' => 'paragraph', 'text' => 'Data analyst at Hubtel in Accra who built fraud detection dashboards for mobile payments, with a BSc in Computer Science from the University of Ghana.'],
        ['type' => 'heading', 'text' => 'Professional Experience'],
        ['type' => 'subheading', 'text' => 'Data Analyst | Hubtel | 2021–2023'],
        ['type' => 'bullet', 'text' => 'Built fraud detection dashboards for mobile payments at Hubtel in Accra.'],
        ['type' => 'bullet', 'text' => 'Worked as a data analyst on mobile payments at Hubtel.'],
        ['type' => 'heading', 'text' => 'Education'],
        ['type' => 'subheading', 'text' => 'BSc Computer Science | University of Ghana | 2023'],
    ]] + $default());

    $order = $this->paidOrder([
        'document_kind' => 'resume',
        'service_snapshot' => ['name' => 'Resume / CV', 'document_kind' => 'resume', 'revision_window_days' => 14, 'revision_fee' => 1500, 'revision_mode' => 'ai'],
        'email' => CV_EMAIL,
        'customer_phone' => CV_PHONE,
        'institution' => null,
        'programme' => null,
        'essay_prompt' => 'Data Analyst building payment dashboards in SQL.',
    ], [
        ['full_name', 'Full name', 'text', 'details', 'Ama Mensah'],
        ['email', 'Email address', 'email', 'details', CV_EMAIL],
        ['phone', 'Phone number', 'phone', 'details', CV_PHONE],
        ['city', 'City', 'text', 'details', 'Tamale-Kpalsi'],
        ['linkedin', 'LinkedIn profile', 'text', 'details', 'https://www.'.CV_LINKEDIN],
        ['work_history', 'Work experience', 'textarea', 'story', 'I worked as a data analyst at Hubtel in Accra from 2021 to 2023. I built fraud detection dashboards for mobile payments.'],
        ['education', 'Education', 'textarea', 'story', 'I completed a BSc in Computer Science at the University of Ghana in 2023.'],
    ]);

    $job = app(PipelineDispatcher::class)->startForOrder($order)->refresh();
    $version = DocumentVersion::query()->where('order_id', $order->id)->latest('id')->firstOrFail();

    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and($version->content['contact'])->toBe(CV_EMAIL.' | '.CV_PHONE.' | Tamale-Kpalsi | '.CV_LINKEDIN)
        ->and(collect($version->content['blocks'])->pluck('type')->all())->toContain('subheading', 'bullet')
        ->and(FakeProvider::calls())->not->toBe([]);

    // The answers given to models leave the contact fields out.
    $step = AiJobStep::query()->where('ai_job_id', $job->id)->orderBy('id')->firstOrFail();
    $context = new StageContext($job, $order->fresh(), $step, $step->stage, microtime(true) + 60);
    expect(array_column($context->answers(), 'key'))->toContain('work_history')->not->toContain('city')->not->toContain('linkedin')->not->toContain('email')->not->toContain('phone');

    foreach (FakeProvider::calls() as $call) {
        $sent = json_encode([$call->instructions, $call->input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        expect($sent)->not->toContain(CV_EMAIL)
            ->not->toContain('555 0199')
            ->not->toContain('ama-mensah-cv-9431')
            ->not->toContain('Tamale-Kpalsi');
    }
});
