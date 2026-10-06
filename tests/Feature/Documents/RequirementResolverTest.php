<?php

use App\Domain\Documents\RequirementResolver;
use App\Models\RequirementRule;
use Tests\Feature\Documents\Fixtures;

beforeEach(fn () => Fixtures::isolatePrivateDisk());
afterEach(fn () => Fixtures::removePrivateDisk());

it('never invents requirements when nothing is known', function () {
    $requirements = Fixtures::resolve(['country_code' => null, 'institution' => null, 'programme' => null, 'degree_level' => null]);

    expect($requirements->minWords)->toBeNull()
        ->and($requirements->maxWords)->toBeNull()
        ->and($requirements->maxCharacters)->toBeNull()
        ->and($requirements->maxPages)->toBeNull()
        ->and($requirements->pageSize)->toBeNull()
        ->and($requirements->fontFamily)->toBeNull()
        ->and($requirements->fontSize)->toBeNull()
        ->and($requirements->marginsMm)->toBeNull()
        ->and($requirements->lineSpacing)->toBeNull()
        ->and($requirements->requiredSections)->toBe([])
        ->and($requirements->applicationPlatform)->toBeNull()
        ->and($requirements->languageVariant)->toBe('en-GB')
        ->and($requirements->dateFormat)->toBe('j F Y')
        ->and($requirements->targetWords)->toBe(900) // the service's default length
        ->and($requirements->conflicts)->toBe([])
        ->and($requirements->appliedRuleIds)->toBe([])
        ->and(array_column($requirements->sources, 'type'))->toBe(['service_default'])
        ->and($requirements->limitsSummary())->toContain('no official limit found');
});

it('falls back to the document-kind default length without a service default', function () {
    $requirements = Fixtures::resolve(
        ['country_code' => null, 'service_snapshot' => ['document_kind' => 'cover_letter', 'default_word_limit' => null]],
        service: ['default_word_limit' => null],
    );

    expect($requirements->targetWords)->toBe(RequirementResolver::KIND_DEFAULT_WORDS['cover_letter'])
        ->and($requirements->maxWords)->toBeNull();
});

it('lets the customer’s official instructions win and records the conflict', function () {
    Fixtures::seed();
    $requirements = Fixtures::resolve(
        researched: [Fixtures::finding('max_words', 1000)],
        customer: ['word_limit' => '500 words'],
    );

    $conflict = collect($requirements->conflicts)->firstWhere('field', 'max_words');

    expect($requirements->maxWords)->toBe(500)
        ->and($requirements->targetWords)->toBe(465) // ≈93% of the limit
        ->and($conflict['chosen'])->toBe(500)
        ->and($conflict['flagged'])->toBeFalse()
        ->and($conflict['reason'])->toContain('customer-provided official instructions')
        ->and(collect($conflict['candidates'])->pluck('value')->all())->toBe([500, 1000])
        ->and(array_column($requirements->sources, 'type'))->toContain('customer', 'official_programme');
});

it('treats the order’s stated word limit as the customer’s instruction', function () {
    $requirements = Fixtures::resolve(['word_limit' => 700]);

    expect($requirements->maxWords)->toBe(700)
        ->and($requirements->targetWords)->toBe(650);
});

it('prefers the official institution over the application platform over country guidance', function () {
    Fixtures::seed();
    $requirements = Fixtures::resolve(
        ['country_code' => 'US', 'degree_level' => 'undergraduate', 'institution' => 'Example College', 'programme' => 'BA Economics',
            'essay_prompt' => 'Common App prompt 2: The lessons we take from obstacles...', 'service_snapshot' => ['document_kind' => 'personal_statement']],
        researched: [
            Fixtures::finding('max_words', 500, 'official_admissions', url: 'https://admissions.example.edu/apply'),
            Fixtures::finding('language_variant', 'British English', 'government', url: 'https://www.gov.example/guidance'),
        ],
    );

    $words = collect($requirements->conflicts)->firstWhere('field', 'max_words');
    $language = collect($requirements->conflicts)->firstWhere('field', 'language_variant');

    expect($requirements->applicationPlatform)->toBe('Common App')
        ->and($requirements->maxWords)->toBe(500)
        ->and($requirements->minWords)->toBe(250) // the platform's minimum still applies
        ->and($words['reason'])->toContain('official institution guidance')->toContain('application-platform guidance')
        ->and($requirements->languageVariant)->toBe('en-US') // platform rule outranks government guidance
        ->and($language['reason'])->toContain('application-platform guidance')->toContain('country guidance');
});

