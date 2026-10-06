<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Prompts\PromptValue;
use App\Domain\Ai\Writing\DraftConverter;
use App\Domain\Documents\DocumentModel;

/**
 * Template variables shared by the writing-side prompts. Only text that
 * Statementra or administrators control is marked trusted; everything that
 * comes from the customer, uploads, the web or earlier model output is left
 * untrusted (wrapped in <untrusted_data> blocks by the renderer).
 */
final class PromptInputs
{
    /** @return array<string, mixed> */
    public static function common(StageContext $ctx): array
    {
        return [
            'document_type' => PromptValue::trusted($ctx->documentTypeLabel()),
            'document_focus' => PromptValue::trusted($ctx->documentKind()->writingFocus()),
            'writing_guidance' => PromptValue::trusted($ctx->writingGuidance()),
            'language' => PromptValue::trusted($ctx->languageGuidance()),
            'limits_summary' => PromptValue::trusted($ctx->limitsSummary()),
            'format_rules' => PromptValue::trusted($ctx->formatRules()),
            'banned_phrases' => PromptValue::trusted(implode('; ', $ctx->bannedPhrases()) ?: 'none'),
            'target_words' => $ctx->targetWords(),
            'order_details' => $ctx->orderDetails(),
            'requirements' => self::requirements($ctx),
            'profile' => $ctx->profileForPrompt(),
            'dossier' => $ctx->dossierForPrompt(),
            'applicant_name' => (string) ($ctx->applicantName() ?? ''),
        ];
    }

    /** The resolved requirements as shown to models. */
    public static function requirements(StageContext $ctx): array
    {
        $r = $ctx->requirements();

        return array_filter([
            'limits' => $r->limitsSummary(),
            'min_words' => $r->minWords,
            'max_words' => $r->maxWords,
            'min_characters' => $r->minCharacters,
            'max_characters' => $r->maxCharacters,
            'max_pages' => $r->maxPages,
            'target_words' => $ctx->targetWords(),
            'required_sections' => $r->requiredSections,
            'prohibited_content' => $r->prohibitedContent,
            'special_instructions' => $r->specialInstructions,
            'application_platform' => $r->applicationPlatform,
            'language_variant' => $r->languageVariant,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /** Analysis fields useful to the strategist and writer. */
    public static function analysis(StageContext $ctx): array
    {
        $analysis = $ctx->analysis();

        return array_intersect_key($analysis, array_flip([
            'prompt_interpretation', 'essay_questions', 'qualities_to_demonstrate', 'experiences_to_emphasise',
            'missing_information', 'risks',
        ]));
    }

    /** Structured context for the fake provider (never sent to a real API). */
    public static function fakeContext(StageContext $ctx, ?DocumentModel $draft = null, array $extra = []): array
    {
        $r = $ctx->requirements();

        return $extra + [
            'order' => $ctx->orderDetails(),
            'facts' => $ctx->profileForPrompt()['facts'],
            'claims' => $ctx->dossierForPrompt(),
            'target_words' => $ctx->targetWords(),
            'max_words' => $r->maxWords ? (int) floor($r->maxWords * 0.97) : 0,
            'max_characters' => $r->maxCharacters ? (int) floor($r->maxCharacters * 0.97) : 0,
            'letter' => $ctx->isLetter(),
            'applicant_name' => $ctx->applicantName(),
            'language_variant' => $ctx->languageVariant(),
            'sections' => $r->requiredSections,
            'banned_phrases' => $ctx->bannedPhrases(),
            'document' => $draft ? DraftConverter::forPrompt($draft) : null,
        ];
    }
}
