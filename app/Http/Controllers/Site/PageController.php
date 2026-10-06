<?php

namespace App\Http\Controllers\Site;

use App\Domain\Catalogue\Catalogue;
use App\Http\Controllers\Controller;
use App\Support\Seo;
use App\Support\Settings;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PageController extends Controller
{
    public const STANDARD_PAGES = ['about', 'privacy-policy', 'terms', 'refund-policy', 'cookie-policy'];

    public function show(Catalogue $catalogue, string $page): View
    {
        $model = $catalogue->page($page);
        abort_if($model === null, 404);

        $seo = Seo::make($model->seo_title ?: $model->title, $model->seo_description ?: $model->excerpt)
            ->withBreadcrumbs([['Home', url('/')], [$model->title, url($model->slug)]]);

        return view('site.pages.show', [
            'page' => $model,
            'html' => $this->render((string) $model->body, $model),
            'seo' => $seo,
        ]);
    }

    public function howItWorks(Catalogue $catalogue): View
    {
        $page = $catalogue->page('how-it-works');
        $home = $catalogue->page('home');
        $faqs = $catalogue->faqs('general', 6);

        return view('site.pages.how-it-works', [
            'page' => $page,
            'home' => $home,
            'services' => $catalogue->services(),
            'faqs' => $faqs,
            'seo' => Seo::make($page?->seo_title ?: 'How It Works', $page?->seo_description)
                ->withBreadcrumbs([['Home', url('/')], ['How It Works', route('pages.how-it-works')]])
                ->withFaqs($faqs),
        ]);
    }

    public function faq(Catalogue $catalogue): View
    {
        $page = $catalogue->page('faq');
        $general = $catalogue->faqs('general', 50);
        $home = $catalogue->faqs('home', 50);
        $all = $home->concat($general)->unique('question')->values();

        return view('site.pages.faq', [
            'page' => $page,
            'faqs' => $all,
            'seo' => Seo::make($page?->seo_title ?: 'Frequently Asked Questions', $page?->seo_description)
                ->withBreadcrumbs([['Home', url('/')], ['FAQ', route('pages.faq')]])
                ->withFaqs($all),
        ]);
    }

    /** Markdown → safe HTML with a few administrator-friendly placeholders. */
    private function render(string $markdown, \App\Models\Page $page): string
    {
        $markdown = strtr($markdown, [
            '{{date}}' => ($page->updated_at ?? now())->format('j F Y'),
            '{{support_email}}' => Settings::supportEmail(),
            '{{contact_email}}' => (string) Settings::get('general.contact_email'),
            '{{retention_days}}' => (string) Settings::get('orders.retention_days', 90),
            '{{site_name}}' => Settings::siteName(),
        ]);

        return (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ], [new \League\CommonMark\Extension\Table\TableExtension]);
    }
}
