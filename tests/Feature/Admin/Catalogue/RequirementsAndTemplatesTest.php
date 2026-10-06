<?php

use App\Enums\AdminRole;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Resources\RequirementRules\Pages\CreateRequirementRule;
use App\Filament\Resources\RequirementRules\Pages\EditRequirementRule;
use App\Filament\Resources\RequirementRules\Pages\ListRequirementRules;
use App\Filament\Resources\RequirementRules\RequirementRuleResource;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\RequirementRule;
use App\Models\Service;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders requirement and template screens for an AI admin', function () {
    actingAsAdmin(AdminRole::Ai);
    $rule = RequirementRule::query()->create(['name' => 'UCAS', 'scope' => 'platform', 'application_platform' => 'UCAS', 'max_characters' => 4000, 'is_active' => true]);
    $template = DocumentTemplate::query()->create(['name' => 'Standard A4', 'slug' => 'standard-a4', 'is_default' => true, 'match_rules' => ['countries' => ['GB']]]);

    $this->get(RequirementRuleResource::getUrl('index'))->assertOk()->assertSee('UCAS');
    $this->get(RequirementRuleResource::getUrl('create'))->assertOk();
    $this->get(RequirementRuleResource::getUrl('edit', ['record' => $rule]))->assertOk();
    $this->get(DocumentTemplateResource::getUrl('index'))->assertOk()->assertSee('Standard A4');
    $this->get(DocumentTemplateResource::getUrl('create'))->assertOk();
    $this->get(DocumentTemplateResource::getUrl('edit', ['record' => $template]))->assertOk();
});

it('creates requirement rules with sections, audits changes and marks them verified', function () {
    $admin = actingAsAdmin(AdminRole::Ai);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateRequirementRule::class)
        ->fillForm([
            'name' => 'Edinburgh MSc Data Science',
            'scope' => 'programme',
            'institution_name' => 'University of Edinburgh',
            'institution_domain' => 'https://www.ed.ac.uk/',
            'programme_name' => 'MSc Data Science',
            'country_code' => 'GB',
            'document_kinds' => ['personal_statement', 'statement_of_purpose'],
            'max_words' => 500,
            'min_words' => 300,
            'language_variant' => 'en-GB',
            'required_sections' => [
                ['heading' => 'Why this programme?', 'question' => 'Explain your motivation.', 'max_characters' => 2000],
                ['heading' => 'Your experience', 'max_words' => 250],
            ],
            'prohibited_content' => ['Quotations', 'Grades already in the transcript'],
            'source_name' => 'Programme page',
            'source_url' => 'https://www.ed.ac.uk/studying/postgraduate/degrees',
            'priority' => 0,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    $undoRepeaterFake();

    $rule = RequirementRule::query()->where('name', 'Edinburgh MSc Data Science')->firstOrFail();
    expect($rule->institution_domain)->toBe('ed.ac.uk')
        ->and($rule->document_kinds)->toBe(['personal_statement', 'statement_of_purpose'])
        ->and($rule->required_sections)->toHaveCount(2)
        ->and($rule->required_sections[1])->toEqual(['heading' => 'Your experience', 'max_words' => 250])
        ->and($rule->prohibited_content)->toBe(['Quotations', 'Grades already in the transcript'])
        ->and(AuditLog::query()->where('action', 'requirement_rule.created')->exists())->toBeTrue();

    Livewire::test(EditRequirementRule::class, ['record' => $rule->getRouteKey()])
        ->fillForm(['max_words' => 200])
        ->call('save')
        ->assertHasFormErrors(['max_words']);

    Livewire::test(EditRequirementRule::class, ['record' => $rule->getRouteKey()])
        ->fillForm(['max_words' => 650])
        ->call('save')
        ->assertHasNoFormErrors();
    $audit = AuditLog::query()->where('action', 'requirement_rule.updated')->sole();
    expect($audit->before['max_words'])->toBe(500)->and($audit->after['max_words'])->toBe(650);

    Livewire::test(ListRequirementRules::class)
        ->callAction(TestAction::make('markVerified')->table($rule));

    $rule->refresh();
    expect($rule->last_verified_at)->not->toBeNull()
        ->and($rule->verified_by_admin_id)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', 'requirement_rule.verified')->exists())->toBeTrue();
});

it('requires the identifying field of each rule scope', function () {
    actingAsAdmin(AdminRole::Ai);

    Livewire::test(CreateRequirementRule::class)
        ->fillForm(['name' => 'UK convention', 'scope' => 'country', 'country_code' => null, 'priority' => 0])
        ->call('create')
        ->assertHasFormErrors(['country_code' => 'required']);

    Livewire::test(CreateRequirementRule::class)
        ->fillForm(['name' => 'UCAS', 'scope' => 'platform', 'application_platform' => null, 'priority' => 0])
        ->call('create')
        ->assertHasFormErrors(['application_platform' => 'required']);
});

it('keeps a single default document template and normalises match rules', function () {
    actingAsAdmin(AdminRole::Ai);
    $old = DocumentTemplate::query()->create(['name' => 'Old', 'slug' => 'old', 'is_default' => true]);
    $service = Service::factory()->create();

    Livewire::test(CreateDocumentTemplate::class)
        ->fillForm([
            'name' => 'UK letters',
            'slug' => 'uk-letters',
            'is_default' => true,
            'priority' => 5,
            'match_rules.services' => [(string) $service->id],
            'match_rules.countries' => ['GB', 'IE'],
            'match_rules.document_kinds' => ['motivation_letter'],
            'match_rules.institutions' => [],
            'font_family' => 'Arial',
            'font_size' => 11,
            'line_spacing' => 1.15,
            'page_numbers' => 'bottom_right',
            'applicant_name_position' => 'footer',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = DocumentTemplate::query()->where('slug', 'uk-letters')->firstOrFail();
    expect($template->is_default)->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse()
        ->and($template->match_rules)->toEqual(['services' => [$service->id], 'countries' => ['GB', 'IE'], 'document_kinds' => ['motivation_letter']])
        ->and($template->font_size)->toBe(11.0)
        ->and(AuditLog::query()->where('action', 'document_template.created')->exists())->toBeTrue();

    Livewire::test(EditDocumentTemplate::class, ['record' => $template->getRouteKey()])
        ->assertFormFieldDisabled('is_default')
        ->fillForm(['font_size' => 30])
        ->call('save')
        ->assertHasFormErrors(['font_size']);

    Livewire::test(EditDocumentTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['margin_left_mm' => 30, 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $template->refresh();
    expect($template->margin_left_mm)->toBe(30.0)
        ->and($template->is_active)->toBeTrue() // the default template stays active
        ->and(AuditLog::query()->where('action', 'document_template.updated')->exists())->toBeTrue();

    Livewire::test(EditDocumentTemplate::class, ['record' => $template->getRouteKey()])
        ->assertActionHidden('delete');
});

it('keeps content and finance admins out of requirements and templates', function (AdminRole $role) {
    actingAsAdmin($role);
    $this->withoutVite();

    $this->get(RequirementRuleResource::getUrl('index'))->assertForbidden();
    $this->get(DocumentTemplateResource::getUrl('index'))->assertForbidden();
})->with([AdminRole::Content, AdminRole::Finance]);
