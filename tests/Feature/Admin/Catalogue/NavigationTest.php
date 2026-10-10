<?php

use App\Enums\AdminRole;
use App\Filament\Pages\AiControlCenter;
use App\Filament\Pages\Settings as SettingsPage;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\AiModelPrices\AiModelPriceResource;
use App\Filament\Resources\AiWorkflows\AiWorkflowResource;
use App\Filament\Resources\ArticleCategories\ArticleCategoryResource;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Filament\Resources\RequirementRules\RequirementRuleResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Testimonials\TestimonialResource;
use App\Filament\Resources\WritingSamples\WritingSampleResource;
use Filament\Facades\Filament;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** Which of this area's screens each built-in role can open. */
dataset('access', [
    'super admin' => [AdminRole::SuperAdmin, ['services', 'pages', 'articles', 'categories', 'faqs', 'testimonials', 'emails', 'workflows', 'prompts', 'samples', 'prices', 'rules', 'templates', 'control', 'settings', 'admins', 'roles', 'audit']],
    'content admin' => [AdminRole::Content, ['services', 'pages', 'articles', 'categories', 'faqs', 'testimonials', 'emails']],
    'finance admin' => [AdminRole::Finance, ['services']],
    'operations admin' => [AdminRole::Operations, []],
    'AI admin' => [AdminRole::Ai, ['workflows', 'prompts', 'samples', 'prices', 'rules', 'templates', 'control']],
]);

it('gives each role exactly the screens it needs', function (AdminRole $role, array $allowed) {
    actingAsAdmin($role);

    $screens = [
        'services' => ServiceResource::class,
        'pages' => PageResource::class,
        'articles' => ArticleResource::class,
        'categories' => ArticleCategoryResource::class,
        'faqs' => FaqResource::class,
        'testimonials' => TestimonialResource::class,
        'emails' => EmailTemplateResource::class,
        'workflows' => AiWorkflowResource::class,
        'prompts' => PromptVersionResource::class,
        'samples' => WritingSampleResource::class,
        'prices' => AiModelPriceResource::class,
        'rules' => RequirementRuleResource::class,
        'templates' => DocumentTemplateResource::class,
        'control' => AiControlCenter::class,
        'settings' => SettingsPage::class,
        'admins' => AdminUserResource::class,
        'roles' => RoleResource::class,
        'audit' => AuditLogResource::class,
    ];

    foreach ($screens as $name => $class) {
        expect($class::canAccess())->toBe(in_array($name, $allowed, true), "{$role->value} → {$name}");
    }
})->with('access');

it('renders the navigation with this area\'s groups for a super admin', function () {
    actingAsAdmin();

    $this->get(ServiceResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Catalogue')
        ->assertSee('Content')
        ->assertSee('Control centre')
        ->assertSee('Prompt versions')
        ->assertSee('Administrators')
        ->assertSee('Audit log');
});
