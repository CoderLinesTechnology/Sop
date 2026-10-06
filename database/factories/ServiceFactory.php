<?php

namespace Database\Factories;

use App\Enums\FieldMapping;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Personal Statement', 'Statement of Purpose', 'Motivation Letter', 'Scholarship Essay', 'General Essay', 'Cover Letter', 'Research Proposal']).' '.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'document_kind' => 'personal_statement',
            'short_description' => fake()->sentence(14),
            'description' => fake()->paragraph(),
            'card_features' => ['Personalized', 'Researched', 'Formatted'],
            'icon' => 'document',
            'icon_color' => 'green',
            'price' => 8900,
            'compare_at_price' => 12000,
            'currency' => 'USD',
            'is_active' => true,
            'display_order' => 0,
            'revisions_included' => 1,
            'revision_window_days' => 14,
            'revision_fee' => 1500,
            'revision_mode' => 'ai',
            'default_word_limit' => 650,
        ];
    }

    /** Adds the standard required fields (name, email, institution, programme, country) and a CV upload slot. */
    public function withStandardFields(): static
    {
        return $this->afterCreating(function (Service $service) {
            $fields = [
                ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'section' => 'details', 'requirement' => 'required', 'maps_to' => FieldMapping::CustomerName->value],
                ['key' => 'email', 'label' => 'Email address', 'type' => 'email', 'section' => 'details', 'requirement' => 'required', 'maps_to' => FieldMapping::Email->value],
                ['key' => 'institution', 'label' => 'University / Institution', 'type' => 'text', 'section' => 'application', 'requirement' => 'required', 'maps_to' => FieldMapping::Institution->value],
                ['key' => 'programme', 'label' => 'Programme / Course', 'type' => 'text', 'section' => 'application', 'requirement' => 'required', 'maps_to' => FieldMapping::Programme->value],
                ['key' => 'country', 'label' => 'Country', 'type' => 'country', 'section' => 'application', 'requirement' => 'required', 'maps_to' => FieldMapping::Country->value],
                ['key' => 'cv', 'label' => 'CV / Resume', 'type' => 'file', 'section' => 'application', 'requirement' => 'optional', 'options' => ['accept' => ['pdf', 'docx', 'txt', 'jpg', 'png'], 'max_files' => 1, 'purpose' => 'cv']],
                ['key' => 'background', 'label' => 'Your background', 'type' => 'textarea', 'section' => 'story', 'requirement' => 'required', 'optional_when_upload' => 'cv'],
                ['key' => 'goals', 'label' => 'Your goals', 'type' => 'textarea', 'section' => 'story', 'requirement' => 'recommended'],
            ];

            foreach ($fields as $i => $field) {
                $service->fields()->create($field + ['display_order' => $i, 'is_active' => true]);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
