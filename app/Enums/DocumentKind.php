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
            self::Resume => 'A polished, ATS-friendly CV tailored to the target role and, when one is given, to the job description: use its key terms wherever the applicant\'s real experience matches them. Rewrite the customer\'s notes into crisp professional entries; never copy their answers as they are. Adapt section names and emphasis to the applicant\'s field and seniority. '
                .'Sections, each a "heading" block in Title Case, in this order, each only when the material supports it (References always closes the CV): '
                .'1. Professional Summary: one "paragraph" block of three to five lines: who the applicant is professionally, their strongest evidence and the role they target. '
                .'2. Education here, before the experience, for students, recent graduates, applicants with under about three years of experience and education or academic roles; otherwise after the experience sections. Each entry a "subheading" block "Degree | Institution | Dates" with one or two bullets (focus, thesis or research project) only when the material gives them; a secondary school only when given ("WASSCE | School | Dates", with a bullet such as "Programme read: General Science"). '
                .'3. Experience grouped into sections named for the field, most relevant first (a teacher: Teaching Experience, Volunteer Teaching & Outreach, Additional Experience; an engineer: Engineering Experience, Projects). Each role: a "subheading" block written "Job Title | Organisation | Dates", most recent first, then three to five "bullet" blocks (two or three for a short, minor or older role): the applicant\'s own duties and achievements from the material first, rewritten, and when the material gives fewer, the standard duties of that exact role at its level, written as work performed, with no invented numbers, results, tools or seniority. Each bullet starts with a strong, varied action verb (led, built, reduced, trained, negotiated) and shows what was achieved, its scope (team, budget, users, volume) or its result; numbers only where the material gives them, and never turn a responsibility into an achievement it was not. '
                .'4. Seminars & Professional Development (or Certifications & Training): "Title | Provider | Year" with one bullet on what was gained. '
                .'5. Core Competencies: one "paragraph" block of 8 to 12 competencies the material supports, one to three words each, separated by " | ". '
                .'6. Technical Skills: one "bullet" block per tool or group, written "Label: items" ("Microsoft Excel: tracking student performance data"). '
                .'7. Languages: one "bullet" block per language ("English: Full professional proficiency"). '
                .'8. References: one "paragraph" block reading "Available upon request." '
                .'Dates read "2019 – 2023", "2023 – Present" or a single year, never in brackets. One consistent format for dates, titles and punctuation; present tense for a current role, past tense otherwise. No first person, no paragraphs of duties, no contact details, photo, age or marital status. Length: one to two pages unless the requirements state a limit.',
            self::Custom => 'Identify the document\'s function and audience first, then follow the service writing guidance and the customer prompt exactly.',
        };
    }
}
