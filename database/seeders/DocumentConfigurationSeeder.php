<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\RequirementRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Formatting templates and requirement rules for the document engine.
 *
 * Rules cover only well-established conventions and application platforms,
 * each with its source and a note to re-verify; last_verified_at stays empty
 * until an administrator has checked the source. No institution-specific
 * rule is invented here: administrators add those from official pages.
 *
 * Idempotent: existing templates (by slug) and rules (by scope and name) are
 * never overwritten, so administrator edits survive re-seeding.
 */
class DocumentConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->templates() as $template) {
                DocumentTemplate::query()->firstOrCreate(['slug' => $template['slug']], $template);
            }

            foreach ($this->rules() as $rule) {
                RequirementRule::query()->firstOrCreate(['scope' => $rule['scope'], 'name' => $rule['name']], $rule + [
                    'is_active' => true,
                    'priority' => 0,
                    'last_verified_at' => null,
                ]);
            }
        });
    }

    private function templates(): array
    {
        $standard = [
            'is_active' => true,
            'priority' => 0,
            'page_size' => 'A4',
            'margin_top_mm' => 25.4,
            'margin_right_mm' => 25.4,
            'margin_bottom_mm' => 25.4,
            'margin_left_mm' => 25.4,
            'font_family' => 'Times New Roman',
            'font_size' => 12,
            'line_spacing' => 1.5,
            'paragraph_spacing_pt' => 8,
            'first_line_indent_mm' => 0,
            'text_align' => 'left',
            'show_title' => true,
            'title_template' => null,
            'title_font_size' => 14,
            'title_align' => 'center',
            'heading_font_size' => 12,
            'applicant_name_position' => 'below_title',
            'header_text' => null,
            'footer_text' => null,
            'page_numbers' => 'bottom_center',
            'page_number_format' => '{PAGE}',
            'date_format' => null,
            'citation_style' => 'none',
            'filename_pattern' => '{applicant_name}_{document_type}',
            'include_name_in_filename' => true,
            'include_branding' => false,
        ];

        return [
            [
                'name' => 'Standard application (A4)',
                'slug' => 'standard-a4',
                'description' => 'Default for statements and essays: Times New Roman 12 pt, 1.5 line spacing, 2.54 cm margins, centred title, page numbers bottom centre.',
                // Default only on a fresh install; never steals the flag from an administrator's choice.
                'is_default' => ! DocumentTemplate::query()->where('is_default', true)->exists(),
                'match_rules' => null,
            ] + $standard,
            [
                'name' => 'US Letter',
                'slug' => 'us-letter',
                'description' => 'United States and Canada: US Letter paper with 1-inch margins.',
                'is_default' => false,
                'priority' => 10,
                'match_rules' => ['countries' => ['US', 'CA']],
                'page_size' => 'Letter',
            ] + $standard,
            [
                'name' => 'Motivation letter (European)',
                'slug' => 'motivation-letter-european',
                'description' => 'Letter layout for motivation and cover letters: starts like a real letter (date line, then the salutation; no document title), closing and signature; Calibri 11 pt, 1.15 spacing.',
                'is_default' => false,
                'priority' => 10,
                'match_rules' => ['document_kinds' => ['motivation_letter', 'cover_letter']],
                'margin_top_mm' => 25,
                'margin_right_mm' => 25,
                'margin_bottom_mm' => 25,
                'margin_left_mm' => 25,
                'font_family' => 'Calibri',
                'font_size' => 11,
                'line_spacing' => 1.15,
                'title_font_size' => 11,
                'title_align' => 'left',
                'heading_font_size' => 11,
                // A letter starts with the date and the salutation: no "Motivation Letter" title or programme line.
                'show_title' => false,
                'applicant_name_position' => 'none',
                'page_numbers' => 'none',
            ] + $standard,
            [
                'name' => 'UCAS personal statement',
                'slug' => 'ucas-personal-statement',
                'description' => 'Plain layout for UCAS answers: each question as a heading, no title and no page numbers (the text is pasted into UCAS).',
                'is_default' => false,
                'priority' => 20,
                'match_rules' => ['platforms' => ['UCAS'], 'document_kinds' => ['personal_statement'], 'degree_levels' => ['undergraduate']],
                'font_family' => 'Arial',
                'font_size' => 11,
                'line_spacing' => 1.15,
                'heading_font_size' => 11,
                'show_title' => false,
                'applicant_name_position' => 'none',
                'page_numbers' => 'none',
            ] + $standard,
            [
                'name' => 'Executive Resume / CV',
                'slug' => 'executive-resume-cv',
                'description' => 'Clean layout for professional CVs and executive resumes: Calibri 10.5 pt, 1.15 line spacing, 18 mm margins.',
                'is_default' => false,
                'priority' => 15,
                'match_rules' => ['document_kinds' => ['cv', 'resume']],
                'margin_top_mm' => 18,
                'margin_right_mm' => 18,
                'margin_bottom_mm' => 18,
                'margin_left_mm' => 18,
                'font_family' => 'Calibri',
                'font_size' => 10.5,
                'line_spacing' => 1.15,
                'heading_font_size' => 12,
                // A CV leads with the person's name as its title (the contact line sits right under it).
                'title_template' => '{applicant_name}',
                'title_font_size' => 20,
                'applicant_name_position' => 'none',
                'page_numbers' => 'bottom_right',
            ] + $standard,
            self::classicCv() + $standard,
            [
                'name' => 'Visa statement & financial intent',
                'slug' => 'visa-statement-of-purpose',
                'description' => 'Formal administrative layout for visa statements of purpose and financial justifications: Arial 11 pt, 1.25 line spacing.',
                'is_default' => false,
                'priority' => 15,
                'match_rules' => ['document_kinds' => ['visa_statement', 'financial_justification', 'intent_letter']],
                'font_family' => 'Arial',
                'font_size' => 11,
                'line_spacing' => 1.25,
                'title_align' => 'left',
                'heading_font_size' => 12,
                'page_numbers' => 'bottom_right',
            ] + $standard,
            [
                'name' => 'Academic research proposal (PhD)',
                'slug' => 'academic-research-proposal',
                'description' => 'Scholarly proposal and research statement layout: Times New Roman 12 pt, 1.5 line spacing, 10 pt paragraph spacing, APA citations.',
                'is_default' => false,
                'priority' => 15,
                'match_rules' => ['degree_levels' => ['phd']],
                'font_family' => 'Times New Roman',
                'font_size' => 12,
                'line_spacing' => 1.5,
                'paragraph_spacing_pt' => 10,
                'heading_font_size' => 13,
                'title_font_size' => 15,
                'citation_style' => 'apa',
                'page_numbers' => 'bottom_center',
            ] + $standard,
            [
                'name' => 'Scholarship & fellowship essay',
                'slug' => 'scholarship-application-essay',
                'description' => 'Elegant layout for competitive scholarship and fellowship application essays: Cambria 11.5 pt, 1.3 line spacing.',
                'is_default' => false,
                'priority' => 15,
                'match_rules' => ['document_kinds' => ['scholarship_essay', 'fellowship_statement']],
                'font_family' => 'Cambria',
                'font_size' => 11.5,
                'line_spacing' => 1.3,
                'heading_font_size' => 13,
                'page_numbers' => 'bottom_center',
            ] + $standard,
            [
                'name' => 'MBA leadership & career statement',
                'slug' => 'mba-leadership-statement',
                'description' => 'Structured business school format for MBA career goals and leadership essays: Calibri 11 pt, 1.2 line spacing.',
                'is_default' => false,
                'priority' => 15,
                'match_rules' => ['degree_levels' => ['mba']],
                'font_family' => 'Calibri',
                'font_size' => 11,
                'line_spacing' => 1.2,
                'heading_font_size' => 13,
                'page_numbers' => 'bottom_right',
            ] + $standard,
            [
                'name' => 'Formal business cover letter',
                'slug' => 'modern-business-cover-letter',
                'description' => 'Clean business format for cover letters and professional inquiries: Arial 10.5 pt, 1.15 line spacing, 20 mm margins.',
                'is_default' => false,
                'priority' => 12,
                'match_rules' => ['document_kinds' => ['cover_letter']],
                'margin_top_mm' => 20,
                'margin_right_mm' => 20,
                'margin_bottom_mm' => 20,
                'margin_left_mm' => 20,
                'font_family' => 'Arial',
                'font_size' => 10.5,
                'line_spacing' => 1.15,
                'heading_font_size' => 11,
                'show_title' => false,
                'applicant_name_position' => 'none',
                'page_numbers' => 'none',
            ] + $standard,
        ];
    }

    /**
     * The default CV layout (it outranks "Executive Resume / CV"), modelled on a
     * classic Word CV: Times New Roman 12 pt single-spaced, the name in capitals
     * as the title, a centred two-line contact block with blue links, blue section
     * headings in capitals over a thin rule, entry lines with the organisation and
     * [dates] in grey and the dates at the right margin, a justified summary and
     * left-aligned bullets. Also used by the production data script.
     */
    public static function classicCv(): array
    {
        return [
            'name' => 'Classic Professional CV',
            'slug' => 'classic-professional-cv',
            'description' => 'Classic CV: Times New Roman 12 pt, the name in capitals as the title, a centred two-line contact block with blue links, blue section headings in capitals with a rule, entry lines with the organisation and [dates] in grey (dates on the right).',
            'is_default' => false,
            'is_active' => true,
            'priority' => 20,
            'match_rules' => ['document_kinds' => ['cv', 'resume']],
            'page_size' => 'A4',
            'margin_top_mm' => 15,
            'margin_right_mm' => 19,
            'margin_bottom_mm' => 15,
            'margin_left_mm' => 19,
            'font_family' => 'Times New Roman',
            'font_size' => 12,
            'line_spacing' => 1,
            'paragraph_spacing_pt' => 2,
            'first_line_indent_mm' => 0,
            'text_align' => 'justify',
            'bullet_align' => 'left',
            'show_title' => true,
            'title_template' => '{applicant_name}',
            'title_font_size' => 16,
            'title_align' => 'center',
            'title_uppercase' => true,
            'heading_font_size' => 12,
            'heading_color' => '#2F5496',
            'heading_uppercase' => true,
            'heading_rule_color' => '#2F5496',
            'applicant_name_position' => 'none',
            'contact_layout' => 'two_lines',
            'link_color' => '#2F5496',
            'entry_line_style' => true,
            'entry_detail_color' => '#595959',
            'page_numbers' => 'none',
        ];
    }

    private function rules(): array
    {
        $a4 = ['source_name' => 'ISO 216 paper sizes (A4) — general reference', 'source_url' => 'https://en.wikipedia.org/wiki/ISO_216'];
        $letter = ['source_name' => 'US Letter paper size — general reference', 'source_url' => 'https://en.wikipedia.org/wiki/Letter_(paper_size)'];
        $note = 'Country convention, not an institutional requirement: official instructions always take precedence. Re-verify periodically.';

        $countries = [
            // code => [name, language variant, page size, date format]
            'GB' => ['United Kingdom', 'en-GB', 'A4', 'j F Y'],
            'IE' => ['Ireland', 'en-IE', 'A4', 'j F Y'],
            'AU' => ['Australia', 'en-AU', 'A4', 'j F Y'],
            'NZ' => ['New Zealand', 'en-NZ', 'A4', 'j F Y'],
            'ZA' => ['South Africa', 'en-ZA', 'A4', 'j F Y'],
            'IN' => ['India', 'en-IN', 'A4', 'j F Y'],
            'NG' => ['Nigeria', 'en-GB', 'A4', 'j F Y'],
            'GH' => ['Ghana', 'en-GB', 'A4', 'j F Y'],
            'KE' => ['Kenya', 'en-GB', 'A4', 'j F Y'],
            'US' => ['United States', 'en-US', 'Letter', 'F j, Y'],
            'CA' => ['Canada', 'en-CA', 'Letter', 'F j, Y'],
            'DE' => ['Germany', 'en-GB', 'A4', 'j F Y'],
            'NL' => ['Netherlands', 'en-GB', 'A4', 'j F Y'],
            'FR' => ['France', 'en-GB', 'A4', 'j F Y'],
            'SE' => ['Sweden', 'en-GB', 'A4', 'j F Y'],
            'BE' => ['Belgium', 'en-GB', 'A4', 'j F Y'],
            'DK' => ['Denmark', 'en-GB', 'A4', 'j F Y'],
            'FI' => ['Finland', 'en-GB', 'A4', 'j F Y'],
            'NO' => ['Norway', 'en-GB', 'A4', 'j F Y'],
            'IT' => ['Italy', 'en-GB', 'A4', 'j F Y'],
            'ES' => ['Spain', 'en-GB', 'A4', 'j F Y'],
            'AT' => ['Austria', 'en-GB', 'A4', 'j F Y'],
            'CH' => ['Switzerland', 'en-GB', 'A4', 'j F Y'],
        ];

        $rules = [];
        foreach ($countries as $code => [$name, $variant, $paper, $dateFormat]) {
            $rules[] = [
                'name' => "Writing conventions: {$name}",
                'scope' => 'country',
                'country_code' => $code,
                'language_variant' => $variant,
                'page_size' => $paper,
                'date_format' => $dateFormat,
                'notes' => $note,
            ] + ($paper === 'Letter' ? $letter : $a4);
        }

        $questions = [
            'Why do you want to study this course or subject?',
            'How have your qualifications and studies helped you to prepare for this course or subject?',
            'What else have you done to prepare outside of education, and why are these experiences useful?',
        ];

        $rules[] = [
            'name' => 'UCAS undergraduate personal statement (2026 entry onwards)',
            'scope' => 'platform',
            'country_code' => 'GB',
            'application_platform' => 'UCAS',
            'degree_level' => 'undergraduate',
            'document_kinds' => ['personal_statement'],
            'max_characters' => 4000,
            'required_sections' => array_map(fn (string $q) => ['heading' => $q, 'question' => $q, 'min_characters' => 350], $questions),
            'language_variant' => 'en-GB',
            'submission_method' => 'Pasted as plain text into the three personal statement questions of the UCAS application',
            'special_instructions' => 'Three separate answers, 4,000 characters in total including spaces, at least 350 characters per answer. UCAS shows the questions, so they are not part of the count.',
            'source_name' => 'UCAS',
            'source_url' => 'https://www.ucas.com/',
            'priority' => 10,
            'notes' => 'Three-question format introduced for 2026 entry. Re-verify the questions and limits on ucas.com before each application cycle.',
        ];

        $rules[] = [
            'name' => 'Common App personal essay',
            'scope' => 'platform',
            'country_code' => 'US',
            'application_platform' => 'Common App',
            'degree_level' => 'undergraduate',
            'document_kinds' => ['personal_statement'],
            'min_words' => 250,
            'max_words' => 650,
            'language_variant' => 'en-US',
            'submission_method' => 'Entered in the personal essay field of the Common App',
            'source_name' => 'Common App',
            'source_url' => 'https://www.commonapp.org/',
            'priority' => 10,
            'notes' => 'Applies only when the Common App is confirmed (customer, prompt or research). Re-verify the length limits on commonapp.org each cycle.',
        ];

        return $rules;
    }
}
