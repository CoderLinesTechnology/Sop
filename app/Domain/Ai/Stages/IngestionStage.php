<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Prompts\PromptValue;
use App\Domain\Ai\Research\QuoteMatcher;
use App\Domain\Files\FileVault;
use App\Domain\Files\TextExtractor;
use App\Enums\ExtractionStatus;
use App\Models\Applicant;
use App\Models\Order;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the Applicant Profile from the order answers and uploads. Text
 * uploads are passed as extracted text; images and scanned PDFs
 * (needs_vision) as input_image / input_file parts. Every fact must cite an
 * existing answer key or file uuid, and its evidence quote must be found in
 * that source (when the source is text) — otherwise the fact is dropped.
 */
class IngestionStage implements Stage
{
    private const MAX_TEXT_PER_FILE = 30_000;

    private const MAX_TEXT_TOTAL = 90_000;

    private const MAX_VISUAL_FILES = 5;

    private const MAX_VISUAL_BYTES = 8 * 1024 * 1024;

    private const MAX_VISUAL_TOTAL_BYTES = 20 * 1024 * 1024;

    private const QUOTE_THRESHOLD = 0.8;

    public function __construct(
        private readonly LlmGateway $llm,
        private readonly FileVault $vault,
        private readonly TextExtractor $extractor,
        private readonly QuoteMatcher $quotes,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $answers = $ctx->answers();
        [$documents, $visual, $attachments, $sourceFiles] = $this->documents($ctx);

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'ingestion',
            variables: [
                'document_type' => PromptValue::trusted($ctx->documentTypeLabel()),
                'order_details' => $ctx->orderDetails(),
                'answers' => $answers,
                'documents' => $documents,
                'visual_documents' => $visual,
            ],
            attachments: $attachments,
            context: [
                'answers' => $answers,
                'documents' => $documents,
                'visual' => $visual,
                'order' => $ctx->orderDetails(),
                'applicant_name' => $ctx->applicantName(),
            ],
        ));

        [$profile, $dropped] = $this->validated($result->data, $ctx, $answers, $documents, $visual);

        $fullName = trim((string) ($profile['full_name'] ?? '')) ?: $ctx->applicantName();

        Applicant::query()->updateOrCreate(['order_id' => $ctx->order->id], [
            'full_name' => $fullName ? mb_substr($fullName, 0, 255) : null,
            'profile' => $profile,
            'missing_information' => $profile['gaps'] ?: null,
            'source_files' => $sourceFiles,
            'model' => mb_substr($result->model, 0, 80),
        ]);

        if ($fullName && blank($ctx->order->applicant_name)) {
            Order::query()->whereKey($ctx->order->id)
                ->where(fn ($q) => $q->whereNull('applicant_name')->orWhere('applicant_name', ''))
                ->update(['applicant_name' => mb_substr($fullName, 0, 255)]);
        }

        return StageResult::completed([
            'facts' => count($profile['facts']),
            'dropped_facts' => $dropped,
            'categories' => array_count_values(array_column($profile['facts'], 'category')),
            'answers' => count($answers),
            'files' => $sourceFiles,
            'gaps' => $profile['gaps'],
            'inconsistencies' => $profile['inconsistencies'],
        ]);
    }

    /**
     * @return array{0:list<array>, 1:list<array>, 2:list<array{label:string, part:array}>, 3:list<array>}
     *                                                                                                     [text documents, visual documents, attachment parts, source file log]
     */
    private function documents(StageContext $ctx): array
    {
        $texts = [];
        $visual = [];
        $attachments = [];
        $sources = [];
        $textBudget = self::MAX_TEXT_TOTAL;
        $visualBytes = 0;

        foreach ($ctx->usableFiles() as $file) {
            /** @var UploadedFile $file */
            if ($file->extraction_status === ExtractionStatus::Pending) {
                try {
                    $this->extractor->extract($file);
                    $file->refresh();
                } catch (Throwable $e) {
                    Log::warning('Inline text extraction failed during ingestion.', ['file' => $file->uuid, 'error' => $e->getMessage()]);
                }
            }

            $entry = ['file_id' => $file->uuid, 'purpose' => $file->purpose ?: 'other', 'type' => $file->extension];
            $status = $file->extraction_status;

            if ($status === ExtractionStatus::Extracted && filled($file->extracted_text)) {
                $full = (string) $file->extracted_text;
                $text = mb_substr($full, 0, max(0, min(self::MAX_TEXT_PER_FILE, $textBudget)));
                if ($text === '') {
                    $sources[] = $entry + ['used' => 'skipped', 'reason' => 'text budget exhausted'];

                    continue;
                }
                $textBudget -= mb_strlen($text);
                $texts[] = $entry + ['text' => $text, 'truncated' => mb_strlen($full) > mb_strlen($text)];
                $sources[] = $entry + ['used' => 'text', 'chars' => mb_strlen($text)];

                continue;
            }

            if ($status === ExtractionStatus::NeedsVision && in_array($file->extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                $size = (int) $file->size_bytes;
                if (count($visual) >= self::MAX_VISUAL_FILES || $size > self::MAX_VISUAL_BYTES || $visualBytes + $size > self::MAX_VISUAL_TOTAL_BYTES) {
                    $sources[] = $entry + ['used' => 'skipped', 'reason' => 'too large for visual reading'];

                    continue;
                }

                try {
                    $bytes = $this->vault->get($file->path, $file->disk, (bool) $file->is_encrypted);
                } catch (Throwable $e) {
                    Log::warning('Could not read an upload for visual ingestion.', ['file' => $file->uuid, 'error' => $e->getMessage()]);
                    $sources[] = $entry + ['used' => 'skipped', 'reason' => 'unreadable'];

                    continue;
                }

                $visualBytes += strlen($bytes);
                $attachments[] = [
                    'label' => "file_id {$file->uuid}, purpose {$entry['purpose']}",
                    'part' => $file->extension === 'pdf'
                        ? ['type' => 'input_file', 'filename' => 'upload-'.substr($file->uuid, 0, 8).'.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode($bytes)]
                        : ['type' => 'input_image', 'image_url' => 'data:'.($file->extension === 'png' ? 'image/png' : 'image/jpeg').';base64,'.base64_encode($bytes), 'detail' => 'high'],
                ];
                $visual[] = $entry;
                $sources[] = $entry + ['used' => 'vision'];

                continue;
            }

            $sources[] = $entry + ['used' => 'skipped', 'reason' => 'extraction '.($status?->value ?? 'unknown')];
        }

        return [$texts, $visual, $attachments, $sources];
    }

    /**
     * Keep only facts whose source exists and whose quote can be found in a
     * text source (visual sources cannot be checked and are marked as such).
     *
     * @return array{0:array, 1:int}
     */
    private function validated(array $data, StageContext $ctx, array $answers, array $documents, array $visual): array
    {
        $answerText = array_column($answers, 'answer', 'key');
        $fileText = array_column($documents, 'text', 'file_id');
        $visualIds = array_flip(array_column($visual, 'file_id'));
        $orderDetails = $ctx->orderDetails();

        $facts = [];
        $ids = [];
        $dropped = 0;

        foreach ((array) $data['facts'] as $fact) {
            $type = $fact['source_type'];
            $ref = trim((string) $fact['source_ref']);
            $quote = (string) $fact['evidence_quote'];
            $verified = null;

            if ($type === 'answer') {
                $ref = preg_replace('/^answers?[.:]/', '', $ref) ?? $ref;
                if (! isset($answerText[$ref]) || ! $this->quotes->matches($quote, $answerText[$ref], self::QUOTE_THRESHOLD)) {
                    $dropped++;

                    continue;
                }
                $verified = true;
            } elseif ($type === 'file') {
                $ref = preg_replace('/^(files?|file_id)[.:]\s*/', '', $ref) ?? $ref;
                if (isset($fileText[$ref])) {
                    if (! $this->quotes->matches($quote, $fileText[$ref], self::QUOTE_THRESHOLD)) {
                        $dropped++;

                        continue;
                    }
                    $verified = true;
                } elseif (! isset($visualIds[$ref])) {
                    $dropped++;

                    continue;
                }
            } else {
                $field = preg_replace('/^order[.:]/', '', $ref) ?? $ref;
                $value = $orderDetails[$field] ?? null;
                if ($value === null) {
                    $dropped++;

                    continue;
                }
                $ref = 'order:'.$field;
                $verified = $this->quotes->matches($quote, (string) $value, self::QUOTE_THRESHOLD);
            }

            $id = (string) $fact['id'];
            if ($id === '' || isset($ids[$id])) {
                $id = 'F'.(count($facts) + 1).'x';
            }
            $ids[$id] = true;

            $facts[] = [
                'id' => $id,
                'category' => $fact['category'],
                'statement' => trim((string) $fact['statement']),
                'date' => $fact['date'],
                'source_type' => $type,
                'source_ref' => $ref,
                'evidence_quote' => $quote,
                'confidence' => $fact['confidence'],
                'quote_verified' => $verified,
            ];
        }

        $known = array_flip(array_column($facts, 'id'));

        return [[
            'full_name' => $data['full_name'],
            'summary' => (string) $data['summary'],
            'facts' => $facts,
            'inconsistencies' => array_values(array_map(fn ($i) => [
                'description' => (string) $i['description'],
                'fact_ids' => array_values(array_filter((array) $i['fact_ids'], fn ($id) => isset($known[$id]))),
            ], (array) $data['inconsistencies'])),
            'gaps' => array_values((array) $data['gaps']),
        ], $dropped];
    }
}
