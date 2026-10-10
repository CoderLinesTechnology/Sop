<?php

use Database\Seeders\ContentSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Filament\Facades\Filament;

beforeEach(fn () => config()->set('statementra.runtime.tasks', []));

it('shows the logo in the header and the reversed logo in the footer of public pages', function () {
    $this->seed([ServiceCatalogSeeder::class, ContentSeeder::class]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<img src="'.asset('images/brand/statementra-logo.svg').'" alt="Statementra" width="157" height="44"')
        ->toContain('<img src="'.asset('images/brand/statementra-logo-reversed.svg').'" alt="Statementra" width="143" height="40"')
        ->toContain('aria-label="Statementra home"')
        ->toContain('favicon.svg?v=2');

    // The header logo is above the fold: never lazy-loaded.
    preg_match('~<img src="[^"]*statementra-logo\.svg"[^>]*>~', $html, $header);
    expect($header[0] ?? '')->not->toContain('loading="lazy"');
});

it('puts the logo at the top of every email as an absolute PNG link', function () {
    $html = view('emails.layout', ['subject' => 'Your document is ready', 'content' => '<p>Hello</p>'])->render();

    expect($html)
        ->toContain('src="'.asset('images/brand/statementra-logo-email.png').'"')
        ->toContain('width="200" height="56" alt="Statementra"')
        ->and(asset('images/brand/statementra-logo-email.png'))->toStartWith('http');
});

it('shows the logo in the admin panel', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getBrandLogo())->toBe(asset('images/brand/statementra-logo.svg'))
        ->and($panel->getBrandLogoHeight())->toBe('2rem');
});

it('ships the brand files at the sizes the markup declares, with nothing executable in the SVGs', function () {
    foreach (['statementra-logo.svg', 'statementra-logo-reversed.svg'] as $file) {
        $svg = (string) file_get_contents(public_path('images/brand/'.$file));
        preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $svg, $box);

        // The width/height attributes (157x44 header, 143x40 footer) follow the artwork's own proportions.
        expect($svg)->not->toMatch('/<script|\son\w+=|href=|<foreignObject|url\(\s*["\']?https?:/i')
            ->and(abs((float) $box[1] / (float) $box[2] - 157 / 44))->toBeLessThan(0.02)
            ->and(abs((float) $box[1] / (float) $box[2] - 143 / 40))->toBeLessThan(0.02);
    }

    expect(getimagesize(public_path('images/brand/statementra-logo-email.png')))->toMatchArray([0 => 400, 1 => 112])
        ->and(getimagesize(public_path('apple-touch-icon.png')))->toMatchArray([0 => 180, 1 => 180])
        ->and(file_get_contents(public_path('favicon.svg')))->not->toMatch('/<script|\son\w+=|href=/i');
});
