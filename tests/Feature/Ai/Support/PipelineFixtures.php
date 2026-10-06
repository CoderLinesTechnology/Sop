<?php

namespace Tests\Feature\Ai\Support;

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Delivery\DocumentDelivery;
use App\Domain\Documents\DocumentFactory;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\QaResult;
use App\Domain\Documents\RequirementResolver;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateResolver;
use App\Enums\EmailStatus;
use App\Models\AiJob;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\EmailMessage;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\Revision;
use App\Models\Service;
use App\Support\Settings;
use Database\Seeders\AiConfigurationSeeder;
use Mockery\MockInterface;

/**
 * Shared fixtures for the AI pipeline tests: seeded AI configuration, a paid
 * order with realistic answers and a fake document engine (requirements,
 * templates, factory, renderer, QA and delivery mocked through the
 * container, so these tests never depend on the document engine's code).
 */
trait PipelineFixtures
{
    /** Calls recorded by the fake document engine. */
    public array $engine = [
        'resolved' => [],
        'delivered' => [],
        'rendered' => 0,
        'qa' => 0,
    ];

    public function setUpPipeline(?ResolvedRequirements $requirements = null, int $qaFailures = 0): void
    {
        // Settings memoises statically across tests; never inherit another test's values.
        Settings::flush();
        FakeProvider::reset();
        config(['statementra.ai.provider' => 'fake']);
        $this->seed(AiConfigurationSeeder::class);
        $this->fakeDocumentEngine($requirements, $qaFailures);
    }

    public function fakeDocumentEngine(?ResolvedRequirements $requirements = null, int $qaFailures = 0): void
    {
        $template = DocumentTemplate::query()->firstOrCreate(['slug' => 'classic'], ['name' => 'Classic', 'is_default' => true]);
        $requirements ??= new ResolvedRequirements(targetWords: 180, languageVariant: 'en-GB');
        $test = $this;
        $qaFailuresLeft = $qaFailures;

        $this->mock(RequirementResolver::class, function (MockInterface $mock) use ($requirements, $test) {
            $mock->shouldReceive('resolve')->andReturnUsing(function (Order $order, array $researched = [], array $stated = []) use ($requirements, $test) {
                $test->engine['resolved'][] = ['researched' => $researched, 'stated' => $stated];

                return clone $requirements;
            });
        });

        $this->mock(TemplateResolver::class, function (MockInterface $mock) use ($template) {
            $mock->shouldReceive('resolve')->andReturn($template);
        });

        $this->mock(DocumentFactory::class, function (MockInterface $mock) {
            $mock->shouldReceive('createVersion')->andReturnUsing(function (Order $order, DocumentModel $model, DocumentTemplate $template, ResolvedRequirements $requirements, string $source = 'ai', ?AiJob $job = null, ?Revision $revision = null, $admin = null, ?float $quality = null) {
                $document = Document::query()->firstOrCreate(['order_id' => $order->id], ['kind' => $order->documentKind(), 'title' => $model->title, 'status' => 'in_progress']);

                return DocumentVersion::query()->create([
                    'document_id' => $document->id,
                    'order_id' => $order->id,
                    'revision_id' => $revision?->id,
                    'ai_job_id' => $job?->id,
                    'version_number' => $document->nextVersionNumber(),
                    'source' => $source,
                    'status' => 'draft',
                    'title' => $model->title,
                    'content' => $model->toArray(),
                    'plain_text' => $model->bodyText(),
                    'word_count' => $model->wordCount(),
                    'char_count' => $model->characterCount(),
                    'char_count_no_spaces' => $model->characterCount(false),
                    'language_variant' => $model->languageVariant,
                    'document_template_id' => $template->id,
                    'requirements_snapshot' => $requirements->toArray(),
                    'quality_score' => $quality,
                ]);
            });
        });

        $this->mock(DocumentRenderer::class, function (MockInterface $mock) use ($test) {
            $mock->shouldReceive('render')->andReturnUsing(function (DocumentVersion $version) use ($test) {
                $test->engine['rendered']++;
                $version->forceFill([
                    'files_disk' => 'private', 'files_encrypted' => true,
                    'pdf_path' => 'documents/test.pdf.enc', 'pdf_size' => 2048, 'pdf_filename' => 'Statement.pdf',
                    'docx_path' => 'documents/test.docx.enc', 'docx_size' => 4096, 'docx_filename' => 'Statement.docx',
                    'rendered_at' => now(), 'qa_status' => null,
                ])->save();

                return $version;
            });
        });

        $this->mock(DocumentQa::class, function (MockInterface $mock) use ($test, &$qaFailuresLeft) {
            $mock->shouldReceive('validate')->andReturnUsing(function (DocumentVersion $version) use ($test, &$qaFailuresLeft) {
                $test->engine['qa']++;
                $passed = $qaFailuresLeft <= 0;
                $qaFailuresLeft--;
                $checks = [['check' => 'pdf_text_matches', 'passed' => $passed, 'detail' => $passed ? 'ok' : 'PDF text differs from the approved content']];
                $version->forceFill(['qa_status' => $passed ? 'passed' : 'failed', 'qa_results' => $checks])->save();

                return new QaResult($passed, $checks);
            });
        });

        $this->mock(DocumentDelivery::class, function (MockInterface $mock) use ($test) {
            $mock->shouldReceive('deliver')->andReturnUsing(function (Order $order, DocumentVersion $version, ?Revision $revision = null) use ($test) {
                $test->engine['delivered'][] = ['order' => $order->id, 'version' => $version->id, 'revision' => $revision?->id];

                return EmailMessage::query()->create([
                    'order_id' => $order->id,
                    'template_key' => $revision ? 'revision_completed' : 'document_ready',
                    'to_email' => $order->email,
                    'subject' => 'Your document is ready',
                    'status' => EmailStatus::Queued,
                    'meta' => ['purpose' => 'delivery', 'document_version_id' => $version->id, 'revision_id' => $revision?->id],
                ]);
            });
        });
    }

