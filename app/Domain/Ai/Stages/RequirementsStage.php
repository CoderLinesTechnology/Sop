<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Documents\RequirementResolver;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateResolver;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Models\Order;
use App\Models\OrderRequirement;
use App\Models\ResearchClaim;

/**
 * Resolves the requirements the document must satisfy from the verified,
 * safe requirement findings and the limits the customer stated, picks the
 * formatting template, logs both in order_requirements, stores the language
 * variant on the order and marks research complete (RESEARCH_COMPLETE).
 */
class RequirementsStage implements Stage
{
    private const LANGUAGE_NAMES = [
        'british' => 'en-GB', 'uk' => 'en-GB', 'american' => 'en-US', 'us' => 'en-US', 'canadian' => 'en-CA',
        'australian' => 'en-AU', 'new zealand' => 'en-NZ', 'irish' => 'en-IE', 'south african' => 'en-ZA', 'indian' => 'en-IN',
    ];

    public function __construct(
        private readonly RequirementResolver $resolver,
        private readonly TemplateResolver $templates,
        private readonly OrderStateMachine $states,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $verification = $ctx->output(PipelineStage::Verification);
        $findings = (array) ($verification['requirements'] ?? []);

        $researched = [];
        foreach ($ctx->safeClaims() as $claim) {
            /** @var ResearchClaim $claim */
            $finding = $findings[$claim->claim_key] ?? null;
            if (! $finding || blank($finding['field'] ?? null)) {
                continue;
            }

            $value = $this->parseValue((string) $finding['field'], $finding['value'] ?? null);
            if ($value === null || $value === []) {
                continue;
            }

            $researched[] = [
                'field' => (string) $finding['field'],
                'value' => $value,
                'source_url' => $claim->source?->url,
                'source_type' => $claim->source?->source_type?->value ?? 'secondary',
                'quote' => $claim->supporting_quote,
                'verified' => true,
            ];
        }

        $stated = $ctx->customerStatedLimits();
        $requirements = $this->resolver->resolve($ctx->order, $researched, $stated);
        $template = $this->templates->resolve($ctx->order, $requirements);

        $conflicts = array_merge(
            $requirements->conflicts,
            array_map(fn ($c) => ['field' => $c['group'], 'candidates' => $c['claim_ids'], 'chosen' => $c['winner'], 'reason' => $c['description']], (array) ($verification['conflicts'] ?? [])),
        );

        OrderRequirement::query()->create([
            'order_id' => $ctx->order->id,
            'ai_job_id' => $ctx->job->id,
            'resolved' => $requirements->toArray(),
            'sources' => $requirements->sources ?: array_map(fn ($r) => ['name' => $r['field'], 'url' => $r['source_url'], 'type' => $r['source_type'], 'checked_at' => now()->toIso8601String()], $researched),
            'conflicts' => $conflicts ?: null,
            'applied_rule_ids' => $requirements->appliedRuleIds ?: null,
            'document_template_id' => $template->id,
            'language_variant' => $requirements->languageVariant,
            'max_words' => $requirements->maxWords,
            'max_characters' => $requirements->maxCharacters,
            'max_pages' => $requirements->maxPages,
            'last_verified_at' => now(),
        ]);

        Order::query()->whereKey($ctx->order->id)->update(['language_variant' => mb_substr($requirements->languageVariant, 0, 10)]);
        $ctx->order->forceFill(['language_variant' => $requirements->languageVariant])->syncOriginalAttribute('language_variant');

        if (! $ctx->isRevision()) {
            $this->states->transitionIfAllowed($ctx->order->refresh(), OrderStatus::ResearchComplete, 'system', 'Research and requirement verification complete');
        }

        return StageResult::completed([
            'requirements' => $requirements->toArray(),
            'template_id' => $template->id,
            'limits' => $requirements->limitsSummary(),
            'researched' => $researched,
            'customer_stated' => $stated,
        ]);
    }

    /** Convert a researched requirement value (written as found) into the resolver's type. */
    private function parseValue(string $field, mixed $raw): mixed
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        return match ($field) {
            'max_words', 'min_words', 'max_characters', 'min_characters', 'max_pages' => preg_match('/\d[\d,]*/', $value, $m) ? (int) str_replace(',', '', $m[0]) : null,
            // Units and phrases ("2.54 cm", "double spacing", "12pt") are converted by the resolver.
            'font_size', 'line_spacing', 'margins_mm' => mb_substr($value, 0, 100),
            'required_sections' => array_values(array_map(fn ($h) => ['heading' => $h], array_filter(array_map('trim', preg_split('/\s*(?:\||\n)\s*/', $value) ?: [])))),
            'file_types' => array_values(array_unique(array_filter(array_map(fn ($t) => strtolower(trim($t, " .\t")), preg_split('/[,\/|;\s]+/', $value) ?: [])))),
            'prohibited_content' => array_values(array_filter(array_map('trim', preg_split('/\s*(?:\||;|\n)\s*/', $value) ?: []))),
            'language_variant' => $this->languageVariant($value),
            default => mb_substr($value, 0, 1000),
        };
    }

    private function languageVariant(string $value): ?string
    {
        if (array_key_exists($value, ResolvedRequirements::LANGUAGE_VARIANTS)) {
            return $value;
        }

        $lower = mb_strtolower($value);
        foreach (self::LANGUAGE_NAMES as $name => $code) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/u', $lower)) {
                return $code;
            }
        }

        return null;
    }
}
