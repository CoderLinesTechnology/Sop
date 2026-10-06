<?php

namespace Tests\Feature\Admin\Operations;

use App\Domain\Files\FileVault;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\AiJobStatus;
use App\Enums\ClaimVerificationStatus;
use App\Enums\EmailStatus;
use App\Enums\ExtractionStatus;
use App\Enums\FileScanStatus;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\RefundStatus;
use App\Enums\RevisionStatus;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Models\AdminUser;
use App\Models\AiJob;
use App\Models\Applicant;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EmailMessage;
use App\Models\InformationRequest;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\OrderRequirement;
use App\Models\Payment;
use App\Models\QualityReview;
use App\Models\Refund;
use App\Models\ResearchClaim;
use App\Models\ResearchSource;
use App\Models\Revision;
use App\Models\UploadedFile;
use Illuminate\Support\Str;

/** Builders for fully populated orders used by the operations admin tests. */
final class Fixtures
{
    public const PDF_CONTENTS = "%PDF-1.4\n% Statementra test document\n";

    public const DOCX_CONTENTS = "PK\x03\x04 statementra test docx";

    /** A paid order in the given status. */
    public static function paidOrder(OrderStatus $status = OrderStatus::Writing, array $attributes = []): Order
    {
        return Order::factory()->paid($status)->create($attributes);
    }

