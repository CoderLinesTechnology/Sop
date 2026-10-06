<?php

namespace Database\Seeders;

use App\Enums\FieldMapping;
use App\Models\Faq;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The initial service catalogue. Everything here is ordinary data that
 * administrators edit in the admin panel; adding a service later requires
 * no code. Re-running the seeder never overwrites existing services.
 */
class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->services() as $order => $definition) {
                if (Service::withTrashed()->where('slug', $definition['slug'])->exists()) {
                    continue;
                }

                $fields = $definition['fields'];
                $faqs = $definition['faqs'] ?? [];
                unset($definition['fields'], $definition['faqs']);

                $service = Service::query()->create($definition + [
                    'display_order' => $order,
                    'currency' => 'USD',
                    'is_active' => true,
                    'revisions_included' => 1,
                    'revision_window_days' => 14,
                    'revision_fee' => 1500,
                    'revision_mode' => 'ai',
                ]);

                foreach ($fields as $position => $field) {
                    $service->fields()->create($field + [
                        'display_order' => $position,
                        'is_active' => true,
                        'width' => $field['width'] ?? 'full',
                    ]);
                }

                foreach ($faqs as $position => [$question, $answer]) {
                    Faq::query()->create([
                        'question' => $question,
                        'answer' => $answer,
                        'scope' => 'service',
                        'service_id' => $service->id,
                        'display_order' => $position,
                        'is_published' => true,
                    ]);
                }
            }
        });
    }

    private function services(): array
    {
        return [
            [
                'name' => 'Personal Statement',
                'slug' => 'personal-statement',
                'document_kind' => 'personal_statement',
                'short_description' => 'A compelling personal narrative that highlights your background, experiences, values and future goals.',
                'description' => "Your personal statement is where admissions readers meet the person behind the grades. We build it from your real experiences and research the programme you're applying to, so every paragraph connects who you are with why this course is the right next step.\n\nYou receive a polished PDF ready to submit and an editable Word document, usually within 20–30 minutes.",
                'card_features' => ['Personalized & authentic', 'Programme-focused', 'Professionally written'],
                'badge' => 'Most Popular',
                'is_featured' => true,
                'icon' => 'document',
                'icon_color' => 'green',
                'price' => 8900,
                'compare_at_price' => 12000,
                'default_word_limit' => 650,
                'seo_title' => 'Personal Statement Writing Service — Researched & Personalized',
                'seo_description' => 'A personal statement built around your real story and researched around your programme. PDF + Word delivered to your email in about 20–30 minutes.',
                'writing_guidance' => 'Lead with a specific, genuine moment or question from the applicant\'s own experience. Show the development of their interest through concrete evidence, connect it to the specific programme, and close with realistic goals.',
                'fields' => [...$this->detailFields(), ...$this->applicationFields(), ...$this->uploadFields(), ...[
                    $this->story('why_field', 'Why are you interested in this field?', 'recommended', 'A moment, project, book, job or problem that sparked or deepened your interest.'),
                    $this->story('background', 'Your background', 'recommended', 'Education, work experience, projects, achievements, volunteering or leadership. Skip anything already in your CV.', optionalWithCv: true, ai: 'Primary evidence for the narrative. Never embellish.'),
                    $this->story('why_programme', 'Why did you choose this programme?', 'recommended', 'What in the course content, structure or approach appeals to you?'),
                    $this->story('why_institution', 'Why this university?', 'optional', 'We will also research the institution, so a sentence or two is enough.'),
                    $this->story('goals', 'What are your future goals?', 'recommended', 'Where do you hope this programme will take you?'),
                ], ...$this->additionalFields()],
                'faqs' => [
                    ['How long should a personal statement be?', 'It depends on where you apply. UCAS (UK undergraduate) uses a 4,000-character limit, US applications often allow around 650 words, and many postgraduate programmes set their own limits. We research the requirement for your programme and keep your statement within it.'],
                    ['Will it sound like me?', 'Yes. We write from your own experiences and answers, in a natural voice appropriate for a strong applicant at your level — no generic filler, no invented achievements.'],
                ],
            ],
            [
                'name' => 'Statement of Purpose',
                'slug' => 'statement-of-purpose',
                'document_kind' => 'statement_of_purpose',
                'short_description' => 'A focused document that explains your academic background, career goals and why you\'re the right fit for the programme.',
                'description' => "A statement of purpose has to show admissions committees what you want to study, why you are prepared for it and how the programme fits your goals. We analyse your background, research the programme, its research areas and faculty interests, and write a focused SOP that answers exactly what the department asks.\n\nDelivered as a submission-ready PDF and an editable Word document.",
                'card_features' => ['Research-driven', 'Programme & institution specific', 'Well-structured and polished'],
                'icon' => 'graduation-cap',
                'icon_color' => 'blue',
                'price' => 9900,
                'compare_at_price' => 14000,
                'default_word_limit' => 900,
                'seo_title' => 'Statement of Purpose (SOP) Writing Service',
                'seo_description' => 'A research-driven statement of purpose tailored to your programme, faculty and goals. Fact-checked, professionally formatted, delivered by email.',
                'writing_guidance' => 'Prioritise academic preparation, specific research or academic interests, concrete programme fit (modules, research areas, faculty interests only if verified) and professional goals. Keep the tone focused and scholarly without jargon.',
                'fields' => [...$this->detailFields(), ...$this->applicationFields(), ...$this->uploadFields(), ...[
                    $this->story('background', 'Your academic background', 'recommended', 'Degrees, key modules, projects, thesis, grades you are proud of. Skip anything already in your CV.', optionalWithCv: true),
                    $this->story('research_interests', 'What are your academic or research interests?', 'recommended', 'Topics, questions or problems you want to work on.'),
                    $this->story('experience', 'Relevant work or research experience', 'optional', 'Roles, labs, internships, publications, tools you have used.', optionalWithCv: true),
                    $this->story('why_programme', 'Why did you choose this programme?', 'recommended', 'Courses, specialisations or research areas that attract you.'),
                    $this->story('goals', 'What are your career goals?', 'recommended', 'Short- and long-term goals after the degree.'),
                ], ...$this->additionalFields()],
                'faqs' => [
                    ['Do you research the faculty and research groups?', 'Yes. Our research process checks official programme, department and faculty pages and only uses information we can verify. We never invent professors, labs or modules.'],
                ],
            ],
            [
                'name' => 'Motivation Letter',
                'slug' => 'motivation-letter',
                'document_kind' => 'motivation_letter',
                'short_description' => 'A persuasive letter that showcases your motivation, passion and commitment to the opportunity.',
                'description' => "Motivation letters are common for programmes in Europe and for scholarships, exchanges and fellowships. We write a clear, sincere letter that connects your motivation and experience to the specific opportunity — formatted to the conventions of the country you are applying to.\n\nDelivered as a submission-ready PDF and an editable Word document.",
                'card_features' => ['Personal and authentic', 'Highlights your unique value', 'Tailored to the specific opportunity'],
                'icon' => 'envelope',
                'icon_color' => 'purple',
                'price' => 7900,
                'compare_at_price' => 11000,
                'default_word_limit' => 600,
                'seo_title' => 'Motivation Letter Writing Service',
                'seo_description' => 'A sincere, specific motivation letter for university programmes, scholarships and exchanges — formatted to your destination country\'s conventions.',
                'writing_guidance' => 'Write as a formal letter (salutation, body, closing) following the destination country\'s conventions. Focus on motivation, fit with the opportunity, personal and professional development, and goals. Keep it to one page unless a longer limit is stated.',
                'fields' => [...$this->detailFields(), ...$this->applicationFields(), ...$this->uploadFields(), ...[
                    $this->story('motivation', 'What motivates you to apply?', 'recommended', 'What do you hope to gain, and why now?'),
                    $this->story('background', 'Relevant experience', 'recommended', 'Studies, work, volunteering or projects that prepared you. Skip anything already in your CV.', optionalWithCv: true),
                    $this->story('why_programme', 'Why this programme or opportunity?', 'recommended', 'What makes it the right fit for you?'),
                    $this->story('goals', 'What are your goals?', 'optional', 'How will this opportunity help you reach them?'),
                ], ...$this->additionalFields()],
            ],
            [
                'name' => 'Scholarship Essay',
                'slug' => 'scholarship-essay',
                'document_kind' => 'scholarship_essay',
                'short_description' => 'A powerful essay that demonstrates your achievements, potential and why you deserve the scholarship.',
                'description' => "Scholarship committees read thousands of essays. We research the scholarship's selection criteria and values, then write an essay that answers its exact prompt with evidence of your achievements, leadership and the impact you intend to make.\n\nDelivered as a submission-ready PDF and an editable Word document.",
                'card_features' => ['Meets specific requirements', 'Showcases your impact and goals', 'Compelling and well-structured'],
                'icon' => 'star',
                'icon_color' => 'yellow',
                'price' => 9500,
                'compare_at_price' => 13000,
                'default_word_limit' => 700,
                'seo_title' => 'Scholarship Essay Writing Service',
                'seo_description' => 'Scholarship essays researched around the scholarship\'s criteria and written from your real achievements and goals. PDF + Word by email.',
                'writing_guidance' => "Answer the scholarship's exact prompt. Map the applicant's evidence to the scholarship's published selection criteria (only if verified). Emphasise achievements, leadership, impact and a credible plan for using the opportunity.",
                'fields' => [...$this->detailFields(), ...$this->applicationFields(scholarship: true), ...$this->uploadFields(), ...[
                    $this->story('achievements', 'Your key achievements', 'recommended', 'Academic, leadership, community or professional achievements you are proud of. Skip anything already in your CV.', optionalWithCv: true),
                    $this->story('impact', 'What impact do you want to make?', 'recommended', 'In your community, field or country — and how this scholarship helps.'),
                    $this->story('why_programme', 'What will you study, and why?', 'recommended', 'The programme you intend to pursue with the scholarship.'),
                    $this->story('circumstances', 'Anything about your circumstances the committee should know?', 'optional', 'Only if relevant and you are comfortable sharing it (e.g. financial need, first-generation student).'),
                ], ...$this->additionalFields()],
            ],
            [
                'name' => 'General Essay',
                'slug' => 'general-essay',
                'document_kind' => 'general_essay',
                'short_description' => 'A well-researched and professionally written essay for various academic or professional purposes.',
                'description' => "Need a supplemental essay, a short-answer response or another application essay? Tell us the question and any requirements, and we'll research, write and format a clear, well-structured essay that follows them exactly.\n\nDelivered as a submission-ready PDF and an editable Word document.",
                'card_features' => ['Original and well-researched', 'Clear structure and flow', 'Follows your requirements'],
                'icon' => 'pen',
                'icon_color' => 'teal',
                'price' => 6900,
                'compare_at_price' => 10000,
                'default_word_limit' => 600,
                'seo_title' => 'Application Essay Writing Service',
                'seo_description' => 'Supplemental and general application essays written to your exact prompt and requirements, researched and fact-checked.',
                'writing_guidance' => 'Follow the requested structure, tone and submission requirements exactly. If the essay is argumentative or analytical, keep claims supported by verified sources or the applicant\'s own experience.',
                'fields' => [...$this->detailFields(), ...[
                    ['key' => 'essay_topic', 'label' => 'Essay question or topic', 'type' => 'textarea', 'section' => 'application', 'requirement' => 'required', 'maps_to' => FieldMapping::EssayPrompt->value, 'placeholder' => 'Paste the exact question or describe the topic.', 'validation' => ['max_length' => 3000]],
                    ['key' => 'institution', 'label' => 'University / organisation', 'type' => 'text', 'section' => 'application', 'requirement' => 'optional', 'maps_to' => FieldMapping::Institution->value, 'placeholder' => 'e.g. University of Toronto', 'width' => 'half'],
                    ['key' => 'programme', 'label' => 'Programme / purpose', 'type' => 'text', 'section' => 'application', 'requirement' => 'optional', 'maps_to' => FieldMapping::Programme->value, 'placeholder' => 'e.g. Supplemental essay for BSc Economics', 'width' => 'half'],
                    $this->countryField('optional'),
                    $this->deadlineField(),
                    ['key' => 'word_limit', 'label' => 'Word limit (if stated)', 'type' => 'number', 'section' => 'application', 'requirement' => 'optional', 'maps_to' => FieldMapping::WordLimit->value, 'placeholder' => 'e.g. 500', 'validation' => ['min' => 50, 'max' => 5000], 'width' => 'half'],
                    ['key' => 'tone', 'label' => 'Preferred tone', 'type' => 'select', 'section' => 'application', 'requirement' => 'optional', 'options' => ['choices' => [
                        ['value' => 'reflective', 'label' => 'Reflective / personal'],
                        ['value' => 'academic', 'label' => 'Formal / academic'],
                        ['value' => 'persuasive', 'label' => 'Persuasive'],
                    ]], 'width' => 'half'],
                ], ...$this->uploadFields(), ...[
                    $this->story('key_points', 'Key points or experiences to include', 'recommended', 'Anything you definitely want the essay to mention.'),
                    $this->story('background', 'Relevant background', 'optional', 'Experience or knowledge the essay can draw on. Skip anything already in your CV.', optionalWithCv: true),
                ], ...$this->additionalFields()],
            ],
        ];
    }

    private function detailFields(): array
    {
        return [
            ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'section' => 'details', 'requirement' => 'required', 'maps_to' => FieldMapping::CustomerName->value, 'placeholder' => 'e.g. Daniel Essel', 'help_text' => 'As it should appear on your document.', 'validation' => ['max_length' => 120], 'width' => 'half'],
            ['key' => 'email', 'label' => 'Email address', 'type' => 'email', 'section' => 'details', 'requirement' => 'required', 'maps_to' => FieldMapping::Email->value, 'placeholder' => 'e.g. you@example.com', 'help_text' => 'Your finished document will be sent here.', 'width' => 'half'],
            ['key' => 'phone', 'label' => 'Phone number', 'type' => 'phone', 'section' => 'details', 'requirement' => 'optional', 'maps_to' => FieldMapping::Phone->value, 'placeholder' => 'e.g. 24 123 4567', 'help_text' => 'Only used if we need to reach you about your order.'],
        ];
    }

    private function applicationFields(bool $scholarship = false): array
    {
        return [
            ['key' => 'institution', 'label' => $scholarship ? 'Scholarship / organisation' : 'University / Institution', 'type' => 'text', 'section' => 'application', 'requirement' => 'required', 'maps_to' => FieldMapping::Institution->value, 'placeholder' => $scholarship ? 'e.g. Chevening Scholarships' : 'e.g. University of Oxford', 'validation' => ['max_length' => 200], 'width' => 'half'],
            ['key' => 'programme', 'label' => 'Programme / Course', 'type' => 'text', 'section' => 'application', 'requirement' => $scholarship ? 'recommended' : 'required', 'maps_to' => FieldMapping::Programme->value, 'placeholder' => 'e.g. MSc Computer Science', 'validation' => ['max_length' => 200], 'width' => 'half'],
            $this->countryField('required'),
            $this->deadlineField(),
            ['key' => 'degree_level', 'label' => 'Level of study', 'type' => 'select', 'section' => 'application', 'requirement' => 'recommended', 'maps_to' => FieldMapping::DegreeLevel->value, 'options' => ['choices' => [
                ['value' => 'undergraduate', 'label' => "Undergraduate / Bachelor's"],
                ['value' => 'masters', 'label' => "Master's"],
                ['value' => 'mba', 'label' => 'MBA'],
                ['value' => 'phd', 'label' => 'PhD / Doctorate'],
                ['value' => 'postgraduate_diploma', 'label' => 'Postgraduate diploma / certificate'],
                ['value' => 'exchange', 'label' => 'Exchange / visiting'],
                ['value' => 'other', 'label' => 'Other'],
            ]], 'width' => 'half'],
            ['key' => 'word_limit', 'label' => 'Word limit (if stated)', 'type' => 'number', 'section' => 'application', 'requirement' => 'optional', 'maps_to' => FieldMapping::WordLimit->value, 'placeholder' => 'e.g. 1000', 'help_text' => "Leave blank if you're not sure — we check the official requirements.", 'validation' => ['min' => 50, 'max' => 5000], 'width' => 'half'],
            ['key' => 'essay_prompt', 'label' => 'Essay question or prompt (if any)', 'type' => 'textarea', 'section' => 'application', 'requirement' => 'recommended', 'maps_to' => FieldMapping::EssayPrompt->value, 'placeholder' => 'Paste the exact question from the application, e.g. "Why do you want to study this programme?"', 'validation' => ['max_length' => 3000]],
        ];
    }

    private function countryField(string $requirement): array
    {
        return ['key' => 'country', 'label' => 'Country', 'type' => 'country', 'section' => 'application', 'requirement' => $requirement, 'maps_to' => FieldMapping::Country->value, 'help_text' => 'Where you are applying. We follow that country\'s conventions.', 'width' => 'half'];
    }

    private function deadlineField(): array
    {
        return ['key' => 'deadline', 'label' => 'Application deadline', 'type' => 'date', 'section' => 'application', 'requirement' => 'optional', 'maps_to' => FieldMapping::Deadline->value, 'width' => 'half'];
    }

    private function uploadFields(): array
    {
        $file = fn (string $key, string $label, string $purpose, string $help, int $max = 2) => [
            'key' => $key, 'label' => $label, 'type' => 'file', 'section' => 'application', 'requirement' => 'optional',
            'help_text' => $help, 'options' => ['accept' => ['pdf', 'docx', 'txt', 'jpg', 'png'], 'max_files' => $max, 'purpose' => $purpose],
        ];

        return [
            $file('cv', 'CV / Resume', 'cv', 'We extract your education, experience and achievements automatically.', 1),
            $file('requirements', 'Programme requirements', 'requirements', 'The programme page, application instructions or scholarship criteria.', 3),
            $file('previous_statement', 'Previous statement', 'previous_statement', 'An earlier draft or statement we can learn from.', 2),
            $file('transcript', 'Transcript', 'transcript', 'Academic records (grades help us describe your preparation accurately).', 2),
            $file('other', 'Other documents', 'other', 'Writing samples, certificates or anything else relevant.', 3),
        ];
    }

    private function story(string $key, string $label, string $requirement, string $help, bool $optionalWithCv = false, ?string $ai = null): array
    {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'type' => 'textarea',
            'section' => 'story',
            'requirement' => $requirement,
            'help_text' => $help,
            'validation' => ['max_length' => 3000],
            'optional_when_upload' => $optionalWithCv ? 'cv' : null,
            'ai_hint' => $ai,
        ], fn ($v) => $v !== null);
    }

    private function additionalFields(): array
    {
        return [[
            'key' => 'additional_notes',
            'label' => 'Anything else we should know?',
            'type' => 'textarea',
            'section' => 'additional',
            'requirement' => 'optional',
            'placeholder' => 'e.g. I have a gap year, I would like to highlight a specific experience, etc.',
            'validation' => ['max_length' => 500],
        ]];
    }
}
