<?php

use App\Enums\RequirementLevel;
use App\Filament\Support\Catalogue\ServiceFieldNormalizer;
use App\Models\Service;
use Database\Seeders\ServiceCatalogSeeder;

it('shows clickable answer starters under a question', function () {
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);
    $service->fields()->where('key', 'goals')->firstOrFail()->update(['options' => ['starters' => ['After graduating, I want to …', 'In five years, I hope to …']]]);

    $this->get(route('order.start', $service->slug))
        ->assertOk()
        ->assertSee('data-starter="After graduating, I want to …"', false)
        ->assertSee('data-starter-target="answer-goals"', false)
        ->assertDontSee('data-starter-target="answer-background"', false);
});

it('keeps tidy starters when an administrator saves a question, and only on long-answer questions', function () {
    $textarea = ServiceFieldNormalizer::normalize(['key' => 'goals', 'label' => 'Goals', 'type' => 'textarea', 'options' => ['starters' => [
        '  After  graduating, I want to … ', 'After graduating, I want to …', '', 'Two', 'Three', 'Four', 'Five',
    ]]]);
    $text = ServiceFieldNormalizer::normalize(['key' => 'title', 'label' => 'Title', 'type' => 'text', 'options' => ['starters' => ['x']]]);

    expect($textarea['options'])->toBe(['starters' => ['After graduating, I want to …', 'Two', 'Three', 'Four']])
        ->and($text['options'])->toBeNull();
});

it('asks for the story behind a personal statement or SOP even when a CV is uploaded', function () {
    $this->seed(ServiceCatalogSeeder::class);

    foreach (['personal-statement' => ['why_field', 'goals'], 'statement-of-purpose' => ['research_interests', 'goals']] as $slug => $keys) {
        $fields = Service::query()->where('slug', $slug)->firstOrFail()->fields()->whereIn('key', $keys)->get();

        expect($fields)->toHaveCount(2);
        foreach ($fields as $field) {
            expect($field->requirement)->toBe(RequirementLevel::Required)
                ->and($field->optional_when_upload)->toBeNull()
                ->and($field->starters())->not->toBeEmpty();
        }
    }
});
