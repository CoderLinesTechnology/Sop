<?php

use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Filament\Resources\AiModelPrices\AiModelPriceResource;
use App\Filament\Resources\AiModelPrices\Pages\ManageAiModelPrices;
use App\Filament\Resources\AiWorkflows\AiWorkflowResource;
use App\Filament\Resources\AiWorkflows\Pages\CreateAiWorkflow;
use App\Filament\Resources\AiWorkflows\Pages\EditAiWorkflow;
use App\Filament\Resources\AiWorkflows\Pages\ListAiWorkflows;
use App\Filament\Resources\PromptVersions\Pages\CreatePromptVersion;
use App\Filament\Resources\PromptVersions\Pages\EditPromptVersion;
use App\Filament\Resources\PromptVersions\Pages\ListPromptVersions;
use App\Filament\Resources\PromptVersions\Pages\ViewPromptVersion;
use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Filament\Support\Catalogue\PromptActivation;
use App\Models\AdminUser;
use App\Models\AiModelPrice;
use App\Models\AiWorkflow;
use App\Models\AuditLog;
use App\Models\PromptVersion;
use App\Models\Service;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function promptVersion(string $key, int $version, string $status, array $attributes = []): PromptVersion
{
    return PromptVersion::query()->create($attributes + [
        'prompt_key' => $key,
        'version' => $version,
        'status' => $status,
        'system_prompt' => "You are the {$key} stage. Version {$version}.",
        'user_template' => '{{applicant_profile}}',
    ]);
}

it('renders the AI screens for an AI admin', function () {
    actingAsAdmin(AdminRole::Ai);
    $workflow = AiWorkflow::query()->create(['name' => 'Standard', 'slug' => 'standard', 'is_default' => true, 'is_active' => true, 'config' => []]);
    $draft = promptVersion('writing', 1, PromptVersion::STATUS_DRAFT);
    $active = promptVersion('strategy', 1, PromptVersion::STATUS_ACTIVE);

    $this->get(AiWorkflowResource::getUrl('index'))->assertOk()->assertSee('Standard');
    $this->get(AiWorkflowResource::getUrl('create'))->assertOk();
    $this->get(AiWorkflowResource::getUrl('edit', ['record' => $workflow]))->assertOk()->assertSee('Deep research');
    $this->get(PromptVersionResource::getUrl('index'))->assertOk()->assertSee('writing');
    $this->get(PromptVersionResource::getUrl('create'))->assertOk();
    $this->get(PromptVersionResource::getUrl('view', ['record' => $active]))->assertOk();
    $this->get(PromptVersionResource::getUrl('edit', ['record' => $draft]))->assertOk();
    $this->get(AiModelPriceResource::getUrl('index'))->assertOk()->assertSee('pricing page');
});

it('creates prompt versions as drafts with the next version number, prefilled from the active one', function () {
    $admin = actingAsAdmin(AdminRole::Ai);
    $active = promptVersion('writing', 3, PromptVersion::STATUS_ACTIVE, ['model' => 'gpt-6-astra', 'reasoning_effort' => 'high']);

    Livewire::test(CreatePromptVersion::class, ['from' => (string) $active->id])
        ->assertSchemaStateSet(['prompt_key' => 'writing', 'system_prompt' => $active->system_prompt, 'model' => 'gpt-6-astra'])
        ->fillForm(['label' => 'Warmer openings', 'system_prompt' => 'You write warmer openings.', 'status' => 'active'])
        ->call('create')
        ->assertHasNoFormErrors();

    $draft = PromptVersion::query()->where('prompt_key', 'writing')->where('version', 4)->firstOrFail();
    expect($draft->status)->toBe(PromptVersion::STATUS_DRAFT)
        ->and($draft->created_by_admin_id)->toBe($admin->id)
        ->and($draft->label)->toBe('Warmer openings')
        ->and($active->refresh()->status)->toBe(PromptVersion::STATUS_ACTIVE);

    // A brand-new key starts at version 1 and is filled from nothing.
    Livewire::test(CreatePromptVersion::class)
        ->fillForm(['prompt_key' => 'cover_letter_writing', 'system_prompt' => 'Write cover letters.'])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(PromptVersion::query()->where('prompt_key', 'cover_letter_writing')->value('version'))->toBe(1);
});

