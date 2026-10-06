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
            self::Custom => 'Custom document',
        };
    }

    /** Built-in emphasis for each document type, used by the writing prompts. */
    public function writingFocus(): string
    {
        return match ($this) {
            self::PersonalStatement => 'Personal narrative; academic motivation; relevant experiences; programme fit; future goals.',
            self::StatementOfPurpose => 'Academic background; research or academic interests; programme fit; professional goals; relevant experience.',
            self::MotivationLetter => 'Motivation; fit with the opportunity or programme; personal and professional development; goals. Letter conventions apply.',
            self::ScholarshipEssay => "Follow the scholarship organisation's exact prompt and selection criteria; evidence of merit, impact and future contribution.",
            self::GeneralEssay => 'Follow the requested structure and submission requirements exactly.',
            self::CoverLetter => 'Role or opportunity fit; concrete evidence of relevant skills; concise professional letter conventions.',
            self::ResearchProposal => 'Research question; context and gap; methodology; feasibility; fit with supervisors and department.',
            self::Custom => 'Follow the service writing guidance and the customer prompt exactly.',
        };
    }
}