it('keeps the strictest limit and flags truly ambiguous sources', function () {
    $requirements = Fixtures::resolve(researched: [
        Fixtures::finding('max_words', 800, 'official_university', url: 'https://www.ed.ac.uk/studying/postgraduate'),
        Fixtures::finding('max_words', 750, 'official_department', url: 'https://www.inf.ed.ac.uk/admissions'),
        Fixtures::finding('min_words', 300, 'official_university', url: 'https://www.ed.ac.uk/studying/postgraduate'),
        Fixtures::finding('min_words', 400, 'official_department', url: 'https://www.inf.ed.ac.uk/admissions'),
    ]);

    $max = collect($requirements->conflicts)->firstWhere('field', 'max_words');

    expect($requirements->maxWords)->toBe(750)
        ->and($requirements->minWords)->toBe(400)
        ->and($max['flagged'])->toBeTrue()
        ->and($max['chosen'])->toBe(750)
        ->and($max['reason'])->toContain('equal authority')->toContain('strictest');
});

it('ignores unverified and secondary findings', function () {
    $requirements = Fixtures::resolve(researched: [
        Fixtures::finding('max_words', 300, verified: false),
        Fixtures::finding('max_words', 200, 'secondary', url: 'https://blog.example.com/edinburgh-tips'),
        Fixtures::finding('font_family', 'Arial', 'secondary'),
    ]);

    expect($requirements->maxWords)->toBeNull()
        ->and($requirements->fontFamily)->toBeNull()
        ->and($requirements->sources)->each(fn ($source) => $source->type->not->toBe('secondary'));
});

it('applies admin rules by scope: programme over institution over country', function () {
    $country = RequirementRule::query()->create(['name' => 'UK guidance', 'scope' => 'country', 'country_code' => 'GB', 'max_words' => 1200, 'language_variant' => 'en-GB', 'source_name' => 'Guide']);
    $institution = RequirementRule::query()->create(['name' => 'Edinburgh statements', 'scope' => 'institution', 'country_code' => 'GB', 'institution_name' => 'The University of Edinburgh', 'max_words' => 1000, 'max_pages' => 2, 'source_url' => 'https://www.ed.ac.uk/']);
    $programme = RequirementRule::query()->create(['name' => 'Edinburgh MSc CS', 'scope' => 'programme', 'institution_name' => 'university of edinburgh', 'programme_name' => 'Computer Science', 'max_words' => 800]);
    $otherInstitution = RequirementRule::query()->create(['name' => 'Oxford', 'scope' => 'institution', 'institution_name' => 'University of Oxford', 'max_words' => 300]);
    $inactive = RequirementRule::query()->create(['name' => 'Old rule', 'scope' => 'programme', 'programme_name' => 'Computer Science', 'max_words' => 100, 'is_active' => false]);
    $otherKind = RequirementRule::query()->create(['name' => 'Letters only', 'scope' => 'institution', 'institution_name' => 'University of Edinburgh', 'document_kinds' => ['motivation_letter'], 'max_words' => 200]);

    $requirements = Fixtures::resolve();
    $conflict = collect($requirements->conflicts)->firstWhere('field', 'max_words');

    expect($requirements->maxWords)->toBe(800)
        ->and($requirements->maxPages)->toBe(2)
        ->and($requirements->appliedRuleIds)->toEqualCanonicalizing([$country->id, $institution->id, $programme->id])
        ->and($requirements->appliedRuleIds)->not->toContain($otherInstitution->id, $inactive->id, $otherKind->id)
        ->and($conflict['chosen'])->toBe(800)
        ->and(collect($conflict['candidates'])->pluck('value')->all())->toBe([800, 1000, 1200]);
});

it('matches an institution rule by the official domain found in research', function () {
    $rule = RequirementRule::query()->create(['name' => 'UoE', 'scope' => 'institution', 'institution_domain' => 'ed.ac.uk', 'max_words' => 950]);

    $matched = Fixtures::resolve(['institution' => 'Edinburgh Uni'], researched: [Fixtures::finding('submission_method', 'Online portal', 'official_admissions', url: 'https://www.ed.ac.uk/studying/apply')]);
    $unmatched = Fixtures::resolve(['institution' => 'Edinburgh Uni']);

    expect($matched->appliedRuleIds)->toBe([$rule->id])
        ->and($matched->maxWords)->toBe(950)
        ->and($unmatched->appliedRuleIds)->toBe([]);
});

it('follows country conventions for language, dates and paper', function (string $country, string $variant, string $dateFormat, string $paper) {
    $requirements = Fixtures::resolve(['country_code' => $country]);

    expect($requirements->languageVariant)->toBe($variant)
        ->and($requirements->dateFormat)->toBe($dateFormat)
        ->and($requirements->pageSize)->toBe($paper);
})->with([
    'United Kingdom' => ['GB', 'en-GB', 'j F Y', 'A4'],
    'Ireland' => ['IE', 'en-IE', 'j F Y', 'A4'],
    'Nigeria' => ['NG', 'en-GB', 'j F Y', 'A4'],
    'Germany' => ['DE', 'en-GB', 'j F Y', 'A4'],
    'United States' => ['US', 'en-US', 'F j, Y', 'Letter'],
    'Canada' => ['CA', 'en-CA', 'F j, Y', 'Letter'],
]);

