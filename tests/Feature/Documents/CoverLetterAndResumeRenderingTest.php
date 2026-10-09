<?php

use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\RequirementResolver;
use App\Domain\Documents\TemplateResolver;
use Illuminate\Support\Carbon;
use Tests\Feature\Documents\Fixtures;

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
    Carbon::setTestNow('2026-10-08 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    Fixtures::removePrivateDisk();
});

it('resolves and renders a Cover Letter according to formal business cover letter design standards', function () {
    $order = Fixtures::order([
        'customer_name' => 'Sarah Connor',
        'applicant_name' => 'Sarah Connor',
        'country_code' => 'US',
        'service_snapshot' => [
            'name' => 'Cover Letter',
            'document_kind' => 'cover_letter',
            'default_word_limit' => 400,
        ],
    ]);

    $coverLetterModel = DocumentModel::fromArray([
        'title' => 'Cover Letter — Senior Product Manager',
        'applicant_name' => 'Sarah Connor',
        'date' => '2026-10-08',
        'language_variant' => 'en-US',
        'blocks' => [
            ['type' => 'salutation', 'text' => 'Dear Hiring Manager,'],
            ['type' => 'paragraph', 'text' => 'I am writing to express my strong interest in the Senior Product Manager position.'],
            ['type' => 'paragraph', 'text' => 'With over eight years of experience leading cross-functional engineering teams, I have delivered digital products.'],
            ['type' => 'paragraph', 'text' => 'Thank you for your time and consideration.'],
            ['type' => 'closing', 'text' => 'Sincerely,'],
            ['type' => 'signature', 'text' => 'Sarah Connor'],
        ],
    ]);

    $requirements = app(RequirementResolver::class)->resolve($order);
    $template = app(TemplateResolver::class)->resolve($order, $requirements);

    expect($template->slug)->toBe('modern-business-cover-letter')
        ->and($template->font_family)->toBe('Arial')
        ->and((float) $template->font_size)->toEqual(10.5)
        ->and((float) $template->margin_top_mm)->toEqual(20.0);

    $version = Fixtures::rendered($order, $coverLetterModel, $requirements, $template);
    $layout = DocumentLayout::make($version->documentModel(), $version->template_snapshot);

    expect($layout->isLetter)->toBeTrue()
        ->and($version->template_snapshot['template_slug'])->toBe('modern-business-cover-letter')
        ->and($version->template_snapshot['font_family'])->toBe('Arial')
        ->and($version->hasFiles())->toBeTrue();

    // Verify DOCX paragraphs match model (US date format 'October 8, 2026')
    $docxParagraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')))->pluck('text')->all();
    expect($docxParagraphs[0])->toBe('October 8, 2026')
        ->and($docxParagraphs[1])->toBe('Dear Hiring Manager,')
        ->and($docxParagraphs[count($docxParagraphs) - 1])->toBe('Sarah Connor');

    // Run Document QA Validation
    $qa = app(DocumentQa::class)->validate($version);
    expect($qa->passed)->toBeTrue();
});

it('resolves and renders a Resume / CV according to executive resume design standards', function () {
    $order = Fixtures::order([
        'customer_name' => 'Alex Mercer',
        'applicant_name' => 'Alex Mercer',
        'country_code' => 'GB',
        'service_snapshot' => [
            'name' => 'Resume / CV',
            'document_kind' => 'resume',
            'default_word_limit' => 800,
        ],
    ]);

    $resumeModel = DocumentModel::fromArray([
        'title' => 'Alex Mercer',
        'subtitle' => 'Senior Software Engineer | System Architect',
        'applicant_name' => 'Alex Mercer',
        'language_variant' => 'en-GB',
        'blocks' => [
            ['type' => 'heading', 'text' => 'Professional Summary'],
            ['type' => 'paragraph', 'text' => 'Results-driven Senior Software Engineer with 10+ years of experience designing scalable microservices.'],
            ['type' => 'heading', 'text' => 'Core Competencies'],
            ['type' => 'paragraph', 'text' => 'Distributed Systems, Go, Python, PostgreSQL, Redis, Kubernetes, CI/CD Pipelines, System Architecture.'],
            ['type' => 'heading', 'text' => 'Work Experience'],
            ['type' => 'paragraph', 'text' => 'Lead Architect — FinTech Solutions (2021 – Present). Architected high-frequency transaction engine processing 50k TPS.'],
            ['type' => 'paragraph', 'text' => 'Senior Backend Developer — Cloud Native Labs (2017 – 2021). Built cloud orchestration services reducing deployment latency by 40%.'],
            ['type' => 'heading', 'text' => 'Education'],
            ['type' => 'paragraph', 'text' => 'BSc Computer Science (First Class Honours) — Imperial College London (2013 – 2017).'],
        ],
    ]);

    $requirements = app(RequirementResolver::class)->resolve($order);
    $template = app(TemplateResolver::class)->resolve($order, $requirements);

    expect($template->slug)->toBe('executive-resume-cv')
        ->and($template->font_family)->toBe('Calibri')
        ->and((float) $template->font_size)->toEqual(10.5)
        ->and((float) $template->margin_top_mm)->toEqual(18.0);

    $version = Fixtures::rendered($order, $resumeModel, $requirements, $template);
    $layout = DocumentLayout::make($version->documentModel(), $version->template_snapshot);

    expect($layout->isLetter)->toBeFalse()
        ->and($version->template_snapshot['template_slug'])->toBe('executive-resume-cv')
        ->and($version->template_snapshot['font_family'])->toBe('Calibri')
        ->and($version->hasFiles())->toBeTrue();

    // Verify DOCX paragraphs match layout
    $docxParagraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')))->pluck('text')->all();
    expect($docxParagraphs[0])->toBe('Alex Mercer')
        ->and($docxParagraphs[1])->toBe('Senior Software Engineer | System Architect')
        ->and($docxParagraphs)->toContain('Work Experience');

    // Run Document QA Validation
    $qa = app(DocumentQa::class)->validate($version);
    expect($qa->passed)->toBeTrue();
});
