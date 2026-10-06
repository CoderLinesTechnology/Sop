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
                'description' => 'Letter layout for motivation and cover letters: date line, subject line, salutation, closing and signature; Calibri 11 pt, 1.15 spacing.',
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