it('agrees with the seeded country rules without conflicts', function () {
    Fixtures::seed();
    $requirements = Fixtures::resolve(['country_code' => 'KE']);
    $rule = RequirementRule::query()->where('country_code', 'KE')->where('scope', 'country')->first();

    expect($requirements->languageVariant)->toBe('en-GB')
        ->and($requirements->pageSize)->toBe('A4')
        ->and($requirements->appliedRuleIds)->toBe([$rule->id])
        ->and($requirements->conflicts)->toBe([]);
});

it('resolves the UCAS three-question format for UK undergraduate personal statements', function () {
    Fixtures::seed();
    $requirements = Fixtures::resolve([
        'degree_level' => 'undergraduate', 'programme' => 'Computer Science BSc', 'institution' => 'University of Leeds',
        'service_snapshot' => ['document_kind' => 'personal_statement', 'default_word_limit' => 650],
    ]);
    $ucas = RequirementRule::query()->where('application_platform', 'UCAS')->first();

    expect($requirements->applicationPlatform)->toBe('UCAS')
        ->and($requirements->maxCharacters)->toBe(4000)
        ->and($requirements->maxWords)->toBeNull()
        ->and($requirements->requiredSections)->toHaveCount(3)
        ->and($requirements->requiredSections[0])->toMatchArray([
            'heading' => 'Why do you want to study this course or subject?',
            'min_characters' => 350,
        ])
        ->and($requirements->limitsIncludeHeadings)->toBeFalse()
        ->and($requirements->targetWords)->toBe(600)
        ->and($requirements->appliedRuleIds)->toContain($ucas->id)
        ->and(collect($requirements->sources)->firstWhere('url', 'https://www.ucas.com/'))->not->toBeNull()
        ->and($requirements->limitsSummary())->toContain('4000 characters')->toContain('not counted');
});

it('does not assume the Common App without evidence', function () {
    Fixtures::seed();
    $order = ['country_code' => 'US', 'degree_level' => 'undergraduate', 'service_snapshot' => ['document_kind' => 'personal_statement']];

    $assumed = Fixtures::resolve($order);
    $stated = Fixtures::resolve($order, customer: ['application_platform' => 'Common Application']);

    expect($assumed->applicationPlatform)->toBeNull()
        ->and($assumed->maxWords)->toBeNull()
        ->and($stated->applicationPlatform)->toBe('Common Application')
        ->and($stated->maxWords)->toBe(650)
        ->and($stated->minWords)->toBe(250)
        ->and($stated->targetWords)->toBe(605);
});

it('drops a lower-authority minimum that exceeds the binding maximum', function () {
    Fixtures::seed();
    $requirements = Fixtures::resolve(
        ['country_code' => 'US', 'degree_level' => 'undergraduate', 'essay_prompt' => 'Common App personal essay', 'service_snapshot' => ['document_kind' => 'personal_statement']],
        customer: ['max_words' => 200],
    );

    expect($requirements->maxWords)->toBe(200)
        ->and($requirements->minWords)->toBeNull()
        ->and(collect($requirements->conflicts)->firstWhere('field', 'min_words')['reason'])->toContain('was dropped');
});

it('converts researched formatting values and ignores unusable ones', function () {
    $requirements = Fixtures::resolve(researched: [
        Fixtures::finding('font', 'Arial'),
        Fixtures::finding('font_size', '11pt'),
        Fixtures::finding('margins', '2 cm'),
        Fixtures::finding('line_spacing', 'double spacing'),
        Fixtures::finding('page_limit', '2 pages'),
        Fixtures::finding('character_limit', '4,000 characters'),
        Fixtures::finding('file_types', ['.PDF', 'docx', 'bad type!']),
        Fixtures::finding('required_sections', ['Research interests', 'Career goals']),
        Fixtures::finding('prohibited_content', 'Do not include photographs'),
        Fixtures::finding('max_words', 'about a page'),
    ]);

    expect($requirements->fontFamily)->toBe('Arial')
        ->and($requirements->fontSize)->toBe(11.0)
        ->and($requirements->marginsMm)->toBe(20.0)
        ->and($requirements->lineSpacing)->toBe(2.0)
        ->and($requirements->maxPages)->toBe(2)
        ->and($requirements->maxCharacters)->toBe(4000)
        ->and($requirements->fileTypes)->toBe(['pdf', 'docx'])
        ->and($requirements->requiredSections)->toBe([['heading' => 'Research interests'], ['heading' => 'Career goals']])
        ->and($requirements->limitsIncludeHeadings)->toBeTrue()
        ->and($requirements->prohibitedContent)->toBe(['Do not include photographs'])
        ->and($requirements->maxWords)->toBeNull();
});
