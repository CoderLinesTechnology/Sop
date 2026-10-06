<?php

use App\Enums\AdminRole;
use App\Enums\FieldMapping;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\AuditLog;
use App\Models\Service;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the service list and edit pages', function () {
    actingAsAdmin();
    $service = Service::factory()->withStandardFields()->create();

    $this->get(ServiceResource::getUrl('index'))->assertOk()->assertSee($service->name);
    $this->get(ServiceResource::getUrl('create'))->assertOk();
    $this->get(ServiceResource::getUrl('edit', ['record' => $service]))->assertOk()->assertSee('Order form');
});

function cvServiceFormData(array $overrides = []): array
{
    return array_replace([
        'name' => 'CV Optimization',
        'slug' => 'cv-optimization',
        'document_kind' => 'custom',
        'short_description' => 'A sharper, achievement-focused CV tailored to the roles you want.',
        'description' => "We rewrite your CV around **evidence** of impact.",
        'card_features' => [['feature' => 'ATS-friendly'], ['feature' => 'Achievement focused']],
        'badge' => 'New',
        'is_active' => true,
        'is_featured' => false,
        'icon' => 'briefcase',
        'icon_color' => 'blue',
        'display_order' => 9,
        'currency' => 'USD',
        'price' => 49.5,
        'compare_at_price' => 79,
        'promo_label' => 'Launch offer',
        'revisions_included' => 2,
        'revision_window_days' => 30,
        'revision_fee' => 12,
        'revision_mode' => 'ai',
        'writing_guidance' => 'Rewrite the CV with quantified achievements.',
        'fields' => [
            ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'section' => 'details', 'requirement' => 'required', 'width' => 'half', 'maps_to' => FieldMapping::CustomerName->value, 'is_active' => true],
            ['key' => 'email', 'label' => 'Email address', 'type' => 'email', 'section' => 'details', 'requirement' => 'required', 'width' => 'half', 'maps_to' => FieldMapping::Email->value, 'is_active' => true],
            ['key' => 'target_role', 'label' => 'Target role', 'type' => 'select', 'section' => 'application', 'requirement' => 'recommended', 'width' => 'full', 'maps_to' => null, 'is_active' => true,
                'options' => ['choices' => [['value' => 'engineering', 'label' => 'Engineering'], ['value' => 'finance', 'label' => 'Finance']]]],
            ['key' => 'current_cv', 'label' => 'Current CV', 'type' => 'file', 'section' => 'application', 'requirement' => 'optional', 'width' => 'full', 'maps_to' => null, 'is_active' => true,
                'options' => ['accept' => ['pdf', 'docx'], 'max_files' => 2, 'purpose' => 'cv']],
            ['key' => 'experience', 'label' => 'Your experience', 'type' => 'textarea', 'section' => 'story', 'requirement' => 'required', 'width' => 'full', 'maps_to' => null, 'is_active' => true,
                'optional_when_upload' => 'current_cv', 'validation' => ['max_length' => 3000], 'show_when' => ['field' => 'target_role', 'equals' => 'engineering']],
        ],
    ], $overrides);
}

it('creates a brand-new service with its order form and makes it orderable', function () {
    $admin = actingAsAdmin();
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateService::class)
        ->fillForm(cvServiceFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $service = Service::query()->active()->where('slug', 'cv-optimization')->firstOrFail();

    expect($service->price)->toBe(4950)
        ->and($service->compare_at_price)->toBe(7900)
        ->and($service->revision_fee)->toBe(1200)
        ->and($service->card_features)->toBe(['ATS-friendly', 'Achievement focused'])
        ->and($service->fields()->pluck('key')->all())->toBe(['full_name', 'email', 'target_role', 'current_cv', 'experience']);

    $select = $service->fields()->where('key', 'target_role')->first();
    expect($select->choices())->toBe(['engineering' => 'Engineering', 'finance' => 'Finance'])
        ->and($select->validation)->toBeNull();

    $upload = $service->fields()->where('key', 'current_cv')->first();
    expect($upload->options)->toEqual(['accept' => ['pdf', 'docx'], 'max_files' => 2, 'purpose' => 'cv'])
        ->and($upload->optional_when_upload)->toBeNull();

    $experience = $service->fields()->where('key', 'experience')->first();
    expect($experience->optional_when_upload)->toBe('current_cv')
        ->and($experience->show_when)->toEqual(['field' => 'target_role', 'equals' => 'engineering'])
        ->and($experience->validation)->toBe(['max_length' => 3000])
        ->and($experience->options)->toBeNull();

    $audit = AuditLog::query()->where('action', 'service.created')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->admin_user_id)->toBe($admin->id)
        ->and($audit->after['price'])->toBe(4950);
});