    /** A paid order with every relation the order page shows. */
    public static function richOrder(OrderStatus $status = OrderStatus::ManualReview, ?AdminUser $admin = null): Order
    {
        $order = self::paidOrder(OrderStatus::PaymentConfirmed, ['customer_phone' => '+234 800 000 0000']);

        $states = app(OrderStateMachine::class);
        $states->transition($order, OrderStatus::Researching, 'system');
        $states->transition($order, OrderStatus::Writing, 'system');
        if ($status !== OrderStatus::Writing) {
            $states->transition($order, $status, 'admin', $admin, 'Fixture status', force: true);
        }

        foreach ([
            ['full_name', 'Full name', 'text', 'details', 'Ada Lovelace'],
            ['institution', 'University', 'text', 'application', 'University of Oxford'],
            ['background', 'Your background', 'textarea', 'story', "Line one\nLine two <script>alert(1)</script>"],
            ['interests', 'Interests', 'multiselect', 'story', ['AI', 'Maths']],
            ['cv', 'CV', 'file', 'application', []],
        ] as [$key, $label, $type, $section, $value]) {
            OrderAnswer::query()->create(['order_id' => $order->id, 'field_key' => $key, 'label' => $label, 'type' => $type, 'section' => $section, 'value' => $value]);
        }

        $file = self::uploadedFile($order);
        OrderAnswer::query()->where('order_id', $order->id)->where('field_key', 'cv')->update(['value' => json_encode([$file->uuid])]);

        Applicant::query()->create([
            'order_id' => $order->id,
            'full_name' => 'Ada Lovelace',
            'profile' => ['education' => [['degree' => 'BSc Mathematics', 'source' => 'cv.pdf']], 'goals' => 'Research in AI'],
            'missing_information' => ['Exact graduation year'],
            'source_files' => [$file->uuid],
            'model' => 'gpt-test',
        ]);

        $source = ResearchSource::query()->create([
            'order_id' => $order->id,
            'url' => 'https://www.ox.ac.uk/admissions/graduate',
            'url_hash' => hash('sha256', 'https://www.ox.ac.uk/admissions/graduate'),
            'domain' => 'ox.ac.uk',
            'title' => 'Graduate admissions',
            'source_type' => SourceType::OfficialAdmissions,
            'is_official' => true,
            'authority_rank' => 2,
            'retrieved_at' => now(),
            'fetch_status' => 'ok',
            'http_status' => 200,
        ]);
        ResearchSource::query()->create([
            'order_id' => $order->id,
            'url' => 'javascript:alert(1)',
            'url_hash' => hash('sha256', 'javascript:alert(1)'),
            'domain' => 'evil.example',
            'title' => 'Untrusted source',
            'source_type' => SourceType::Secondary,
            'is_official' => false,
            'authority_rank' => 9,
        ]);
        ResearchClaim::query()->create([
            'order_id' => $order->id,
            'research_source_id' => $source->id,
            'claim_key' => 'C1',
            'claim' => 'The MSc includes a research project.',
            'category' => 'programme_structure',
            'supporting_quote' => 'Students complete a research project.',
            'confidence' => 0.9,
            'verification_status' => ClaimVerificationStatus::Verified,
            'safe_to_use' => true,
            'used_in_document' => true,
        ]);

        OrderRequirement::query()->create([
            'order_id' => $order->id,
            'resolved' => ['max_words' => 650, 'format' => 'statement'],
            'sources' => [['url' => 'https://www.ox.ac.uk', 'title' => 'Oxford']],
            'conflicts' => [['field' => 'max_words', 'values' => [650, 700]]],
            'applied_rule_ids' => [3],
            'max_words' => 650,
            'last_verified_at' => now(),
        ]);

        $job = AiJob::query()->create([
            'order_id' => $order->id,
            'kind' => AiJob::KIND_ORDER,
            'dedupe_key' => 'order:'.$order->id.':pipeline',
            'status' => $status === OrderStatus::ManualReview ? AiJobStatus::ManualReview : AiJobStatus::Running,
            'current_stage' => PipelineStage::QualityReview,
            'workflow_snapshot' => ['stages' => []],
            'prompt_versions' => ['writing' => 3, 'quality_review' => ['version' => 2]],
            'provider' => 'fake',
            'started_at' => now()->subMinutes(20),
            'total_input_tokens' => 12000,
            'total_output_tokens' => 3000,
            'llm_calls' => 6,
            'total_cost_usd' => 0.4321,
            'last_error_code' => 'quality_below_threshold',
            'last_error_message' => 'Quality score 6.1 is below the threshold 8.0.',
        ]);
        foreach ([
            [PipelineStage::Research, StepStatus::Completed, 1],
            [PipelineStage::QualityReview, StepStatus::Failed, 1],
        ] as $i => [$stage, $stepStatus, $attempt]) {
            $job->steps()->create([
                'stage' => $stage,
                'sequence' => $i + 1,
                'attempt' => $attempt,
                'status' => $stepStatus,
                'model' => 'gpt-test',
                'input_tokens' => 1000,
                'output_tokens' => 200,
                'cost_usd' => 0.01,
                'duration_ms' => 1234,
                'error_code' => $stepStatus === StepStatus::Failed ? 'timeout' : null,
                'error_message' => $stepStatus === StepStatus::Failed ? 'The model timed out.' : null,
            ]);
        }
        QualityReview::query()->create([
            'ai_job_id' => $job->id,
            'order_id' => $order->id,
            'round' => 1,
            'scores' => ['personalization' => 7.5, 'grammar' => 9],
            'overall_score' => 6.1,
            'threshold' => 8.0,
            'passed' => false,
            'answers_prompt' => true,
            'issues' => [['issue' => 'Too generic opening'], 'Missing programme detail'],
            'reviewer' => 'ai',
            'model' => 'gpt-test',
        ]);

        $version = self::documentVersion($order);

        EmailMessage::query()->create([
            'order_id' => $order->id,
            'template_key' => 'payment_received',
            'to_email' => $order->email,
            'subject' => 'We received your order',
            'html_body' => '<p>Thanks!</p><script>alert(1)</script>',
            'text_body' => 'Thanks!',
            'status' => EmailStatus::Failed,
            'attempts' => 5,
            'last_error' => 'SMTP connection refused',
        ]);

        Revision::query()->create([
            'order_id' => $order->id,
            'number' => 1,
            'request_text' => 'Please mention my internship.',
            'status' => RevisionStatus::Requested,
            'mode' => 'ai',
            'fee_amount' => 0,
            'currency' => 'USD',
            'requested_at' => now(),
        ]);

        InformationRequest::query()->create([
            'order_id' => $order->id,
            'source' => 'ai',
            'questions' => [['key' => 'q1', 'question' => 'When do you graduate?', 'why' => 'Programme requires it']],
            'answers' => ['q1' => 'June 2027'],
            'status' => 'answered',
            'requested_at' => now()->subDay(),
            'answered_at' => now(),
        ]);

        $order->notes()->create(['admin_user_id' => $admin?->id, 'body' => 'Checked the CV — looks fine.']);

        unset($version);

        return $order->refresh();
    }