it('allows editing drafts but never active or archived versions', function () {
    actingAsAdmin(AdminRole::Ai);
    $draft = promptVersion('analysis', 2, PromptVersion::STATUS_DRAFT);
    $active = promptVersion('analysis', 1, PromptVersion::STATUS_ACTIVE);
    $archived = promptVersion('analysis', 0, PromptVersion::STATUS_ARCHIVED);

    Livewire::test(EditPromptVersion::class, ['record' => $draft->getRouteKey()])
        ->fillForm(['system_prompt' => 'Analyse carefully.', 'prompt_key' => 'hijack'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($draft->refresh()->system_prompt)->toBe('Analyse carefully.')
        ->and($draft->prompt_key)->toBe('analysis')
        ->and(AuditLog::query()->where('action', 'prompt.updated')->exists())->toBeTrue();

    Livewire::test(EditPromptVersion::class, ['record' => $active->getRouteKey()])->assertForbidden();
    Livewire::test(EditPromptVersion::class, ['record' => $archived->getRouteKey()])->assertForbidden();

    Livewire::test(ListPromptVersions::class)
        ->assertActionHidden(TestAction::make('edit')->table($active))
        ->assertActionVisible(TestAction::make('edit')->table($draft))
        ->assertActionHidden(TestAction::make('delete')->table($active));
});

it('activates a prompt version: requires prompts.activate, keeps exactly one active and audits', function () {
    $ai = actingAsAdmin(AdminRole::Ai);
    $v1 = promptVersion('writing', 1, PromptVersion::STATUS_ACTIVE);
    $v2 = promptVersion('writing', 2, PromptVersion::STATUS_DRAFT);
    $other = promptVersion('strategy', 1, PromptVersion::STATUS_ACTIVE);

    Livewire::test(ViewPromptVersion::class, ['record' => $v2->getRouteKey()])
        ->assertActionVisible('activate')
        ->callAction('activate')
        ->assertHasNoActionErrors();

    expect($v2->refresh()->status)->toBe(PromptVersion::STATUS_ACTIVE)
        ->and($v2->activated_by_admin_id)->toBe($ai->id)
        ->and($v2->activated_at)->not->toBeNull()
        ->and($v1->refresh()->status)->toBe(PromptVersion::STATUS_ARCHIVED)
        ->and($other->refresh()->status)->toBe(PromptVersion::STATUS_ACTIVE)
        ->and(PromptVersion::query()->where('prompt_key', 'writing')->active()->count())->toBe(1);

    $audit = AuditLog::query()->where('action', 'prompt.activated')->sole();
    expect($audit->admin_user_id)->toBe($ai->id)
        ->and($audit->before['active_version'])->toBe(1)
        ->and($audit->after['active_version'])->toBe(2)
        ->and($audit->meta['prompt_key'])->toBe('writing');

    // Rolling back re-activates the archived version.
    Livewire::test(ListPromptVersions::class)
        ->callAction(TestAction::make('activate')->table($v1));
    expect($v1->refresh()->status)->toBe(PromptVersion::STATUS_ACTIVE)
        ->and($v2->refresh()->status)->toBe(PromptVersion::STATUS_ARCHIVED)
        ->and(PromptVersion::query()->where('prompt_key', 'writing')->active()->count())->toBe(1);
});

it('does not let administrators without prompts.activate activate prompts', function () {
    (new RolesAndPermissionsSeeder)->run();
    Role::findByName(AdminRole::Ai->value, 'admin')->revokePermissionTo(Permission::PromptsActivate);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = AdminUser::factory()->create();
    $admin->assignRole(AdminRole::Ai->value);
    $this->actingAs($admin, 'admin');

    $draft = promptVersion('writing', 2, PromptVersion::STATUS_DRAFT);
    promptVersion('writing', 1, PromptVersion::STATUS_ACTIVE);

    Livewire::test(ViewPromptVersion::class, ['record' => $draft->getRouteKey()])
        ->assertActionHidden('activate');

    // Even a hand-crafted Livewire request cannot run the hidden action.
    Livewire::test(ViewPromptVersion::class, ['record' => $draft->getRouteKey()])
        ->call('mountAction', 'activate')
        ->call('callMountedAction');

    expect($draft->refresh()->status)->toBe(PromptVersion::STATUS_DRAFT)
        ->and(AuditLog::query()->where('action', 'prompt.activated')->exists())->toBeFalse();

    expect(fn () => PromptActivation::activate($draft, $admin))->toThrow(LogicException::class);
    expect($draft->refresh()->status)->toBe(PromptVersion::STATUS_DRAFT);
});

it('compares a draft with the active version', function () {
    actingAsAdmin(AdminRole::Ai);
    promptVersion('writing', 1, PromptVersion::STATUS_ACTIVE, ['system_prompt' => "Line one\nLine two"]);
    $draft = promptVersion('writing', 2, PromptVersion::STATUS_DRAFT, ['system_prompt' => "Line one\nLine three"]);

    Livewire::test(ViewPromptVersion::class, ['record' => $draft->getRouteKey()])
        ->mountAction('compare')
        ->assertMountedActionModalSee(['Active · v1', 'Draft · v2', '+ Line three', '- Line two']);
});

it('creates workflows from the defaults, keeps one default and versions config changes', function () {
    $admin = actingAsAdmin(AdminRole::Ai);
    $old = AiWorkflow::query()->create(['name' => 'Old default', 'slug' => 'old-default', 'is_default' => true, 'is_active' => true, 'config' => []]);

    Livewire::test(CreateAiWorkflow::class)
        ->assertSchemaStateSet(['config.quality.threshold' => 8.0, 'config.stages.writing.enabled' => true])
        ->fillForm([
            'name' => 'Premium',
            'slug' => 'premium',
            'is_default' => true,
            'config.quality.threshold' => 8.5,
            'config.stages.research.enabled' => false,
            'config.stages.writing.enabled' => false,
            'config.on_budget_exceeded' => 'manual_review',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $premium = AiWorkflow::query()->where('slug', 'premium')->firstOrFail();
    expect($premium->is_default)->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse()
        ->and($premium->effectiveConfig()['quality']['threshold'])->toEqual(8.5)
        ->and($premium->effectiveConfig()['stages']['research']['enabled'])->toBeFalse()
        ->and($premium->effectiveConfig()['stages']['writing']['enabled'])->toBeTrue() // required stage
        ->and($premium->effectiveConfig()['on_budget_exceeded'])->toBe('manual_review')
        ->and($premium->version)->toBe(1)
        ->and(AuditLog::query()->where('action', 'ai_workflow.created')->exists())->toBeTrue();

    // Saving without a configuration change keeps the version.
    Livewire::test(EditAiWorkflow::class, ['record' => $premium->getRouteKey()])
        ->fillForm(['description' => 'Our most thorough workflow.'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($premium->refresh()->version)->toBe(1);

    Livewire::test(EditAiWorkflow::class, ['record' => $premium->getRouteKey()])
        ->fillForm(['config.limits.max_cost_usd' => 6.5, 'config.stages.writing.model' => 'gpt-6-astra-pro'])
        ->call('save')
        ->assertHasNoFormErrors();

    $premium->refresh();
    expect($premium->version)->toBe(2)
        ->and($premium->effectiveConfig()['limits']['max_cost_usd'])->toEqual(6.5)
        ->and($premium->effectiveConfig()['stages']['writing']['model'])->toBe('gpt-6-astra-pro');

    $audit = AuditLog::query()->where('action', 'ai_workflow.updated')->latest('id')->first();
    expect($audit->admin_user_id)->toBe($admin->id)
        ->and($audit->after['config.limits.max_cost_usd'])->toEqual(6.5)
        ->and($audit->before['config.limits.max_cost_usd'])->toEqual(4.0);

    // The default workflow cannot be deactivated or un-defaulted.
    Livewire::test(EditAiWorkflow::class, ['record' => $premium->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);
});

it('manages model prices with an audit trail', function () {
    actingAsAdmin(AdminRole::Ai);

    Livewire::test(ManageAiModelPrices::class)
        ->callAction('create', ['model' => 'gpt-6.1-sol', 'input_per_million' => 1.25, 'cached_input_per_million' => 0.125, 'output_per_million' => 10, 'web_search_per_call' => 0.01, 'is_active' => true])
        ->assertHasNoActionErrors();

    $price = AiModelPrice::query()->where('model', 'gpt-6.1-sol')->firstOrFail();
    expect((float) $price->output_per_million)->toBe(10.0);

    Livewire::test(ManageAiModelPrices::class)
        ->callAction(TestAction::make('edit')->table($price), ['output_per_million' => 12])
        ->assertHasNoActionErrors();

    expect((float) $price->refresh()->output_per_million)->toBe(12.0)
        ->and(AuditLog::query()->where('action', 'ai_model_price.updated')->exists())->toBeTrue();
});

it('keeps non-AI administrators out of AI configuration', function (AdminRole $role) {
    actingAsAdmin($role);
    $this->withoutVite();

    $this->get(AiWorkflowResource::getUrl('index'))->assertForbidden();
    $this->get(PromptVersionResource::getUrl('index'))->assertForbidden();
    $this->get(AiModelPriceResource::getUrl('index'))->assertForbidden();
})->with([AdminRole::Content, AdminRole::Finance, AdminRole::Operations]);

it('duplicates workflows as inactive copies and only deletes unused ones', function () {
    actingAsAdmin(AdminRole::Ai);
    $default = AiWorkflow::query()->create(['name' => 'Standard', 'slug' => 'standard', 'is_default' => true, 'is_active' => true, 'config' => []]);
    $used = AiWorkflow::query()->create(['name' => 'Premium', 'slug' => 'premium', 'is_default' => false, 'is_active' => true, 'config' => ['quality' => ['threshold' => 9]]]);
    $unused = AiWorkflow::query()->create(['name' => 'Experiment', 'slug' => 'experiment', 'is_default' => false, 'is_active' => true, 'config' => []]);
    Service::factory()->create(['ai_workflow_id' => $used->id]);

    Livewire::test(ListAiWorkflows::class)
        ->assertActionHidden(TestAction::make('delete')->table($default))
        ->assertActionHidden(TestAction::make('delete')->table($used))
        ->callAction(TestAction::make('duplicate')->table($used))
        ->callAction(TestAction::make('delete')->table($unused));

    $copy = AiWorkflow::query()->where('slug', 'premium-copy')->firstOrFail();
    expect($copy->is_active)->toBeFalse()
        ->and($copy->is_default)->toBeFalse()
        ->and($copy->effectiveConfig()['quality']['threshold'])->toEqual(9)
        ->and(AiWorkflow::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'ai_workflow.deleted')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'ai_workflow.duplicated')->exists())->toBeTrue();
});