it('lets a content admin edit copy but never prices', function () {
    actingAsAdmin(AdminRole::Content);
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);

    Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
        ->assertFormFieldDisabled('price')
        ->assertFormFieldDisabled('compare_at_price')
        ->assertFormFieldDisabled('currency')
        ->assertFormFieldDisabled('revision_fee')
        ->assertFormFieldEnabled('name')
        ->fillForm(['name' => 'Personal Statement Plus', 'price' => 1, 'compare_at_price' => 2, 'currency' => 'NGN'])
        ->call('save')
        ->assertHasNoFormErrors();

    $service->refresh();
    expect($service->name)->toBe('Personal Statement Plus')
        ->and($service->price)->toBe(8900)
        ->and($service->currency)->toBe('USD');

    $this->withoutVite()->get(ServiceResource::getUrl('create'))->assertForbidden();
});

it('lets a finance admin change prices, audited with before and after', function () {
    $finance = actingAsAdmin(AdminRole::Finance);
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'compare_at_price' => 12000, 'currency' => 'USD']);

    Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
        ->assertFormFieldEnabled('price')
        ->assertFormFieldDisabled('name')
        ->fillForm(['price' => 95.5, 'compare_at_price' => 130, 'name' => 'Hijacked name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $service->refresh();
    expect($service->price)->toBe(9550)
        ->and($service->compare_at_price)->toBe(13000)
        ->and($service->name)->not->toBe('Hijacked name');

    $audit = AuditLog::query()->where('action', 'service.updated')->latest('id')->first();
    expect($audit->admin_user_id)->toBe($finance->id)
        ->and($audit->before)->toMatchArray(['price' => 8900, 'compare_at_price' => 12000])
        ->and($audit->after)->toMatchArray(['price' => 9550, 'compare_at_price' => 13000])
        ->and($audit->meta['price_changed'])->toBeTrue();
});

it('audits activation changes and validates the original price', function () {
    actingAsAdmin();
    $service = Service::factory()->withStandardFields()->create(['is_active' => true, 'price' => 8900]);

    Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['compare_at_price' => 50])
        ->call('save')
        ->assertHasFormErrors(['compare_at_price']);

    Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['is_active' => false, 'compare_at_price' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Service::query()->active()->whereKey($service->id)->exists())->toBeFalse();
    $audit = AuditLog::query()->where('action', 'service.updated')->latest('id')->first();
    expect($audit->before['is_active'])->toBeTrue()
        ->and($audit->after['is_active'])->toBeFalse();
});

it('keeps slugs unique among live services and explains archived conflicts', function () {
    actingAsAdmin();
    Service::factory()->create(['slug' => 'taken-slug']);
    $archived = Service::factory()->create(['slug' => 'archived-slug', 'name' => 'Old Essay Service']);
    $archived->delete();

    Livewire::test(CreateService::class)
        ->fillForm(cvServiceFormData(['slug' => 'taken-slug']))
        ->call('create')
        ->assertHasFormErrors(['slug' => 'Another active service already uses this slug.']);

    Livewire::test(CreateService::class)
        ->fillForm(cvServiceFormData(['slug' => 'archived-slug']))
        ->call('create')
        ->assertHasFormErrors(['slug' => 'This slug belongs to the archived service “Old Essay Service”. Restore that service, permanently delete it (only possible if it has no orders), or choose another slug.']);

    Livewire::test(CreateService::class)
        ->fillForm(cvServiceFormData(['slug' => 'Not A Slug']))
        ->call('create')
        ->assertHasFormErrors(['slug' => 'regex']);
});