    public static function uploadedFile(Order $order, string $contents = self::PDF_CONTENTS): UploadedFile
    {
        $stored = app(FileVault::class)->put('uploads', $contents, 'pdf');

        return UploadedFile::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'field_key' => 'cv',
            'purpose' => 'cv',
            'original_name' => 'Ada "CV".pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $stored['size'],
            'sha256' => $stored['sha256'],
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'is_encrypted' => true,
            'scan_status' => FileScanStatus::Clean,
            'extraction_status' => ExtractionStatus::Extracted,
            'extracted_chars' => 1200,
            'page_count' => 2,
            'attached_at' => now(),
        ]);
    }

    /** A rendered, QA-passed (deliverable) version with encrypted PDF and DOCX files. */
    public static function documentVersion(Order $order, int $number = 1, string $qaStatus = 'passed'): DocumentVersion
    {
        $vault = app(FileVault::class);
        $pdf = $vault->put('documents', self::PDF_CONTENTS, 'pdf');
        $docx = $vault->put('documents', self::DOCX_CONTENTS, 'docx');

        $document = Document::query()->firstOrCreate(
            ['order_id' => $order->id],
            ['kind' => 'personal_statement', 'title' => 'Personal Statement', 'status' => 'in_progress'],
        );

        return DocumentVersion::query()->create([
            'document_id' => $document->id,
            'order_id' => $order->id,
            'version_number' => $number,
            'source' => 'ai',
            'status' => 'draft',
            'title' => 'Personal Statement',
            'content' => [
                'title' => 'Personal Statement',
                'subtitle' => null,
                'applicant_name' => 'Ada Lovelace',
                'date' => null,
                'language_variant' => 'en-GB',
                'blocks' => [['type' => 'paragraph', 'text' => 'I have long been fascinated by engines.']],
            ],
            'plain_text' => 'I have long been fascinated by engines.',
            'word_count' => 7,
            'char_count' => 40,
            'page_count' => 1,
            'quality_score' => 8.5,
            'files_disk' => $pdf['disk'],
            'files_encrypted' => true,
            'pdf_path' => $pdf['path'],
            'pdf_size' => $pdf['size'],
            'docx_path' => $docx['path'],
            'docx_size' => $docx['size'],
            'pdf_filename' => 'Ada_Lovelace_Personal_Statement.pdf',
            'docx_filename' => 'Ada_Lovelace_Personal_Statement.docx',
            'qa_status' => $qaStatus,
            'qa_results' => ['passed' => $qaStatus === 'passed', 'checks' => [
                ['check' => 'pdf_opens', 'passed' => true, 'detail' => 'The PDF opens.'],
                ['check' => 'length', 'passed' => $qaStatus === 'passed', 'detail' => 'Within the word limit.'],
            ]],
            'rendered_at' => now(),
        ]);
    }

    public static function refund(Order $order, int $amount, RefundStatus $status = RefundStatus::Requested, ?AdminUser $admin = null): Refund
    {
        /** @var Payment $payment */
        $payment = $order->payments()->firstOrFail();

        return Refund::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => $amount,
            'currency' => $payment->currency,
            'reason' => 'Customer changed their mind',
            'status' => $status,
            'requested_by' => 'admin',
            'requested_by_admin_id' => $admin?->id,
            'idempotency_key' => hash('sha256', Str::random(32)),
        ]);
    }
}
