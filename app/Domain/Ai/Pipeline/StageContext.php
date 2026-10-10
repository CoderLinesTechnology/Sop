<?php

namespace App\Domain\Ai\Pipeline;

use App\Domain\Ai\Prompts\LanguageGuide;
use App\Domain\Ai\Samples\WritingSampleSelector;
use App\Domain\Ai\Writing\EvidenceCorpus;
use App\Domain\Ai\Writing\LengthChecker;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\RequirementResolver;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateResolver;
use App\Enums\DocumentKind;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\Applicant;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\OrderRequirement;
use App\Models\ResearchClaim;
use App\Models\Revision;
use App\Models\UploadedFile;
use App\Models\WritingSample;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything a stage handler needs about the job it is running: the order,
 * the checkpointed outputs of earlier stages, the applicant profile, the
 * verified dossier, the resolved requirements and template, the current
 * draft and the stage's time budget.
 *
 * Prompt helpers return data only; whether a value is trusted is decided
 * where it is passed to the gateway (see PromptRenderer).
 */
final class StageContext
{
    private array $memo = [];

    public function __construct(
        public readonly AiJob $job,
        public readonly Order $order,
        public readonly AiJobStep $step,
        public readonly PipelineStage $stage,
        private readonly float $deadline,
    ) {}

