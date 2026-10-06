<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Prompts\PromptValue;
use App\Domain\Ai\Writing\DraftConverter;
use App\Domain\Ai\Writing\FactChecker;
use App\Domain\Ai\Writing\LengthChecker;

/**
 * Deterministic word / character / page / section checks against the
 * resolved requirements. Violations trigger a constrained length-fit
 * revision (preserving meaning, evidence, programme fit and voice), re-checked
 * after every attempt, up to limits.max_length_revisions. A document that
 * still breaks a hard limit is never delivered: manual review.
 */
class LimitsStage implements Stage
{
    public function __construct(
        private readonly LlmGateway $llm,
        private readonly LengthChecker $lengths,
        private readonly FactChecker $facts,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $draft = $ctx->currentDraft() ?? throw StageFailure::permanent('missing_draft', 'There is no draft to check.');
        $requirements = $ctx->requirements();
        $template = $ctx->template();
        $target = $ctx->targetWords();
        $maxRevisions = max(0, (int) $ctx->config('limits.max_length_revisions', 3));

        $report = $this->lengths->check($draft, $requirements, $template, $target);
        $history = [$report['counts']];
        $revisions = 0;
        $evidence = null;
        $names = ['institution' => $ctx->order->institution, 'programme' => $ctx->order->programme];
        $citationsAllowed = ($template?->citation_style ?? 'none') !== 'none';

        while (! $report['ok'] && $revisions < $maxRevisions) {
            $revisions++;
            $targets = $report['targets'];

            $result = $this->llm->call($ctx, new LlmCall(
                task: 'limits',
                variables: [
                    'document_type' => PromptValue::trusted($ctx->documentTypeLabel()),
                    'language' => PromptValue::trusted($ctx->languageGuidance()),
                    'violations' => array_column($report['violations'], 'message'),
                    'targets' => PromptValue::trusted($this->targetsText($targets, $report['counts'])),
                    'counting_rules' => PromptValue::trusted('Words are whitespace-separated tokens containing at least one letter or digit. Characters are counted including spaces; each paragraph break counts as one character. '
                        .($requirements->limitsIncludeHeadings ? 'Headings count towards the totals.' : 'Headings (the questions shown by the application portal) are not counted; only the answers are.')),
                    'required_sections' => $requirements->requiredSections,
                    'draft' => DraftConverter::forPrompt($draft),
                ],
                context: PromptInputs::fakeContext($ctx, $draft, ['targets' => $targets, 'violations' => $report['violations'], 'used_claim_ids' => $ctx->currentDraftClaimIds()]),
            ));

            $candidate = DraftConverter::toModel($result->data, $draft->title, $ctx->languageVariant(), $ctx->applicantName());
            if ($candidate->wordCount() === 0) {
                continue;
            }

            // The length editor must not introduce unsupported details.
            $evidence ??= $ctx->evidence();
            $problems = $this->facts->check($candidate, $evidence, $names, $citationsAllowed);
            if ($problems !== []) {
                $candidate = $this->facts->sanitize($candidate, $problems);
            }

            $draft = $candidate;
            $report = $this->lengths->check($draft, $requirements, $template, $target);
            $history[] = $report['counts'];
        }

        $output = [
            'document' => $draft->toArray(),
            'used_claim_ids' => $ctx->currentDraftClaimIds(),
            'report' => $report,
            'revisions' => $revisions,
            'history' => $history,
        ];

        if (! $report['ok'] && $report['hard']) {
            return StageResult::manualReview(
                'length_limits',
                "The document still breaks its requirements after {$revisions} length revision(s): ".implode(' ', array_column($report['violations'], 'message')),
                $output,
            );
        }

        // Only the soft target band can remain unmet; that is acceptable.
        return StageResult::completed($output + ['soft_target_missed' => ! $report['ok']]);
    }

    private function targetsText(array $targets, array $counts): string
    {
        $parts = [];
        if ($targets['words']) {
            $parts[] = "about {$targets['words']} words (currently {$counts['words']})";
        }
        if ($targets['characters']) {
            $parts[] = "about {$targets['characters']} characters including spaces (currently {$counts['characters']})";
        }

        return $parts ? implode('; ', $parts) : 'keep the current length';
    }
}
