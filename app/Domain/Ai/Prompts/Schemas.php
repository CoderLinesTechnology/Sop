<?php

namespace App\Domain\Ai\Prompts;

use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\ResolvedRequirements;
use App\Enums\SourceType;
use App\Models\QualityReview;
use InvalidArgumentException;

/**
 * Structured-output schemas (OpenAI strict json_schema mode): every object
 * forbids additional properties and lists all of its properties as required;
 * optional values are expressed as nullable types.
 */
final class Schemas
{
    public const FACT_CATEGORIES = [
        'personal_background', 'academic_history', 'work_history', 'project', 'achievement', 'skill',
        'interest', 'motivation', 'career_goal', 'key_experience', 'important_date', 'other',
    ];

    public const CLAIM_CATEGORIES = [
        'programme_overview', 'module', 'structure', 'research_area', 'specialisation', 'lab_or_centre', 'faculty',
        'career_outcome', 'opportunity', 'teaching_approach', 'institution_value', 'scholarship_criteria',
        'admissions_requirement', 'document_requirement', 'deadline', 'other',
    ];

    /** Requirement fields understood by the RequirementResolver. */
    public const REQUIREMENT_FIELDS = [
        'max_words', 'min_words', 'max_characters', 'min_characters', 'max_pages', 'required_sections',
        'language_variant', 'file_types', 'font_family', 'font_size', 'line_spacing', 'margins_mm', 'page_size',
        'naming_convention', 'submission_method', 'prohibited_content', 'special_instructions', 'date_format',
        'application_platform',
    ];

    public const FACT_ISSUE_TYPES = [
        'unsupported_applicant_fact', 'wrong_institution_or_programme', 'wrong_date_or_number',
        'unsupported_goal_or_motivation', 'unverified_research_claim', 'invented_statistic_or_ranking',
        'faculty_or_scholarship_unverified', 'citation_or_url', 'other',
    ];