    /** A paid personal-statement order with realistic answers (all numbers are in the answers). */
    public function paidOrder(array $attributes = [], ?array $answers = null): Order
    {
        $service = Service::factory()->create([
            'document_kind' => $attributes['document_kind'] ?? 'personal_statement',
            'writing_guidance' => 'Lead with a specific, genuine moment from the applicant\'s own experience.',
            'delivery_max_minutes' => 30,
        ]);
        unset($attributes['document_kind']);

        $order = Order::factory()->paid()->create($attributes + [
            'service_id' => $service->id,
            'email' => 'ama.mensah@example.com',
            'customer_name' => 'Ama Mensah',
            'customer_phone' => '+233 24 123 4567',
            'institution' => 'University of Oxford',
            'programme' => 'MSc Computer Science',
            'country_code' => 'GB',
            'essay_prompt' => 'Why do you want to study this programme?',
            'word_limit' => null,
        ]);

        $answers ??= [
            ['full_name', 'Full name', 'text', 'details', 'Ama Mensah'],
            ['email', 'Email address', 'email', 'details', 'ama.mensah@example.com'],
            ['why_field', 'Why are you interested in this field?', 'textarea', 'story', 'I became interested in machine learning when I built a crop disease classifier for my family farm in 2021. The model reached 87% accuracy on photos taken with a basic phone.'],
            ['background', 'Your background', 'textarea', 'story', 'I completed a BSc in Computer Science at the University of Ghana in 2023 with first class honours. For my final project I built a Twi speech recognition prototype.'],
            ['experience', 'Relevant experience', 'textarea', 'story', 'I worked as a data analyst at Hubtel in Accra for two years, building fraud detection dashboards for mobile payments.'],
            ['goals', 'Your goals', 'textarea', 'story', 'After the MSc I want to build language technology for West African languages.'],
        ];

        foreach ($answers as [$key, $label, $type, $section, $value]) {
            OrderAnswer::query()->create(['order_id' => $order->id, 'field_key' => $key, 'label' => $label, 'type' => $type, 'section' => $section, 'value' => $value]);
        }

        return $order->refresh();
    }
}
