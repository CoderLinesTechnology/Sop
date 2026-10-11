<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\Llm\LlmRequest;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Documents\ResolvedRequirements;
use App\Enums\AiJobStatus;
use App\Enums\PipelineStage;
use App\Models\AiJob;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\ResearchClaim;
use Tests\Feature\Ai\Support\PipelineFixtures;

uses(PipelineFixtures::class);

/** A paid CV order: no institution or programme, a pasted job description, the usual CV answers. */
function resumeOrder(object $test, array $attributes = [], array $extraAnswers = []): Order
{
    return $test->paidOrder($attributes + [
        'document_kind' => 'resume',
        'service_snapshot' => ['name' => 'Resume / CV', 'document_kind' => 'resume', 'revision_window_days' => 14, 'revision_fee' => 1500, 'revision_mode' => 'ai'],
        'institution' => null,
        'programme' => null,
        'essay_prompt' => 'We are hiring a Data Analyst to build reporting dashboards in SQL and Power BI for our payments team.',
    ], [
        ['full_name', 'Full name', 'text', 'details', 'Ama Mensah'],
        ['email', 'Email address', 'email', 'details', 'ama.mensah@example.com'],
        ['target_role', 'Target role or job title', 'text', 'application', 'Data Analyst'],
        ['work_history', 'Work experience', 'textarea', 'story', 'I worked as a data analyst at Hubtel in Accra from 2021 to 2023. I built fraud detection dashboards for mobile payments.'],
        ['education', 'Education', 'textarea', 'story', 'I completed a BSc in Computer Science at the University of Ghana in 2021.'],
        ['skills', 'Key skills and tools', 'textarea', 'story', 'I use SQL, Python and Power BI every day.'],
        ...$extraAnswers,
    ]);
}

function promptText(LlmRequest $request): string
{
    return json_encode($request->input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).$request->instructions;
}

it('skips the web research and verification calls for a CV order that names no employer or job posting', function () {
    // The kind's essay-style default (800 words) is what the requirements engine resolves for a CV.
    $this->setUpPipeline(new ResolvedRequirements(targetWords: 800, languageVariant: 'en-GB'));
    $order = resumeOrder($this);

    $job = app(PipelineDispatcher::class)->startForOrder($order)->refresh();

    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and(FakeProvider::calls('research'))->toBe([])
        ->and(FakeProvider::calls('verification'))->toBe([])
        ->and(ResearchClaim::query()->where('order_id', $order->id)->exists())->toBeFalse();

    $research = $job->stageOutput(PipelineStage::Research);
    expect($research['skipped'])->toBeTrue()
        ->and($research['claims'])->toBe([])
        ->and($research['skip_reason'])->toContain('without a named target employer or job-posting link')
        ->and($job->stageOutput(PipelineStage::Verification)['skipped'])->toBeTrue();
});

it('gives a CV a one-to-two-page target and CV format rules instead of an essay target', function () {
    $this->setUpPipeline(new ResolvedRequirements(targetWords: 800, languageVariant: 'en-GB'));
    $order = resumeOrder($this);

    app(PipelineDispatcher::class)->startForOrder($order);

    $writing = FakeProvider::calls('writing')[0];
    expect($writing->context['target_words'])->toBe(500)
        ->and(promptText($writing))
        ->toContain('CV length: one to two pages, about 350 to 600 words (target about 500 words)')
        ->toContain('CV format: a \"heading\" block in Title Case for each section')
        ->toContain('Job Title | Organisation | Dates')
        ->toContain('Degree | Institution | Dates')
        ->toContain('Title | Provider | Year')
        ->toContain('Sections in the order the document-type focus gives, each only when the material supports it, with References always last.')
        ->toContain('2. Education here, before the experience, for students, recent graduates, applicants with under about three years of experience')
        ->toContain('5. Core Competencies: one \"paragraph\" block of 8 to 12 competencies')
        ->toContain('8. References: one \"paragraph\" block reading \"Available upon request.\"')
        ->not->toContain('Professional Summary, Skills, Professional Experience, Education')
        ->toContain('job_description')
        ->not->toContain('essay_question')
        ->not->toContain('No headings: continuous prose')
        ->not->toContain('target about 800 words');

    // The reviewer judges the same CV length, so a concise CV is not marked down against 800 words.
    expect(promptText(FakeProvider::calls('quality_review')[0]))->toContain('about 350 to 600 words');
});

