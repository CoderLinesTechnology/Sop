<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Prompts\PromptValue;
use App\Domain\Ai\Research\UrlNormalizer;
use App\Domain\Orders\InformationRequestService;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJobStep;
use App\Models\UploadedFile;

/**
 * Works out what the application really asks, which evidence to emphasise,
 * what research must answer, the stated limits, the language variant and
 * whether essential information is missing. If critical information is
 * missing, follow-up questions are enabled for the workflow and this job has
 * not asked before, the customer is asked (order → NEEDS_INFORMATION) and the
 * job waits; resume() continues it.
 */
class AnalysisStage implements Stage
{
    private const MAX_REQUIREMENTS_TEXT = 20_000;

    public function __construct(
        private readonly LlmGateway $llm,
        private readonly InformationRequestService $information,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $allowed = (bool) $ctx->config('needs_information.enabled', true) && ! $this->askedBefore($ctx);
        $maxQuestions = max(1, min(InformationRequestService::MAX_QUESTIONS, (int) $ctx->config('needs_information.max_questions', 3)));
        $followUps = array_values(array_filter($ctx->answers(), fn ($a) => str_starts_with($a['key'], 'followup_')));

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'analysis',
            variables: [
                'document_type' => PromptValue::trusted($ctx->documentTypeLabel()),
                'document_focus' => PromptValue::trusted($ctx->documentKind()->writingFocus()),
                'order_details' => $ctx->orderDetails(),
                'profile' => $ctx->profileForPrompt(),
                'requirements_documents' => $this->requirementsDocuments($ctx),
                'follow_up_answers' => $followUps,
                'follow_up_allowed' => $allowed,
                'max_questions' => $maxQuestions,
            ],
            context: [
                'order' => $ctx->orderDetails(),
                'facts' => $ctx->profileForPrompt()['facts'],
                'needs_information_allowed' => $allowed,
            ],
        ));

        $analysis = $this->sanitize($result->data, $ctx->factIds());

        $critical = array_values(array_filter($analysis['missing_information'], fn ($m) => $m['critical']));
        if ($critical !== [] && $allowed) {
            $optional = array_values(array_filter($analysis['missing_information'], fn ($m) => ! $m['critical']));
            $questions = array_map(
                fn ($m) => ['question' => $m['question'], 'why' => $m['why']],
                array_slice([...$critical, ...$optional], 0, $maxQuestions),
            );

            $this->information->request($ctx->order, $questions, 'ai');

            return StageResult::waitingForCustomer($analysis + ['information_requested' => true, 'questions' => $questions]);
        }

        return StageResult::completed($analysis + ['information_requested' => false]);
    }

    /** Whether this job already asked the customer (each job asks at most once). */
    private function askedBefore(StageContext $ctx): bool
    {
        return AiJobStep::query()
            ->where('ai_job_id', $ctx->job->id)
            ->where('stage', PipelineStage::Analysis->value)
            ->where('status', StepStatus::Completed->value)
            ->get(['id', 'output'])
            ->contains(fn (AiJobStep $step) => ! empty($step->output['information_requested']));
    }

    /** @return list<array{file_id:string, text:string}> */
    private function requirementsDocuments(StageContext $ctx): array
    {
        $documents = [];
        $budget = self::MAX_REQUIREMENTS_TEXT;

        foreach ($ctx->usableFiles() as $file) {
            /** @var UploadedFile $file */
            if ($file->purpose !== 'requirements' || blank($file->extracted_text) || $budget <= 0) {
                continue;
            }
            $text = mb_substr((string) $file->extracted_text, 0, $budget);
            $budget -= mb_strlen($text);
            $documents[] = ['file_id' => $file->uuid, 'text' => $text];
        }

        return $documents;
    }

    /** Drop references to unknown facts and normalise domains. */
    private function sanitize(array $analysis, array $factIds): array
    {
        $known = array_flip($factIds);

        $analysis['experiences_to_emphasise'] = array_values(array_filter(
            (array) $analysis['experiences_to_emphasise'],
            fn ($e) => isset($known[$e['fact_id']]),
        ));

        $domains = [];
        foreach ((array) $analysis['official_domains'] as $entry) {
            $domain = UrlNormalizer::bareDomain((string) $entry['domain']);
            if ($domain !== null) {
                $domains[$domain] = ['domain' => $domain, 'entity' => (string) $entry['entity'], 'confidence' => (float) $entry['confidence']];
            }
        }
        $analysis['official_domains'] = array_values($domains);

        $analysis['missing_information'] = array_values(array_filter(
            (array) $analysis['missing_information'],
            fn ($m) => trim((string) $m['question']) !== '',
        ));

        return $analysis;
    }
}
