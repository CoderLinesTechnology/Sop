<?php

use App\Enums\AdminRole;
use App\Filament\Pages\Settings as SettingsPage;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\ArticleCategories\ArticleCategoryResource;
use App\Filament\Resources\ArticleCategories\Pages\ManageArticleCategories;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Filament\Resources\Testimonials\Pages\ManageTestimonials;
use App\Filament\Resources\Testimonials\TestimonialResource;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Service;
use App\Models\Testimonial;
use Database\Seeders\ContentSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders every content screen for a content admin', function () {
    actingAsAdmin(AdminRole::Content);
    (new ContentSeeder)->run();
    $article = Article::query()->create(['title' => 'How to write an SOP', 'slug' => 'how-to-write-an-sop', 'body' => 'Body', 'is_published' => true]);

    $this->get(PageResource::getUrl('index'))->assertOk()->assertSee('Home');
    $this->get(PageResource::getUrl('edit', ['record' => Page::query()->where('slug', 'home')->first()]))->assertOk()->assertSee('How it works');
    $this->get(PageResource::getUrl('edit', ['record' => Page::query()->where('slug', 'privacy-policy')->first()]))->assertOk();
    $this->get(PageResource::getUrl('create'))->assertOk();
    $this->get(ArticleResource::getUrl('index'))->assertOk()->assertSee($article->title);
    $this->get(ArticleResource::getUrl('create'))->assertOk();
    $this->get(ArticleResource::getUrl('edit', ['record' => $article]))->assertOk();
    $this->get(ArticleCategoryResource::getUrl('index'))->assertOk()->assertSee('Study Abroad');
    $this->get(FaqResource::getUrl('index'))->assertOk();
    $this->get(TestimonialResource::getUrl('index'))->assertOk();
});

it('lets a content admin create and edit articles with a required cover alt text', function () {
    actingAsAdmin(AdminRole::Content);
    $category = ArticleCategory::query()->create(['name' => 'SOP', 'slug' => 'sop', 'icon' => 'document', 'display_order' => 1, 'is_active' => true]);

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Writing a statement of purpose',
            'slug' => 'writing-a-statement-of-purpose',
            'article_category_id' => $category->id,
            'excerpt' => 'A practical guide.',
            'body' => str_repeat('Show, do not tell. ', 300),
            'cover_image_path' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
            'cover_image_alt' => null,
            'is_published' => true,
            'display_order' => 1,
        ])
        ->call('create')
        ->assertHasFormErrors(['cover_image_alt' => 'required']);

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Writing a statement of purpose',
            'slug' => 'writing-a-statement-of-purpose',
            'article_category_id' => $category->id,
            'body' => str_repeat('Show, do not tell. ', 300),
            'cover_image_path' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
            'cover_image_alt' => 'A student writing at a desk',
            'is_published' => true,
            'display_order' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::query()->where('slug', 'writing-a-statement-of-purpose')->firstOrFail();
    expect($article->is_published)->toBeTrue()
        ->and($article->published_at)->not->toBeNull()
        ->and($article->reading_minutes)->toBeGreaterThan(1)
        ->and($article->cover_image_path)->toStartWith('articles/');
    Storage::disk('public')->assertExists($article->cover_image_path);
    expect(Article::query()->published()->whereKey($article->id)->exists())->toBeTrue();

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['title' => 'Writing a great statement of purpose', 'is_featured' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($article->refresh()->title)->toBe('Writing a great statement of purpose')
        ->and($article->is_featured)->toBeTrue();
});

