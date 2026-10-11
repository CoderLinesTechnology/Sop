<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Delivery\DocumentDelivery;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\DocxImporter;
use App\Domain\Documents\LetterAddresses;
use App\Domain\Documents\Qa\DocxInspector;
use App\Enums\AiJobStatus;
use App\Enums\EmailStatus;
use App\Models\AiJobStep;
use App\Models\AiWorkflow;
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
    Carbon::setTestNow('2026-10-11 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    Fixtures::removePrivateDisk();
});

const LETTER_HOME = "P.O. Box 4471\nMampong-Ashanti\nGhana";
const LETTER_COMPANY = 'Oak Specialist Hospital';
const LETTER_COMPANY_ADDRESS = '14 Nhyiaeso Ridge Road, Kumasi, Ghana';

/** A cover letter order with the given address answers ([key, value]). */
function coverLetterOrder(array $answers = []): Order
{
    $order = Fixtures::order([
        'institution' => LETTER_COMPANY,
        'programme' => null,
        'country_code' => 'GH',
        'service_snapshot' => ['name' => 'Cover Letter', 'document_kind' => 'cover_letter', 'default_word_limit' => 400],
    ]);
    foreach ($answers as [$key, $value]) {
        OrderAnswer::query()->create(['order_id' => $order->id, 'field_key' => $key, 'label' => $key, 'type' => 'textarea', 'section' => 'details', 'value' => $value]);
    }

    return $order;
}

it('lays an address out as letter lines and heads the recipient block with the organisation', function () {
    expect(LetterAddresses::lines('P.O. Box 12, Mampong, Ashanti, Ghana'))->toBe(['P.O. Box 12', 'Mampong', 'Ashanti', 'Ghana'])
        ->and(LetterAddresses::lines("Flat 3, 12 High Street\nKumasi"))->toBe(['Flat 3, 12 High Street', 'Kumasi'])
        ->and(LetterAddresses::lines('   '))->toBe([])
        ->and(LetterAddresses::lines(['not', 'text']))->toBe([])
        ->and(LetterAddresses::build(['company_address' => LETTER_COMPANY_ADDRESS], LETTER_COMPANY))
        ->toBe(['address' => null, 'recipient' => "Oak Specialist Hospital\n14 Nhyiaeso Ridge Road\nKumasi\nGhana"])
        // The customer already wrote the organisation as the first line: not repeated.
        ->and(LetterAddresses::build(['company_address' => "Oak Specialist Hospital\nKumasi"], LETTER_COMPANY)['recipient'])->toBe("Oak Specialist Hospital\nKumasi")
        // No address: no recipient block, even though the organisation is known.
        ->and(LetterAddresses::build(['address' => LETTER_HOME], LETTER_COMPANY))->toBe(['address' => LETTER_HOME, 'recipient' => null]);
});

it('places the applicant\'s address, the date and the company\'s address above "Dear" in both files', function () {
    $order = coverLetterOrder([['address', LETTER_HOME], ['company_address', LETTER_COMPANY_ADDRESS]]);

    $version = Fixtures::rendered($order, Fixtures::letter());
    $paragraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')));
    $pdfText = Fixtures::compact((new Parser)->parseContent(Fixtures::pdfBytes($version))->getText());
    $recipient = "Oak Specialist Hospital\n14 Nhyiaeso Ridge Road\nKumasi\nGhana";

    expect($version->content['address'])->toBe(LETTER_HOME)
        ->and($version->content['recipient'])->toBe($recipient)
        ->and($version->word_count)->toBe(Fixtures::letter()->wordCount()) // limits never count the addresses
        ->and($paragraphs->take(4)->pluck('text')->all())->toBe([LETTER_HOME, '11 October 2026', $recipient, 'Dear Admissions Committee,'])
        ->and($paragraphs->take(4)->pluck('style')->all())->toBe(['SenderAddress', null, 'Recipient', null])
        ->and($pdfText)->toContain(Fixtures::compact('P.O. Box 4471 Mampong-Ashanti Ghana 11 October 2026 Oak Specialist Hospital 14 Nhyiaeso Ridge Road Kumasi Ghana Dear Admissions Committee'));
});

it('passes file QA with the address blocks', function () {
    $version = Fixtures::rendered(coverLetterOrder([['address', LETTER_HOME], ['company_address', LETTER_COMPANY_ADDRESS]]), Fixtures::letter());

    expect(collect(app(DocumentQa::class)->validate($version)->checks)->reject(fn ($c) => $c['passed'])->all())->toBe([]);
});

it('leaves the letter as it is when the customer gives no address', function () {
    $version = Fixtures::rendered(coverLetterOrder(), Fixtures::letter());
    $paragraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')));

    expect($version->content)->not->toHaveKey('address')->not->toHaveKey('recipient')
        ->and($paragraphs->take(2)->pluck('text')->all())->toBe(['11 October 2026', 'Dear Admissions Committee,']);
});

