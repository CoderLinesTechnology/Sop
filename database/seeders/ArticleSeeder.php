<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Starter guides for /resources. Article bodies are Markdown files in
 * database/seeders/content/articles/{slug}.md.
 *
 * Idempotent: articles are matched by slug and updated in place, so re-running
 * refreshes the copy without creating duplicates. An existing article keeps its
 * original publication date. Categories are looked up by slug (ContentSeeder
 * creates them); reading time is computed by the Article model on save.
 */
class ArticleSeeder extends Seeder
{
    public function run(): void
    {
        $categories = ArticleCategory::query()->pluck('id', 'slug');

        foreach ($this->articles() as $position => $article) {
            $categoryId = $categories[$article['category']] ?? null;
            if ($categoryId === null) {
                $this->command?->warn("ArticleSeeder: category '{$article['category']}' not found; '{$article['slug']}' will be uncategorized.");
            }

            // Seed once: after that the articles belong to the editors, and
            // re-running the seeder must never undo their changes.
            Article::query()->firstOrCreate(['slug' => $article['slug']], [
                'article_category_id' => $categoryId,
                'title' => $article['title'],
                'excerpt' => $article['excerpt'],
                'body' => $this->body($article['slug']),
                'cover_image_path' => $article['cover_image_path'],
                'cover_image_alt' => $article['cover_image_alt'],
                'author_name' => 'Statementra Editorial',
                'is_featured' => $position < 4,
                'is_published' => true,
                'published_at' => now()->subDays($article['days_ago'])->setTime(9, 0),
                'display_order' => $position + 1,
                'seo_title' => $article['seo_title'],
                'seo_description' => $article['seo_description'],
            ]);
        }
    }

    private function body(string $slug): string
    {
        $path = __DIR__.'/content/articles/'.$slug.'.md';
        if (! is_file($path)) {
            throw new RuntimeException("ArticleSeeder: missing article body at {$path}.");
        }

        return trim(str_replace("\r\n", "\n", (string) file_get_contents($path)));
    }

