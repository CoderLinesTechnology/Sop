<?php

use App\Domain\Documents\DocumentFactory;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\RequirementResolver;
use App\Domain\Documents\ResolvedRequirements;
use App\Models\DocumentTemplate;
use App\Models\OrderRequirement;
use App\Models\RequirementRule;
use Database\Seeders\DocumentConfigurationSeeder;
use Tests\Feature\Documents\Fixtures;

beforeEach(fn () => Fixtures::isolatePrivateDisk());
afterEach(fn () => Fixtures::removePrivateDisk());

it('seeds the document templates and requirement rules idempotently', function () {
    (new DocumentConfigurationSeeder)->run();
    DocumentTemplate::query()->where('slug', 'us-letter')->update(['font_size' => 11.5]); // an administrator's edit
    (new DocumentConfigurationSeeder)->run();

    $templates = DocumentTemplate::query()->pluck('slug')->all();
    $ucas = RequirementRule::query()->where('application_platform', 'UCAS')->sole();
    $commonApp = RequirementRule::query()->where('application_platform', 'Common App')->sole();

    expect($templates)->toEqualCanonicalizing([
        'standard-a4',
        'us-letter',
        'motivation-letter-european',
        'ucas-personal-statement',
        'executive-resume-cv',
        'visa-statement-of-purpose',
        'academic-research-proposal',
        'scholarship-application-essay',
        'mba-leadership-statement',
        'modern-business-cover-letter',
    ])
        ->and(DocumentTemplate::query()->where('is_default', true)->pluck('slug')->all())->toBe(['standard-a4'])
        ->and(DocumentTemplate::query()->where('slug', 'us-letter')->value('font_size'))->toEqual(11.5)
        ->and(RequirementRule::query()->where('scope', 'country')->count())->toBe(23)
        ->and(RequirementRule::query()->whereNull('source_url')->count())->toBe(0)
        ->and(RequirementRule::query()->whereNotNull('last_verified_at')->count())->toBe(0)
        ->and(RequirementRule::query()->where('notes', 'like', '%e-verify%')->count())->toBe(RequirementRule::query()->count())
        ->and(RequirementRule::query()->where('scope', 'institution')->count())->toBe(0) // nothing institution-specific is invented
        ->and($ucas->max_characters)->toBe(4000)
        ->and(array_column($ucas->required_sections, 'question'))->toBe(Fixtures::UCAS_QUESTIONS)
        ->and(array_unique(array_column($ucas->required_sections, 'min_characters')))->toBe([350])
        ->and($ucas->source_url)->toBe('https://www.ucas.com/')
        ->and([$commonApp->min_words, $commonApp->max_words])->toBe([250, 650])
        ->and($commonApp->source_url)->toBe('https://www.commonapp.org/');
});

it('does not take the default flag from an administrator’s template', function () {
    Fixtures::template('house-style', ['is_default' => true]);

    (new DocumentConfigurationSeeder)->run();

    expect(DocumentTemplate::query()->where('is_default', true)->pluck('slug')->all())->toBe(['house-style']);
});

it('records where each resolved value came from', function () {
    Fixtures::seed();
    $requirements = Fixtures::resolve(['country_code' => 'US', 'word_limit' => 800]);

    expect($requirements->fieldSources)->toMatchArray([
        'maxWords' => 'customer',
        'pageSize' => 'rule:country',
        'languageVariant' => 'rule:country',
    ])
        ->and($requirements->isConvention('pageSize'))->toBeTrue()
        ->and($requirements->isConvention('maxWords'))->toBeFalse();
});

it('reads a logged resolution back as requirements', function () {
    Fixtures::seed();
    $order = Fixtures::ucasOrder();
    $requirements = app(RequirementResolver::class)->resolve($order);
    $log = OrderRequirement::query()->create(['order_id' => $order->id, 'resolved' => $requirements->toArray()]);

    expect($log->fresh()->resolvedRequirements())->toEqual($requirements);
});

it('refuses to render a version without content', function () {
    $order = Fixtures::order();
    $version = app(DocumentFactory::class)->createVersion($order, new DocumentModel('Statement of Purpose', null, 'Daniel Essel', []), Fixtures::template('plain'), new ResolvedRequirements);

    expect(fn () => app(DocumentRenderer::class)->render($version))->toThrow(RuntimeException::class, 'no content')
        ->and($version->fresh()->hasFiles())->toBeFalse();
});