it('shows only the applicant\'s address when the company\'s address is missing', function () {
    $version = Fixtures::rendered(coverLetterOrder([['address', 'P.O. Box 4471, Mampong-Ashanti, Ghana']]), Fixtures::letter());
    $paragraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')));

    expect($version->content)->not->toHaveKey('recipient')
        ->and($paragraphs->take(3)->pluck('text')->all())->toBe([LETTER_HOME, '11 October 2026', 'Dear Admissions Committee,']);
});

it('gives no address blocks to documents that are not letters', function () {
    $order = Fixtures::order(['service_snapshot' => ['name' => 'Statement of Purpose', 'document_kind' => 'statement_of_purpose']]);
    OrderAnswer::query()->create(['order_id' => $order->id, 'field_key' => 'address', 'label' => 'Address', 'type' => 'textarea', 'section' => 'details', 'value' => LETTER_HOME]);

    expect(Fixtures::rendered($order)->content)->not->toHaveKey('address')->not->toHaveKey('recipient');
});

it('maps the address blocks back from an uploaded Word file', function () {
    $version = Fixtures::rendered(coverLetterOrder([['address', LETTER_HOME], ['company_address', LETTER_COMPANY_ADDRESS]]), Fixtures::letter());

    $model = app(DocxImporter::class)->import(Fixtures::docxBytes($version), 'Cover Letter', 'en-GB', 'Daniel Essel')['model'];

    expect($model->address)->toBe(LETTER_HOME)
        ->and($model->recipient)->toBe($version->content['recipient'])
        ->and($model->date)->toBe('11 October 2026')
        ->and($model->blocks[0])->toBe(['type' => 'salutation', 'text' => 'Dear Admissions Committee,']);
});

it('never sends the addresses to the AI provider, while the delivered letter carries them', function () {
    Settings::flush();
    FakeProvider::reset();
    config(['statementra.ai.provider' => 'fake']);
    $this->seed(AiConfigurationSeeder::class);
    // This test is about privacy, not follow-up questions.
    AiWorkflow::query()->get()->each(fn (AiWorkflow $w) => $w->update(['config' => array_replace_recursive((array) $w->config, ['needs_information' => ['enabled' => false]])]));
    $this->mock(DocumentDelivery::class, function (MockInterface $mock) {
        $mock->shouldReceive('deliver')->andReturnUsing(fn (Order $order, DocumentVersion $version, ?Revision $revision = null) => EmailMessage::query()->create([
            'order_id' => $order->id, 'template_key' => 'document_ready', 'to_email' => $order->email, 'subject' => 'Your document is ready',
            'status' => EmailStatus::Queued, 'meta' => ['purpose' => 'delivery', 'document_version_id' => $version->id],
        ]));
    });

    $order = $this->paidOrder([
        'document_kind' => 'cover_letter',
        'service_snapshot' => ['name' => 'Cover Letter', 'document_kind' => 'cover_letter', 'revision_window_days' => 14, 'revision_fee' => 1500, 'revision_mode' => 'ai'],
        'institution' => LETTER_COMPANY,
        'programme' => null,
        'essay_prompt' => 'Registered General Nurse for our surgical ward.',
    ], [
        ['full_name', 'Full name', 'text', 'details', 'Ama Mensah'],
        ['address', 'Your address', 'textarea', 'details', LETTER_HOME],
        ['company_address', "Company's address", 'textarea', 'application', LETTER_COMPANY_ADDRESS],
        ['experience', 'Relevant experience', 'textarea', 'story', 'I worked as a registered nurse at Komfo Anokye Teaching Hospital from 2021 to 2024, caring for surgical patients.'],
    ]);

    $job = app(PipelineDispatcher::class)->startForOrder($order)->refresh();
    $version = DocumentVersion::query()->where('order_id', $order->id)->latest('id')->firstOrFail();

    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and($version->content['address'])->toBe(LETTER_HOME)
        ->and($version->content['recipient'])->toBe("Oak Specialist Hospital\n14 Nhyiaeso Ridge Road\nKumasi\nGhana");

    $step = AiJobStep::query()->where('ai_job_id', $job->id)->orderBy('id')->firstOrFail();
    $context = new StageContext($job, $order->fresh(), $step, $step->stage, microtime(true) + 60);
    expect(array_column($context->answers(), 'key'))->toContain('experience')->not->toContain('address')->not->toContain('company_address');

    foreach (FakeProvider::calls() as $call) {
        $sent = json_encode([$call->instructions, $call->input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        expect($sent)->not->toContain('4471')->not->toContain('Nhyiaeso');
    }
});