it('rejects duplicate field keys and mappings in the form builder', function () {
    actingAsAdmin();
    $undoRepeaterFake = Repeater::fake();
    $data = cvServiceFormData();
    $data['fields'][1]['key'] = 'full_name';
    $data['fields'][2]['maps_to'] = FieldMapping::CustomerName->value;

    Livewire::test(CreateService::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors(['fields.1.key', 'fields.2.maps_to']);

    $undoRepeaterFake();
    expect(Service::query()->where('slug', 'cv-optimization')->exists())->toBeFalse();
});

it('warns when no required question collects the delivery email or name', function () {
    actingAsAdmin();
    $undoRepeaterFake = Repeater::fake();
    $data = cvServiceFormData();
    $data['fields'][1]['requirement'] = 'optional';
    unset($data['fields'][0]);
    $data['fields'] = array_values($data['fields']);

    Livewire::test(CreateService::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('This live service has order-form problems');

    $undoRepeaterFake();
    $service = Service::query()->where('slug', 'cv-optimization')->firstOrFail();
    expect(\App\Filament\Support\Catalogue\ServiceFormChecks::warningsFor($service))->toHaveCount(2);
});

it('archives, restores and only permanently deletes services without orders', function () {
    actingAsAdmin();
    $service = Service::factory()->withStandardFields()->create(['is_active' => true]);

    Livewire::test(ListServices::class)
        ->callAction(\Filament\Actions\Testing\TestAction::make('delete')->table($service))
        ->assertHasNoActionErrors();

    $service->refresh();
    expect($service->trashed())->toBeTrue()
        ->and($service->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'service.archived')->exists())->toBeTrue();

    Livewire::test(ListServices::class)
        ->filterTable('trashed', true)
        ->callAction(\Filament\Actions\Testing\TestAction::make('restore')->table($service));
    expect($service->refresh()->trashed())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'service.restored')->exists())->toBeTrue();

    $withOrder = Service::factory()->create();
    \App\Models\Order::factory()->create(['service_id' => $withOrder->id]);
    $withOrder->delete();
    $withoutOrder = Service::factory()->withStandardFields()->create();
    $withoutOrder->delete();

    Livewire::test(ListServices::class)
        ->filterTable('trashed', true)
        ->assertActionHidden(\Filament\Actions\Testing\TestAction::make('forceDelete')->table($withOrder))
        ->callAction(\Filament\Actions\Testing\TestAction::make('forceDelete')->table($withoutOrder));

    expect(Service::withTrashed()->whereKey($withOrder->id)->exists())->toBeTrue()
        ->and(Service::withTrashed()->whereKey($withoutOrder->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'service.deleted')->exists())->toBeTrue();
});

it('duplicates a service as an inactive copy with its form and FAQs', function () {
    actingAsAdmin();
    $service = Service::factory()->withStandardFields()->create(['slug' => 'personal-statement', 'is_active' => true]);
    \App\Models\Faq::query()->create(['scope' => 'service', 'service_id' => $service->id, 'question' => 'Q?', 'answer' => 'A.', 'display_order' => 0, 'is_published' => true]);

    Livewire::test(ListServices::class)
        ->callAction(\Filament\Actions\Testing\TestAction::make('duplicate')->table($service));

    $copy = Service::query()->where('slug', 'personal-statement-copy')->firstOrFail();
    expect($copy->is_active)->toBeFalse()
        ->and($copy->fields()->count())->toBe($service->fields()->count())
        ->and($copy->faqs()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'service.duplicated')->exists())->toBeTrue();
});

it('reorders services from the list', function () {
    actingAsAdmin();
    $first = Service::factory()->create(['display_order' => 1]);
    $second = Service::factory()->create(['display_order' => 2]);

    Livewire::test(ListServices::class)
        ->call('reorderTable', [(string) $second->id, (string) $first->id]);

    expect($second->refresh()->display_order)->toBeLessThan($first->refresh()->display_order);
});