    // ------------------------------------------------------------ job & config

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->job->config($key, $default);
    }

    public function stageConfig(string $key, mixed $default = null): mixed
    {
        return $this->job->config("stages.{$this->stage->value}.{$key}", $default);
    }

    public function isRevision(): bool
    {
        return $this->job->kind === AiJob::KIND_REVISION;
    }

    public function revision(): ?Revision
    {
        if (! array_key_exists('revision', $this->memo)) {
            $this->memo['revision'] = $this->job->revision_id ? Revision::query()->find($this->job->revision_id) : null;
        }

        return $this->memo['revision'];
    }

    public function heartbeat(): void
    {
        $now = now();
        AiJob::query()->whereKey($this->job->id)->update(['heartbeat_at' => $now]);
        $this->job->forceFill(['heartbeat_at' => $now])->syncOriginalAttribute('heartbeat_at');
    }

    public function remainingSeconds(): int
    {
        return (int) floor($this->deadline - microtime(true));
    }

    /**
     * HTTP timeout for the next model call, bounded by what is left of this
     * stage's time budget (which fits inside the worker's lease).
     *
     * @throws StageFailure when too little time is left for another call
     */
    public function callTimeout(): int
    {
        $remaining = $this->remainingSeconds() - 20;
        if ($remaining < 45) {
            throw StageFailure::retryable('stage_time_exhausted', 'Not enough time left in this stage run for another model call.');
        }

        return min(max(30, (int) config('statementra.ai.request_timeout', 300)), $remaining);
    }

    /** Latest completed output of a stage in this job ([] if none). */
    public function output(PipelineStage $stage): array
    {
        return $this->memo['output:'.$stage->value] ??= (array) ($this->job->stageOutput($stage) ?? []);
    }

    /** The most recent completed step before the one currently running. */
    public function previousCompletedStep(): ?AiJobStep
    {
        return AiJobStep::query()
            ->where('ai_job_id', $this->job->id)
            ->where('id', '<', $this->step->id)
            ->where('status', StepStatus::Completed->value)
            ->orderByDesc('id')
            ->first();
    }

    // ----------------------------------------------------------- order data

    public function documentKind(): DocumentKind
    {
        return DocumentKind::tryFrom($this->order->documentKind()) ?? DocumentKind::Custom;
    }

    public function documentTypeLabel(): string
    {
        $kind = $this->documentKind();

        return $kind === DocumentKind::Custom ? $this->order->serviceName() : $kind->getLabel();
    }

    public function isLetter(): bool
    {
        return in_array($this->documentKind(), [DocumentKind::MotivationLetter, DocumentKind::CoverLetter], true);
    }

    /** Administrator-written guidance for the service (trusted text). */
    public function writingGuidance(): string
    {
        $guidance = trim((string) ($this->order->service?->writing_guidance ?? ''));

        return $guidance !== '' ? $guidance : 'None.';
    }

    /**
     * The writing samples chosen for this job (see WritingSampleSelector).
     *
     * @return Collection<int, WritingSample>
     */
    public function writingSamples(): Collection
    {
        return $this->memo['writing_samples'] ??= app(WritingSampleSelector::class)->forJob($this->job, $this->order, $this->documentKind());
    }

    /** @return list<string> */
    public function bannedPhrases(): array
    {
        return array_values(array_filter(array_map('strval', (array) Settings::get('ai.banned_phrases', [])), fn ($p) => trim($p) !== ''));
    }

    /** Structural rules for the writer (trusted; never contains customer or web text). */
    public function formatRules(): string
    {
        $rules = [$this->isLetter()
            ? 'Letter format: one salutation block, body paragraph blocks, one closing block, then one signature block with the applicant\'s name.'
            : 'Essay format: paragraph blocks only — no salutation, sign-off or signature.'];

        $rules[] = $this->requirements()->requiredSections !== []
            ? 'Required sections are listed in the resolved requirements: introduce each with a heading block whose text is exactly the required heading, in the required order.'
            : 'No headings: continuous prose in paragraph blocks.';

        return implode(' ', $rules);
    }

    /** Order facts for prompts (no contact details, no internal ids). */
    public function orderDetails(): array
    {
        $order = $this->order;

        return array_filter([
            'service' => $order->serviceName(),
            'document_type' => $this->documentTypeLabel(),
            'institution' => $order->institution,
            'programme' => $order->programme,
            'degree_level' => $order->degree_level,
            'country' => $order->country_code,
            'intake' => $order->intake,
            'application_deadline' => $order->deadline?->format('j F Y'),
            'essay_question' => $order->essay_prompt,
            'word_limit_stated_by_customer' => $order->word_limit,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The applicant's answers, without contact details or upload fields.
     *
     * @return list<array{key:string, question:string, section:string, answer:string}>
     */
    public function answers(): array
    {
        return $this->memo['answers'] ??= $this->order->answers()->orderBy('id')->get()
            ->reject(fn (OrderAnswer $a) => in_array((string) $a->type, ['email', 'phone', 'file'], true) || in_array($a->field_key, ['email', 'phone'], true))
            ->map(fn (OrderAnswer $a) => [
                'key' => $a->field_key,
                'question' => (string) $a->label,
                'section' => $a->section instanceof \BackedEnum ? $a->section->value : (string) $a->section,
                'answer' => mb_substr(trim($a->displayValue()), 0, 6000),
            ])
            ->filter(fn (array $a) => $a['answer'] !== '')
            ->values()
            ->all();
    }

    /** @return Collection<int, UploadedFile> uploads that passed the malware scan */
    public function usableFiles(): Collection
    {
        return $this->memo['files'] ??= $this->order->files()->orderBy('id')->get()
            ->filter(fn (UploadedFile $file) => $file->isUsable())
            ->values();
    }

    public function applicantName(): ?string
    {
        $name = $this->applicant()?->full_name ?: ($this->order->applicant_name ?: $this->order->customer_name);

        return $name ? trim($name) : null;
    }

    // --------------------------------------------------------------- profile

    public function applicant(): ?Applicant
    {
        if (! array_key_exists('applicant', $this->memo)) {
            $this->memo['applicant'] = Applicant::query()->where('order_id', $this->order->id)->first();
        }

        return $this->memo['applicant'];
    }

    /** @return list<array<string, mixed>> */
    public function facts(): array
    {
        return array_values(array_filter((array) data_get($this->applicant()?->profile, 'facts', []), 'is_array'));
    }

    /** @return list<string> */
    public function factIds(): array
    {
        return array_values(array_filter(array_map(fn ($fact) => (string) ($fact['id'] ?? ''), $this->facts())));
    }

    public function profileForPrompt(): array
    {
        $profile = (array) ($this->applicant()?->profile ?? []);

        return [
            'full_name' => $profile['full_name'] ?? $this->applicantName(),
            'summary' => $profile['summary'] ?? null,
            'facts' => array_map(fn (array $fact) => [
                'id' => $fact['id'] ?? null,
                'category' => $fact['category'] ?? null,
                'statement' => $fact['statement'] ?? null,
                'date' => $fact['date'] ?? null,
                'evidence' => $fact['evidence_quote'] ?? null,
            ], $this->facts()),
        ];
    }

    /** The application analysis (for revisions: the analysis of the job that built the dossier). */
    public function analysis(): array
    {
        if ($this->isRevision()) {
            $jobId = $this->dossierJobId();

            return $this->memo['analysis'] ??= (array) ($jobId ? AiJob::query()->find($jobId)?->stageOutput(PipelineStage::Analysis) : []);
        }

        return $this->output(PipelineStage::Analysis);
    }

    // --------------------------------------------------------------- dossier

    /** The job whose research dossier applies (this job, or for revisions the latest researched job). */
    public function dossierJobId(): ?int
    {
        if (! $this->isRevision()) {
            return $this->job->id;
        }

        return $this->memo['dossier_job'] ??= ResearchClaim::query()
            ->where('order_id', $this->order->id)
            ->whereNotNull('ai_job_id')
            ->max('ai_job_id');
    }

    /** @return Collection<int, ResearchClaim> verified, safe-to-use claims */
    public function safeClaims(): Collection
    {
        return $this->memo['safe_claims'] ??= ($jobId = $this->dossierJobId())
            ? ResearchClaim::query()->with('source')
                ->where('order_id', $this->order->id)
                ->where('ai_job_id', $jobId)
                ->where('safe_to_use', true)
                ->orderBy('id')
                ->get()
            : collect();
    }

    /** @return list<string> */
    public function safeClaimKeys(): array
    {
        return $this->safeClaims()->pluck('claim_key')->map(fn ($k) => (string) $k)->values()->all();
    }

    public function dossierForPrompt(): array
    {
        return $this->safeClaims()->map(fn (ResearchClaim $claim) => [
            'claim_id' => $claim->claim_key,
            'category' => $claim->category,
            'claim' => $claim->claim,
            'quote' => mb_substr((string) $claim->supporting_quote, 0, 400),
            'source' => trim(($claim->source?->title ?: 'Source').' — '.($claim->source?->domain ?? '')),
            'relevance' => $claim->relevance,
        ])->values()->all();
    }

    // ----------------------------------------------------------- requirements

    public function requirements(): ResolvedRequirements
    {
        if (isset($this->memo['requirements'])) {
            return $this->memo['requirements'];
        }

        $data = $this->output(PipelineStage::Requirements)['requirements'] ?? null;
        if (! is_array($data)) {
            $data = $this->latestRequirementLog()?->resolved;
        }

        if (is_array($data)) {
            return $this->memo['requirements'] = ResolvedRequirements::fromArray($data);
        }

        try {
            $requirements = app(RequirementResolver::class)->resolve($this->order, [], $this->customerStatedLimits());
        } catch (Throwable $e) {
            Log::warning('Requirement resolution unavailable; using order defaults.', ['order' => $this->order->reference, 'error' => $e->getMessage()]);
            $requirements = $this->fallbackRequirements();
        }

        return $this->memo['requirements'] = $requirements;
    }

    /**
     * Limits the customer (or their uploaded requirements / essay prompt)
     * stated, as found by the analysis stage.
     */
    public function customerStatedLimits(): array
    {
        $analysis = $this->analysis();
        $stated = [];

        foreach (['max_words', 'min_words', 'max_characters', 'min_characters', 'max_pages'] as $field) {
            $value = data_get($analysis, "stated_limits.{$field}");
            if (is_numeric($value) && (int) $value > 0) {
                $stated[$field] = (int) $value;
            }
        }

        if ($this->order->word_limit && ! isset($stated['max_words'])) {
            $stated['max_words'] = (int) $this->order->word_limit;
        }

        $sections = array_values(array_filter((array) ($analysis['required_sections'] ?? []), fn ($s) => is_array($s) && filled($s['heading'] ?? null)));
        if ($sections !== []) {
            $stated['required_sections'] = array_map(fn (array $s) => array_filter([
                'heading' => (string) $s['heading'],
                'question' => $s['question'] ?? null,
                'min_words' => $s['min_words'] ?? null,
                'max_words' => $s['max_words'] ?? null,
                'min_characters' => $s['min_characters'] ?? null,
                'max_characters' => $s['max_characters'] ?? null,
            ], fn ($v) => $v !== null), $sections);
        }

        if (in_array($analysis['language_variant_source'] ?? null, ['stated_in_prompt', 'stated_in_requirements'], true) && filled($analysis['language_variant'] ?? null)) {
            $stated['language_variant'] = (string) $analysis['language_variant'];
        }

        return $stated;
    }

    public function template(): ?DocumentTemplate
    {
        if (array_key_exists('template', $this->memo)) {
            return $this->memo['template'];
        }

        $id = $this->output(PipelineStage::Requirements)['template_id'] ?? $this->latestRequirementLog()?->document_template_id;
        $template = $id ? DocumentTemplate::query()->find($id) : null;

        if (! $template) {
            try {
                $template = app(TemplateResolver::class)->resolve($this->order, $this->requirements());
            } catch (Throwable $e) {
                Log::warning('Template resolution unavailable; using the default template.', ['order' => $this->order->reference, 'error' => $e->getMessage()]);
                $template = DocumentTemplate::default();
            }
        }

        return $this->memo['template'] = $template;
    }

    public function languageVariant(): string
    {
        return LanguageGuide::normalize($this->requirements()->languageVariant);
    }

    public function languageGuidance(): string
    {
        return LanguageGuide::describe($this->languageVariant());
    }

    public function limitsSummary(): string
    {
        return $this->requirements()->limitsSummary();
    }

    /** Target length in words, within every hard limit. */
    public function targetWords(): int
    {
        $requirements = $this->requirements();
        $base = $requirements->targetWords > 0 ? $requirements->targetWords : 650;
        $targets = (new LengthChecker)->targets($requirements, $this->template(), $base, 0);

        return max(80, (int) ($targets['words'] ?? $base));
    }

    // ------------------------------------------------------------------ draft

    /** The latest draft produced in this job (for revisions, initially the delivered document). */
    public function currentDraft(): ?DocumentModel
    {
        $steps = AiJobStep::query()
            ->where('ai_job_id', $this->job->id)
            ->where('id', '<', $this->step->id)
            ->where('status', StepStatus::Completed->value)
            ->orderByDesc('id')
            ->get(['id', 'output']);

        foreach ($steps as $step) {
            if (is_array($step->output['document'] ?? null)) {
                return DocumentModel::fromArray($step->output['document']);
            }
        }

        if ($this->isRevision()) {
            $version = $this->deliveredVersion();

            return is_array($version?->content) ? DocumentModel::fromArray($version->content) : null;
        }

        return null;
    }

    /** Claim ids the latest draft reports using. */
    public function currentDraftClaimIds(): array
    {
        $steps = AiJobStep::query()
            ->where('ai_job_id', $this->job->id)
            ->where('id', '<', $this->step->id)
            ->where('status', StepStatus::Completed->value)
            ->orderByDesc('id')
            ->get(['id', 'output']);

        foreach ($steps as $step) {
            if (is_array($step->output['document'] ?? null)) {
                return array_values(array_map('strval', (array) ($step->output['used_claim_ids'] ?? [])));
            }
        }

        return [];
    }

    /** The version the customer currently has (for revisions). */
    public function deliveredVersion(): ?DocumentVersion
    {
        return DocumentVersion::query()->where('order_id', $this->order->id)->where('status', 'final')->orderByDesc('id')->first()
            ?? DocumentVersion::query()->where('order_id', $this->order->id)->where('qa_status', 'passed')->orderByDesc('id')->first();
    }

    /** Everything the document may rely on, for the deterministic fact checks. */
    public function evidence(): EvidenceCorpus
    {
        $texts = array_column($this->answers(), 'answer');

        foreach ($this->usableFiles() as $file) {
            if ($file->extracted_text) {
                $texts[] = (string) $file->extracted_text;
            }
        }

        foreach ($this->facts() as $fact) {
            $texts[] = (string) ($fact['statement'] ?? '');
            $texts[] = (string) ($fact['evidence_quote'] ?? '');
            $texts[] = (string) ($fact['date'] ?? '');
        }

        foreach ($this->safeClaims() as $claim) {
            $texts[] = (string) $claim->claim;
            $texts[] = (string) $claim->supporting_quote;
            $texts[] = (string) $claim->source?->title;
        }

        foreach ($this->orderDetails() as $value) {
            $texts[] = (string) $value;
        }

        $requirements = $this->requirements();
        foreach ([$requirements->maxWords, $requirements->minWords, $requirements->maxCharacters, $requirements->minCharacters, $requirements->maxPages] as $limit) {
            if ($limit) {
                $texts[] = (string) $limit;
            }
        }
        foreach ($requirements->requiredSections as $section) {
            $texts[] = trim(($section['heading'] ?? '').' '.($section['question'] ?? ''));
        }

        if ($revision = $this->revision()) {
            $texts[] = (string) $revision->request_text;
        }

        $texts[] = (string) $this->applicantName();

        return new EvidenceCorpus($texts);
    }

    private function latestRequirementLog(): ?OrderRequirement
    {
        if (! array_key_exists('requirement_log', $this->memo)) {
            $this->memo['requirement_log'] = OrderRequirement::query()->where('order_id', $this->order->id)->orderByDesc('id')->first();
        }

        return $this->memo['requirement_log'];
    }

    private function fallbackRequirements(): ResolvedRequirements
    {
        $limit = $this->order->word_limit ? (int) $this->order->word_limit : null;
        $default = (int) (data_get($this->order->service_snapshot, 'default_word_limit') ?: 650);

        return new ResolvedRequirements(
            maxWords: $limit,
            targetWords: $limit ? (int) floor($limit * 0.95) : $default,
            languageVariant: LanguageGuide::normalize($this->order->language_variant ?: 'en-GB'),
        );
    }
}