it('edits home page sections and keeps unknown keys and bundled images', function () {
    actingAsAdmin(AdminRole::Content);
    (new ContentSeeder)->run();
    $home = Page::query()->where('slug', 'home')->firstOrFail();
    $sections = $home->sections;
    $sections['hero']['experimental_badge'] = 'Keep me';
    $sections['future_section'] = ['title' => 'Unknown section'];
    $home->update(['sections' => $sections]);
    Service::factory()->create(['slug' => 'scholarship-essay', 'name' => 'Scholarship Essay']);

    $undoRepeaterFake = Repeater::fake();

    $component = Livewire::test(EditPage::class, ['record' => $home->getRouteKey()]);
    $component
        ->fillForm([
            'sections.hero.title' => 'A new hero title',
            'sections.how_it_works.steps' => [
                ['icon' => 'user', 'title' => 'Tell us', 'text' => 'Share your story.'],
                ['icon' => 'mail', 'title' => 'Receive it', 'text' => 'By email.'],
            ],
            'sections.research.points' => [['point' => 'Programme research'], ['point' => 'Verification']],
            'sections.offer.service_slug' => 'scholarship-essay',
            'slug' => 'not-home',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();
    $home->refresh();

    expect($home->slug)->toBe('home')
        ->and($home->section('hero')['title'])->toBe('A new hero title')
        ->and($home->sections['hero']['experimental_badge'])->toBe('Keep me')
        ->and($home->sections['hero']['image'])->toBe('images/home/hero.jpg')
        ->and($home->sections['future_section'])->toBe(['title' => 'Unknown section'])
        ->and($home->sections['how_it_works']['steps'])->toHaveCount(2)
        ->and($home->sections['how_it_works']['steps'][1]['title'])->toBe('Receive it')
        ->and($home->sections['research']['points'])->toBe(['Programme research', 'Verification'])
        ->and($home->sections['offer']['service_slug'])->toBe('scholarship-essay')
        ->and($home->sections['cta']['button'])->toBe('Start Your Application');
});

it('edits the how-it-works details list and legal page bodies', function () {
    actingAsAdmin(AdminRole::Content);
    (new ContentSeeder)->run();
    $undoRepeaterFake = Repeater::fake();

    $page = Page::query()->where('slug', 'how-it-works')->firstOrFail();
    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['sections.details' => [['title' => 'Only step', 'text' => 'We do everything.']]])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($page->refresh()->sections['details'])->toEqual([['title' => 'Only step', 'text' => 'We do everything.']])
        ->and($page->sections['hero']['eyebrow'])->toBe('How it works');

    $undoRepeaterFake();

    $terms = Page::query()->where('slug', 'terms')->firstOrFail();
    Livewire::test(EditPage::class, ['record' => $terms->getRouteKey()])
        ->fillForm(['body' => "## Updated terms\n\nNew wording.", 'is_published' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($terms->refresh()->body)->toContain('New wording.');
});

it('never deletes system pages but deletes custom ones', function () {
    actingAsAdmin(AdminRole::Content);
    (new ContentSeeder)->run();
    $privacy = Page::query()->where('slug', 'privacy-policy')->firstOrFail();

    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'Partners', 'slug' => 'partners', 'kind' => 'standard', 'body' => 'Our partners.', 'is_published' => true])
        ->call('create')
        ->assertHasNoFormErrors();
    $custom = Page::query()->where('slug', 'partners')->firstOrFail();

    Livewire::test(ListPages::class)
        ->assertActionHidden(TestAction::make('delete')->table($privacy))
        ->callAction(TestAction::make('delete')->table($custom));

    Livewire::test(ListPages::class)
        ->selectTableRecords([$privacy->getKey()])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Page::query()->whereKey($custom->id)->exists())->toBeFalse()
        ->and(Page::query()->whereKey($privacy->id)->exists())->toBeTrue();

    Livewire::test(EditPage::class, ['record' => $privacy->getRouteKey()])
        ->assertActionHidden('delete')
        ->assertFormFieldDisabled('slug')
        ->assertFormFieldDisabled('kind');
});

it('manages FAQs by scope, including service FAQs', function () {
    actingAsAdmin(AdminRole::Content);
    $service = Service::factory()->create();

    Livewire::test(ManageFaqs::class)
        ->callAction('create', ['question' => 'Do you offer refunds?', 'answer' => 'Yes, see our policy.', 'scope' => 'service', 'service_id' => null, 'display_order' => 1, 'is_published' => true])
        ->assertHasActionErrors(['service_id' => 'required']);

    Livewire::test(ManageFaqs::class)
        ->callAction('create', ['question' => 'Do you offer refunds?', 'answer' => 'Yes, see our policy.', 'scope' => 'service', 'service_id' => $service->id, 'display_order' => 1, 'is_published' => true])
        ->assertHasNoActionErrors();

    $faq = Faq::query()->where('question', 'Do you offer refunds?')->firstOrFail();
    expect($faq->service_id)->toBe($service->id);

    Livewire::test(ManageFaqs::class)
        ->callAction(TestAction::make('edit')->table($faq), ['scope' => 'general'])
        ->assertHasNoActionErrors();

    expect($faq->refresh()->scope)->toBe('general')
        ->and($faq->service_id)->toBeNull();
});

it('manages testimonials and article categories', function () {
    actingAsAdmin(AdminRole::Content);

    Livewire::test(ManageTestimonials::class)
        ->callAction('create', ['quote' => 'Brilliant service.', 'author_name' => 'Ama K.', 'author_detail' => 'MSc applicant', 'rating' => 5, 'display_order' => 1, 'is_published' => true])
        ->assertHasNoActionErrors();
    expect(Testimonial::query()->published()->where('author_name', 'Ama K.')->exists())->toBeTrue();

    Livewire::test(ManageArticleCategories::class)
        ->callAction('create', ['name' => 'Interviews', 'slug' => 'interviews', 'icon' => 'user', 'display_order' => 3, 'is_active' => true])
        ->assertHasNoActionErrors();
    expect(ArticleCategory::query()->where('slug', 'interviews')->exists())->toBeTrue();
});

it('keeps content admins out of prices, settings and system screens', function () {
    actingAsAdmin(AdminRole::Content);
    $this->withoutVite();

    $this->get(SettingsPage::getUrl())->assertForbidden();
    $this->get(AdminUserResource::getUrl('index'))->assertForbidden();
    $this->get(PromptVersionResource::getUrl('index'))->assertForbidden();
});

it('keeps finance admins out of website content', function () {
    actingAsAdmin(AdminRole::Finance);
    $this->withoutVite();

    $this->get(PageResource::getUrl('index'))->assertForbidden();
    $this->get(ArticleResource::getUrl('index'))->assertForbidden();
});