    /** In display order; the first four are featured. */
    private function articles(): array
    {
        return [
            [
                'slug' => 'how-to-write-a-personal-statement',
                'title' => 'How to Write a Personal Statement',
                'category' => 'personal-statement',
                'excerpt' => 'A step-by-step guide to planning, drafting and polishing a personal statement that sounds like you and gives admissions readers real evidence.',
                'cover_image_path' => 'images/articles/personal-statement.jpg',
                'cover_image_alt' => 'An open notebook with a black pen on a wooden desk beside a potted plant',
                'seo_title' => 'How to Write a Personal Statement: Step-by-Step Guide',
                'seo_description' => 'Plan, draft and polish a personal statement admissions readers remember: what to include, a clear structure, a before-and-after example and a checklist.',
                'days_ago' => 3,
            ],
            [
                'slug' => 'how-to-write-a-statement-of-purpose',
                'title' => 'How to Write a Statement of Purpose',
                'category' => 'sop',
                'excerpt' => 'What admissions committees look for in a statement of purpose, how it differs from a personal statement, and a clear structure for showing your fit.',
                'cover_image_path' => 'images/articles/statement-of-purpose.jpg',
                'cover_image_alt' => 'A historic red-brick university building with a stone Gothic entrance, framed by trees',
                'seo_title' => 'How to Write a Statement of Purpose (SOP)',
                'seo_description' => 'Learn what a statement of purpose should cover, how it differs from a personal statement, and how to show preparation, programme fit and clear goals.',
                'days_ago' => 10,
            ],
            [
                'slug' => 'how-to-write-a-motivation-letter',
                'title' => 'How to Write a Motivation Letter',
                'category' => 'motivation-letter',
                'excerpt' => 'How to write a clear, sincere motivation letter for a university programme, scholarship or exchange, with a paragraph-by-paragraph structure and examples.',
                'cover_image_path' => 'images/articles/motivation-letter.jpg',
                'cover_image_alt' => 'A person in a green sweater writing by hand in an open notebook',
                'seo_title' => 'How to Write a Motivation Letter: Structure and Tips',
                'seo_description' => 'A practical guide to motivation letters for programmes, scholarships and exchanges: format, paragraph-by-paragraph structure, examples and a checklist.',
                'days_ago' => 17,
            ],
            [
                'slug' => 'scholarship-essay-guide',
                'title' => 'Scholarship Essay Guide',
                'category' => 'scholarship',
                'excerpt' => 'How to read a scholarship call, match your evidence to the selection criteria and write an essay that answers the prompt with honest, specific examples.',
                'cover_image_path' => 'images/articles/scholarship-essay.jpg',
                'cover_image_alt' => 'A graduation cap resting on a stack of books beside a small desk globe',
                'seo_title' => 'Scholarship Essay Guide: Structure, Evidence and Tips',
                'seo_description' => 'Read the scholarship call, map your achievements to its selection criteria and write a focused, honest essay, with a structure, examples and a checklist.',
                'days_ago' => 24,
            ],
            [
                'slug' => 'essential-application-tips',
                'title' => '10 Essential Application Tips',
                'category' => 'application-tips',
                'excerpt' => 'Ten practical habits for calmer, stronger applications, from building a requirements tracker to briefing referees and submitting early.',
                'cover_image_path' => 'images/articles/application-tips.jpg',
                'cover_image_alt' => 'A laptop, an open notebook and a cup of coffee on a sunlit wooden desk',
                'seo_title' => '10 Essential Tips for a Stronger Application',
                'seo_description' => 'Ten practical tips for university and scholarship applications: tracking requirements, planning a timeline, briefing referees and submitting early.',
                'days_ago' => 31,
            ],
            [
                'slug' => 'how-to-optimize-your-cv',
                'title' => 'How to Optimize Your CV',
                'category' => 'cv-resume',
                'excerpt' => 'How to shape a clear, honest CV for university, scholarship and early-career applications: structure, strong bullet points, tailoring and formatting checks.',
                'cover_image_path' => 'images/articles/cv.jpg',
                'cover_image_alt' => 'A printed resume on a desk with a black pen beside it',
                'seo_title' => 'How to Optimize Your CV for Study and Job Applications',
                'seo_description' => 'Make your CV clear and persuasive for university, scholarship and job applications: what to include, strong bullet points, tailoring and formatting.',
                'days_ago' => 38,
            ],
            [
                'slug' => 'choosing-the-right-country-and-university',
                'title' => 'Choosing the Right Country and University',
                'category' => 'study-abroad',
                'excerpt' => 'A practical framework for comparing countries, universities and programmes on what matters most: course content, total cost, visas, recognition and fit.',
                'cover_image_path' => 'images/articles/study-abroad.jpg',
                'cover_image_alt' => 'An open notebook of handwritten notes beside a small globe on a world map',
                'seo_title' => 'How to Choose the Right Country and University Abroad',
                'seo_description' => 'Compare study destinations and universities with a clear framework: programme content, total cost, language, visas, recognition, careers and fit.',
                'days_ago' => 45,
            ],
            [
                'slug' => 'common-application-mistakes-to-avoid',
                'title' => 'Common Mistakes to Avoid',
                'category' => 'application-tips',
                'excerpt' => 'Twelve avoidable mistakes that can weaken a strong application, from generic statements and unanswered prompts to inconsistent details, with a fix for each.',
                'cover_image_path' => 'images/articles/common-mistakes.jpg',
                'cover_image_alt' => 'An open laptop, a cup of black coffee and a notebook on a wooden desk, seen from above',
                'seo_title' => 'Common Application Mistakes and How to Fix Them',
                'seo_description' => 'Avoid the mistakes that weaken applications, from generic statements and ignored prompts to wrong names and inconsistent details, with a fix for each.',
                'days_ago' => 52,
            ],
        ];
    }
}