it('completes every CV role to three to five bullets with its standard duties, which the checkers allow and the customer is asked to check', function () {
    $this->setUpPipeline(new ResolvedRequirements(targetWords: 800, languageVariant: 'en-GB'));
    app(PipelineDispatcher::class)->startForOrder(resumeOrder($this));

    expect(promptText(FakeProvider::calls('writing')[0]))
        ->toContain('Every role gets three to five bullets')
        ->toContain('complete the entry with the standard duties of that exact role')
        ->toContain('no responsibility beyond the role\'s level')
        ->toContain('then three to five \"bullet\" blocks (two or three for a short, minor or older role)')
        ->not->toContain('fewer only when the material is thin')
        ->and(promptText(FakeProvider::calls('strategy')[0]))->toContain('We added the usual duties of your Science Teacher and Teaching Intern roles')
        ->and(promptText(FakeProvider::calls('fact_check')[0]))->toContain('bullets describing the standard duties of a role the applicant states')
        ->and(promptText(FakeProvider::calls('quality_review')[0]))->toContain('Each role should have three to five bullets');
});

it('delivers a CV in the reference structure, with education placed by seniority and References last', function (array $extraAnswers, array $expectedHeadings, array $expectedEntries) {
    $this->setUpPipeline(new ResolvedRequirements(targetWords: 800, languageVariant: 'en-GB'));
    $order = resumeOrder($this, [], [
        ['core_skills', 'Strengths', 'textarea', 'story', 'I am good at data cleaning, dashboard design, stakeholder reporting and fraud analysis.'],
        ['training', 'Seminars and training', 'textarea', 'story', 'I attended a workshop on Power BI dashboards by Microsoft Ghana in 2022.'],
        ['languages', 'Languages', 'textarea', 'story', 'English (full professional proficiency), Twi (native).'],
        ...$extraAnswers,
    ]);

    expect(app(PipelineDispatcher::class)->startForOrder($order)->refresh()->status)->toBe(AiJobStatus::Completed);

    $blocks = array_map(
        fn (array $b) => ['type' => $b['type'], 'text' => $b['text']],
        DocumentVersion::query()->where('order_id', $order->id)->orderByDesc('id')->value('content')['blocks'],
    );
    $ofType = fn (string $type) => array_values(array_column(array_filter($blocks, fn ($b) => $b['type'] === $type), 'text'));
    $after = function (string $heading) use ($blocks): array {
        $at = array_search(['type' => 'heading', 'text' => $heading], $blocks, true);
        expect($at)->not->toBeFalse("No [{$heading}] section.");

        return $blocks[$at + 1];
    };

    // Sections in the reference order; entry lines "Role | Organisation | Dates" with unbracketed dates.
    expect($ofType('heading'))->toBe($expectedHeadings)
        ->and($ofType('subheading'))->toBe($expectedEntries);
    foreach ($ofType('subheading') as $entry) {
        expect($entry)->toMatch('/^[^|\[\]]+ \| [^|\[\]]+ \| (?:19|20)\d{2}(?: – (?:(?:19|20)\d{2}|Present))?$/u');
    }

    expect($after('Professional Summary')['type'])->toBe('paragraph')
        ->and($after('Core Competencies'))->toBe(['type' => 'paragraph', 'text' => 'Data Cleaning | Dashboard Design | Stakeholder Reporting | Fraud Analysis'])
        ->and($after('Technical Skills'))->toBe(['type' => 'bullet', 'text' => 'Tools: SQL, Python, Power BI'])
        ->and($ofType('bullet'))->toContain('Built fraud detection dashboards for mobile payments.', 'English: Full professional proficiency', 'Twi: Native')
        ->and(array_slice($blocks, -2))->toBe([['type' => 'heading', 'text' => 'References'], ['type' => 'paragraph', 'text' => 'Available upon request.']])
        ->and(json_encode($blocks, JSON_UNESCAPED_UNICODE))->not->toContain('—');
})->with([
    'early career: education before the experience' => [
        [],
        ['Professional Summary', 'Education', 'Professional Experience', 'Seminars & Professional Development', 'Core Competencies', 'Technical Skills', 'Languages', 'References'],
        ['BSc in Computer Science | University of Ghana | 2021', 'Data Analyst | Hubtel | 2021 – 2023', 'Workshop on Power BI dashboards | Microsoft Ghana | 2022'],
    ],
    'experienced: education after the experience, most recent role first' => [
        [['earlier_work', 'Earlier experience', 'textarea', 'story', 'I worked as a junior analyst at MTN Ghana from 2017 to 2021.']],
        ['Professional Summary', 'Professional Experience', 'Education', 'Seminars & Professional Development', 'Core Competencies', 'Technical Skills', 'Languages', 'References'],
        ['Data Analyst | Hubtel | 2021 – 2023', 'Junior Analyst | MTN Ghana | 2017 – 2021', 'BSc in Computer Science | University of Ghana | 2021', 'Workshop on Power BI dashboards | Microsoft Ghana | 2022'],
    ],
]);

