<?php

use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateResolver;
use Tests\Feature\Documents\Fixtures;

beforeEach(fn () => Fixtures::isolatePrivateDisk());
afterEach(fn () => Fixtures::removePrivateDisk());

it('falls back to the default template when nothing more specific matches', function () {
    Fixtures::seed();
    $template = app(TemplateResolver::class)->resolve(Fixtures::order(['country_code' => 'GB']), new ResolvedRequirements);

    expect($template->slug)->toBe('standard-a4')
        ->and($template->is_default)->toBeTrue();
});

it('picks the seeded template for the destination, document kind and platform', function (array $order, ?string $platform, string $expected) {
    Fixtures::seed();
    $template = app(TemplateResolver::class)->resolve(Fixtures::order($order), new ResolvedRequirements(applicationPlatform: $platform));

    expect($template->slug)->toBe($expected);
})->with([
    'US statement' => [['country_code' => 'US'], null, 'us-letter'],
    'Canadian statement' => [['country_code' => 'ca'], null, 'us-letter'],
    'German motivation letter' => [['country_code' => 'DE', 'service_snapshot' => ['document_kind' => 'motivation_letter']], null, 'motivation-letter-european'],
    'US cover letter' => [['country_code' => 'US', 'service_snapshot' => ['document_kind' => 'cover_letter']], null, 'motivation-letter-european'],
    'UCAS personal statement' => [['country_code' => 'GB', 'degree_level' => 'Undergraduate', 'service_snapshot' => ['document_kind' => 'personal_statement']], 'UCAS', 'ucas-personal-statement'],
    'UCAS mentioned for a master’s' => [['country_code' => 'GB', 'degree_level' => 'masters', 'service_snapshot' => ['document_kind' => 'personal_statement']], 'UCAS', 'standard-a4'],
    'UCAS but another kind' => [['country_code' => 'GB'], 'ucas', 'standard-a4'],
]);

it('prefers the most specific match, then priority', function () {
    $default = Fixtures::template('default', ['is_default' => true]);
    $country = Fixtures::template('country', ['match_rules' => ['countries' => ['gb']], 'priority' => 50]);
    $institution = Fixtures::template('institution', ['match_rules' => ['institutions' => ['the university of edinburgh'], 'countries' => ['GB']]]);
    Fixtures::template('inactive', ['match_rules' => ['institutions' => ['University of Edinburgh'], 'document_kinds' => ['statement_of_purpose']], 'is_active' => false]);
    Fixtures::template('other-institution', ['match_rules' => ['institutions' => ['University of Oxford']], 'priority' => 99]);

    $resolver = app(TemplateResolver::class);
    $edinburgh = Fixtures::order(['institution' => 'University of EDINBURGH']);

    expect($resolver->resolve($edinburgh, new ResolvedRequirements)->is($institution))->toBeTrue()
        ->and($resolver->resolve(Fixtures::order(['institution' => 'Imperial College London']), new ResolvedRequirements)->is($country))->toBeTrue()
        ->and($resolver->resolve(Fixtures::order(['country_code' => 'FR', 'institution' => 'Sciences Po']), new ResolvedRequirements)->is($default))->toBeTrue();

    $stronger = Fixtures::template('country-priority', ['match_rules' => ['countries' => ['GB']], 'priority' => 80]);
    expect($resolver->resolve(Fixtures::order(['institution' => 'Imperial College London']), new ResolvedRequirements)->is($stronger))->toBeTrue();
});

it('matches templates by service, including the service’s assigned template', function () {
    Fixtures::template('default', ['is_default' => true]);
    $assigned = Fixtures::template('assigned');
    $byService = Fixtures::template('by-service');
    Fixtures::template('kind', ['match_rules' => ['document_kinds' => ['statement_of_purpose']]]);

    $order = Fixtures::order(service: ['document_template_id' => $assigned->id]);
    $byServiceRule = Fixtures::order();
    $byService->update(['match_rules' => ['services' => [(string) $byServiceRule->service_id]]]);

    expect(app(TemplateResolver::class)->resolve($order, new ResolvedRequirements)->is($assigned))->toBeTrue()
        ->and(app(TemplateResolver::class)->resolve($byServiceRule, new ResolvedRequirements)->is($byService))->toBeTrue();
});

it('works without any template configured', function () {
    $template = app(TemplateResolver::class)->resolve(Fixtures::order(), new ResolvedRequirements);

    expect($template->exists)->toBeFalse()
        ->and($template->font_family)->toBe('Times New Roman')
        ->and($template->page_size)->toBe('A4');

    $version = Fixtures::rendered(Fixtures::order(), template: $template);
    expect($version->document_template_id)->toBeNull()
        ->and($version->template_snapshot['template_name'])->toBe('Standard application (A4)')
        ->and($version->hasFiles())->toBeTrue();
});
