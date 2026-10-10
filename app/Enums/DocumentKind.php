<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The kind of document a service produces. The kind selects document-type
 * writing guidance in the AI pipeline and default formatting; administrators
 * can add new services of any kind (use "custom" plus writing guidance).
 */
enum DocumentKind: string implements HasLabel
{
    case PersonalStatement = 'personal_statement';
    case StatementOfPurpose = 'statement_of_purpose';
    case MotivationLetter = 'motivation_letter';
    case ScholarshipEssay = 'scholarship_essay';
    case GeneralEssay = 'general_essay';
    case CoverLetter = 'cover_letter';
    case ResearchProposal = 'research_proposal';
    case Resume = 'resume';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::PersonalStatement => 'Personal Statement',
            self::StatementOfPurpose => 'Statement of Purpose',
            self::MotivationLetter => 'Motivation Letter',
            self::ScholarshipEssay => 'Scholarship Essay',
            self::GeneralEssay => 'General Essay',
            self::CoverLetter => 'Cover Letter',
            self::ResearchProposal => 'Research Proposal',
            self::Resume => 'Resume / CV',
            self::Custom => 'Custom document',
        };
    }

    /** Built-in emphasis for each document type, used by the writing prompts. */
    public function writingFocus(): string
    {
        return match ($this) {
            self::PersonalStatement => 'Motivation, relevant experience, preparation, a coherent direction and fit with the programme, connected as past experience, what it showed, present motivation and future goals. For research degrees, honest evidence of research readiness and a focused intellectual direction.',
            self::StatementOfPurpose => 'Academic or research interests narrowed to a focused direction (a central area and a specific problem, from the applicant\'s own material); relevant preparation and honestly calibrated research readiness; why this programme and its verified resources; realistic goals.',
            self::MotivationLetter => 'Why the applicant is applying, evidence of suitability, understanding of the opportunity and what they would contribute. Not the CV in paragraph form. Letter conventions apply.',
            self::ScholarshipEssay => "The scholarship's exact prompt and selection criteria first; relevant achievements with demonstrated impact (not mere involvement), personal motivation and future objectives aligned with the scholarship's purpose. Omit impressive but unrelated qualifications.",
            self::GeneralEssay => 'The prompt, a clear thesis, argument, evidence, logical progression and a conclusion. Follow the requested structure and submission requirements exactly. No personal history or institutional fit unless the prompt asks for it.',
            self::CoverLetter => 'Why this role, concrete evidence of the skills it needs, understanding of the organisation and what the applicant would contribute, in concise professional letter conventions. Not the CV in paragraph form.',
            self::ResearchProposal => 'A clear problem and rationale, research questions, relevant background and gap, proposed approach and methods, feasibility and potential contribution, with fit with supervisors and department only where verified. Detail appropriate to the assignment.',
            self::Resume => 'Relevance to the target role, accuracy and concision: achievement-oriented bullet points, quantified only where the material gives figures, in reverse-chronological order with consistent formatting. Do not turn every responsibility into a claimed achievement.',
            self::Custom => 'Identify the document\'s function and audience first, then follow the service writing guidance and the customer prompt exactly.',
        };
    }
}