it('keeps a word limit the customer stated for a CV', function () {
    $this->setUpPipeline(new ResolvedRequirements(maxWords: 400, targetWords: 372, languageVariant: 'en-GB'));
    $order = resumeOrder($this);

    app(PipelineDispatcher::class)->startForOrder($order);

    $writing = FakeProvider::calls('writing')[0];
    expect($writing->context['target_words'])->toBe(372)
        ->and(promptText($writing))->toContain('at most 400 words')->not->toContain('about 350 to 600 words');
});

it('researches a CV order that names a target employer or gives a job-posting link', function (array $attributes, array $answers) {
    $this->setUpPipeline();
    $order = resumeOrder($this, $attributes, $answers);

    $job = app(PipelineDispatcher::class)->startForOrder($order)->refresh();

    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and(FakeProvider::calls('research'))->not->toBe([])
        ->and($job->stageOutput(PipelineStage::Research))->not->toHaveKey('skipped');
})->with([
    'employer mapped to the institution' => [['institution' => 'Hubtel'], []],
    'employer named in its own field' => [[], [['target_company', 'Company you are applying to', 'text', 'application', 'Hubtel']]],
    'link in the job description' => [['essay_prompt' => 'Data Analyst role, full posting at https://careers.hubtel.example/jobs/42'], []],
    'link in the notes' => [[], [['additional_notes', 'Anything else we should know?', 'textarea', 'additional', 'The job advert is at www.hubtel.example/careers']]],
]);

it('still researches other document types as before', function () {
    $this->setUpPipeline();
    $order = $this->paidOrder();

    app(PipelineDispatcher::class)->startForOrder($order);

    expect(FakeProvider::calls('research'))->not->toBe([])
        ->and(FakeProvider::calls('writing')[0]->context['target_words'])->toBe(180)
        ->and(promptText(FakeProvider::calls('writing')[0]))->toContain('Essay format: paragraph blocks only')->not->toContain('CV format');
});

it('ignores links the applicant gives about their own work when deciding to research a CV', function () {
    $this->setUpPipeline();
    $order = resumeOrder($this, [], [['portfolio', 'Projects', 'textarea', 'story', 'My dashboards are at https://github.com/ama-example.']]);

    app(PipelineDispatcher::class)->startForOrder($order);

    expect(FakeProvider::calls('research'))->toBe([])
        ->and(AiJob::query()->where('order_id', $order->id)->sole()->status)->toBe(AiJobStatus::Completed);
});

it('does not research a CV just because a follow-up question asked about an employer', function () {
    $this->setUpPipeline();
    $order = resumeOrder($this, [], [
        ['followup_9_q3', 'Is this for a specific vacancy? If so, what are the employer, exact job title and posting link?', 'textarea', 'additional', 'No specific vacancy yet. I want teaching and training roles.'],
    ]);

    app(PipelineDispatcher::class)->startForOrder($order);

    expect(FakeProvider::calls('research'))->toBe([]);
});

it('researches a CV when the customer answers a follow-up question with a job posting link', function () {
    $this->setUpPipeline();
    $order = resumeOrder($this, [], [
        ['followup_9_q3', 'Is this for a specific vacancy?', 'textarea', 'additional', 'Yes, this one: https://careers.example.org/jobs/science-teacher'],
    ]);

    app(PipelineDispatcher::class)->startForOrder($order);

    expect(FakeProvider::calls('research'))->not->toBe([]);
});