    /** @return array{name:string, schema:array<string,mixed>} */
    public static function for(string $promptKey): array
    {
        return match ($promptKey) {
            'ingestion' => self::named('applicant_profile', self::applicantProfile()),
            'analysis' => self::named('application_analysis', self::analysis()),
            'research' => self::named('research_findings', self::research()),
            'verification' => self::named('claim_review', self::verification()),
            'strategy' => self::named('narrative_strategy', self::strategy()),
            'writing', 'editorial', 'fact_fix', 'refinement', 'limits', 'revision' => self::named('document_draft', self::documentDraft()),
            'fact_check' => self::named('fact_review', self::factReview()),
            'quality_review' => self::named('quality_review', self::qualityReview()),
            default => throw new InvalidArgumentException("No schema for prompt key [{$promptKey}]."),
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return ['ingestion', 'analysis', 'research', 'verification', 'strategy', 'writing', 'editorial', 'fact_check', 'fact_fix', 'quality_review', 'refinement', 'limits', 'revision'];
    }

    // ---------------------------------------------------------------- schemas

    private static function applicantProfile(): array
    {
        return self::obj([
            'full_name' => self::nstr(),
            'summary' => self::str(),
            'facts' => self::arr(self::obj([
                'id' => self::str(),
                'category' => self::enum(self::FACT_CATEGORIES),
                'statement' => self::str(),
                'date' => self::nstr(),
                'source_type' => self::enum(['answer', 'file', 'order']),
                'source_ref' => self::str(),
                'evidence_quote' => self::str(),
                'confidence' => self::enum(['high', 'medium', 'low']),
            ]), 80),
            'inconsistencies' => self::arr(self::obj([
                'description' => self::str(),
                'fact_ids' => self::arr(self::str(), 10),
            ]), 20),
            'gaps' => self::arr(self::obj([
                'item' => self::str(),
                'critical' => self::bool(),
                'why' => self::str(),
            ]), 20),
            'customer_guidance' => self::arr(self::obj([
                'type' => self::enum(['instruction', 'reference']),
                'source_type' => self::enum(['answer', 'file', 'order']),
                'source_ref' => self::str(),
                'quote' => self::str(),
                'guidance' => self::str(),
            ]), 15),
        ]);
    }

    private static function analysis(): array
    {
        return self::obj([
            'prompt_interpretation' => self::str(),
            'essay_questions' => self::arr(self::obj([
                'question' => self::str(),
                'heading' => self::nstr(),
            ]), 10),
            'qualities_to_demonstrate' => self::arr(self::str(), 12),
            'experiences_to_emphasise' => self::arr(self::obj([
                'fact_id' => self::str(),
                'why' => self::str(),
            ]), 20),
            'research_questions' => self::arr(self::obj([
                'question' => self::str(),
                'purpose' => self::enum(['programme_fit', 'requirements', 'institution', 'faculty_research', 'scholarship_criteria', 'careers', 'other']),
            ]), 15),
            'claims_requiring_verification' => self::arr(self::obj([
                'claim' => self::str(),
                'origin' => self::enum(['customer_answer', 'uploaded_document', 'essay_prompt']),
            ]), 15),
            'official_domains' => self::arr(self::obj([
                'domain' => self::str(),
                'entity' => self::str(),
                'confidence' => self::num(0, 1),
            ]), 8),
            'application_platform' => self::nstr(),
            'stated_limits' => self::obj([
                'max_words' => self::nint(),
                'min_words' => self::nint(),
                'max_characters' => self::nint(),
                'min_characters' => self::nint(),
                'max_pages' => self::nint(),
                'source' => self::nstr(),
                'quote' => self::nstr(),
            ]),
            'required_sections' => self::arr(self::obj([
                'heading' => self::str(),
                'question' => self::nstr(),
                'min_words' => self::nint(),
                'max_words' => self::nint(),
                'min_characters' => self::nint(),
                'max_characters' => self::nint(),
            ]), 10),
            'language_variant' => self::enum(array_keys(ResolvedRequirements::LANGUAGE_VARIANTS), nullable: true),
            'language_variant_source' => self::enum(['stated_in_prompt', 'stated_in_requirements', 'inferred_from_country', 'unknown']),
            'missing_information' => self::arr(self::obj([
                'question' => self::str(),
                'why' => self::str(),
                'critical' => self::bool(),
                'suggested_answers' => self::arr(self::str(), 3),
            ]), 10),
            'risks' => self::arr(self::str(), 10),
        ]);
    }

    private static function research(): array
    {
        return self::obj([
            'programme_found' => self::bool(),
            'claims' => self::arr(self::obj([
                'claim' => self::str(),
                'category' => self::enum(self::CLAIM_CATEGORIES),
                'source_url' => self::str(),
                'source_title' => self::str(),
                'source_type' => self::enum(array_map(fn (SourceType $t) => $t->value, SourceType::cases())),
                'supporting_quote' => self::str(),
                'confidence' => self::num(0, 1),
                'relevance' => self::str(),
                'relevance_score' => self::num(0, 1),
                'requirement_field' => self::enum(self::REQUIREMENT_FIELDS, nullable: true),
                'requirement_value' => self::nstr(),
            ]), 40),
            'fit_summary' => self::str(),
            'requirements_summary' => self::str(),
            'gaps' => self::arr(self::str(), 15),
        ]);
    }

    private static function verification(): array
    {
        return self::obj([
            'reviews' => self::arr(self::obj([
                'claim_id' => self::str(),
                'supported' => self::bool(),
                'notes' => self::str(),
            ]), 80),
            'conflicts' => self::arr(self::obj([
                'claim_ids' => self::arr(self::str(), 10),
                'description' => self::str(),
            ]), 20),
        ]);
    }

    private static function strategy(): array
    {
        $point = self::obj(['point' => self::str(), 'fact_ids' => self::arr(self::str(), 12)]);

        return self::obj([
            'central_thread' => self::str(),
            'opening' => self::obj(['approach' => self::str(), 'fact_ids' => self::arr(self::str(), 6)]),
            'evidence' => self::arr($point, 10),
            'development' => self::str(),
            'programme_fit' => self::arr(self::obj([
                'point' => self::str(),
                'claim_ids' => self::arr(self::str(), 8),
                'fact_ids' => self::arr(self::str(), 8),
            ]), 8),
            'future_goals' => $point,
            'contribution' => $point,
            'conclusion' => self::str(),
            'paragraph_plan' => self::arr(self::obj([
                'purpose' => self::str(),
                'section_heading' => self::nstr(),
                'target_words' => self::int(0, 5000),
                'fact_ids' => self::arr(self::str(), 12),
                'claim_ids' => self::arr(self::str(), 8),
            ]), 20),
            'tone' => self::str(),
            'avoid' => self::arr(self::str(), 12),
            'proposals_to_confirm' => self::arr(self::str(), 6),
        ]);
    }

    private static function documentDraft(): array
    {
        return self::obj([
            'title' => self::nstr(),
            'blocks' => self::arr(self::obj([
                'type' => self::enum(DocumentModel::BLOCK_TYPES),
                'text' => self::str(),
            ]), 60),
            'used_fact_ids' => self::arr(self::str(), 120),
            'used_claim_ids' => self::arr(self::str(), 40),
            'notes' => self::str(),
        ]);
    }

    private static function factReview(): array
    {
        return self::obj([
            'verdict' => self::enum(['pass', 'fix_required']),
            'issues' => self::arr(self::obj([
                'excerpt' => self::str(),
                'problem' => self::str(),
                'type' => self::enum(self::FACT_ISSUE_TYPES),
                'severity' => self::enum(['high', 'medium', 'low']),
                'fix' => self::str(),
            ]), 40),
            'used_claim_ids' => self::arr(self::str(), 40),
        ]);
    }

    private static function qualityReview(): array
    {
        $scores = [];
        foreach (array_keys(QualityReview::CATEGORIES) as $category) {
            $scores[$category] = self::num(0, 10);
        }

        return self::obj([
            'scores' => self::obj($scores),
            'answers_prompt' => self::bool(),
            'prompt_adherence_notes' => self::str(),
            'issues' => self::arr(self::obj([
                'category' => self::enum(array_keys(QualityReview::CATEGORIES)),
                'problem' => self::str(),
                'excerpt' => self::nstr(),
                'severity' => self::enum(['high', 'medium', 'low']),
            ]), 30),
            'instructions' => self::str(),
            'strengths' => self::arr(self::str(), 10),
        ]);
    }

    // ---------------------------------------------------------------- builders

    private static function named(string $name, array $schema): array
    {
        return ['name' => $name, 'schema' => $schema];
    }

    /** @param array<string, array> $properties */
    private static function obj(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    private static function arr(array $items, ?int $maxItems = null): array
    {
        return array_filter(['type' => 'array', 'items' => $items, 'maxItems' => $maxItems], fn ($v) => $v !== null);
    }

    private static function str(): array
    {
        return ['type' => 'string'];
    }

    private static function nstr(): array
    {
        return ['type' => ['string', 'null']];
    }

    private static function bool(): array
    {
        return ['type' => 'boolean'];
    }

    private static function int(int $min, int $max): array
    {
        return ['type' => 'integer', 'minimum' => $min, 'maximum' => $max];
    }

    private static function nint(): array
    {
        return ['type' => ['integer', 'null'], 'minimum' => 0];
    }

    private static function num(float|int $min, float|int $max): array
    {
        return ['type' => 'number', 'minimum' => $min, 'maximum' => $max];
    }

    /** @param list<string> $values */
    private static function enum(array $values, bool $nullable = false): array
    {
        return $nullable
            ? ['type' => ['string', 'null'], 'enum' => [...array_values($values), null]]
            : ['type' => 'string', 'enum' => array_values($values)];
    }
}
