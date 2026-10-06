<?php

use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateSnapshot;
use Tests\Unit\Documents\Samples;

it('fills gaps with professional defaults and clamps values', function () {
    $snapshot = TemplateSnapshot::normalize(['font_family' => 'Comic Sans', 'font_size' => 72, 'margin_left_mm' => 1, 'text_align' => 'centre', 'page_size' => 'us letter', 'page_numbers' => 'sideways']);

    expect($snapshot['font_family'])->toBe('Times New Roman')
        ->and($snapshot['font_size'])->toBe(20.0)
        ->and($snapshot['margin_left_mm'])->toBe(5.0)
        ->and($snapshot['margin_top_mm'])->toBe(25.4)
        ->and($snapshot['text_align'])->toBe('center')
        ->and($snapshot['page_size'])->toBe('Letter')
        ->and($snapshot['page_numbers'])->toBe('bottom_center')
        ->and($snapshot['citation_style'])->toBe('none')
        ->and($snapshot['include_branding'])->toBeFalse()
        ->and($snapshot['context']['brand'])->toBe('Statementra')
        ->and(TemplateSnapshot::normalize($snapshot))->toBe($snapshot); // idempotent
});

it('applies requirement overrides and records them', function () {
    $requirements = new ResolvedRequirements(pageSize: 'letter', fontFamily: 'Arial', fontSize: 11, marginsMm: 20, lineSpacing: 2, dateFormat: 'F j, Y', languageVariant: 'en-US');
    $snapshot = TemplateSnapshot::make(Samples::template(), $requirements, ['document_type' => 'Personal Statement', 'institution' => 'MIT']);

    expect($snapshot)->toMatchArray([
        'page_size' => 'Letter', 'font_family' => 'Arial', 'font_size' => 11.0, 'margin_top_mm' => 20.0, 'margin_left_mm' => 20.0,
        'line_spacing' => 2.0, 'date_format' => 'F j, Y', 'language_variant' => 'en-US',
    ])
        ->and($snapshot['applied_overrides']['page_size'])->toBe(['template' => 'A4', 'applied' => 'Letter', 'requirement' => 'pageSize'])
        ->and($snapshot['context'])->toMatchArray(['document_type' => 'Personal Statement', 'institution' => 'MIT'])
        ->and($snapshot['ignored_overrides'])->toBe([]);
});

it('ignores overrides it cannot honour, saying why', function () {
    $snapshot = TemplateSnapshot::make(Samples::template(), new ResolvedRequirements(pageSize: 'A3', fontFamily: 'Georgia', fontSize: 4, marginsMm: 200));

    expect($snapshot['font_family'])->toBe('Times New Roman')
        ->and($snapshot['page_size'])->toBe('A4')
        ->and(array_keys($snapshot['ignored_overrides']))->toBe(['page_size', 'font_family', 'font_size', 'margins_mm'])
        ->and($snapshot['ignored_overrides']['font_family']['reason'])->toContain('metric-compatible');
});

it('lets an explicitly chosen template keep its formatting over country conventions only', function () {
    $requirements = new ResolvedRequirements(pageSize: 'A4', fontFamily: 'Arial', fieldSources: ['pageSize' => 'convention', 'fontFamily' => 'official_programme']);
    $snapshot = TemplateSnapshot::make(Samples::template(['page_size' => 'Letter']), $requirements, preferTemplate: true);

    expect($snapshot['page_size'])->toBe('Letter')
        ->and($snapshot['ignored_overrides'])->toHaveKey('page_size')
        ->and($snapshot['font_family'])->toBe('Arial')
        ->and(TemplateSnapshot::make(Samples::template(['page_size' => 'Letter']), $requirements)['page_size'])->toBe('A4');
});

it('turns a snapshot back into an unsaved template', function () {
    $template = TemplateSnapshot::toTemplate(TemplateSnapshot::normalize(['font_family' => 'Calibri', 'template_name' => 'Letter layout', 'show_title' => false]));

    expect($template->exists)->toBeFalse()
        ->and($template->name)->toBe('Letter layout')
        ->and($template->font_family)->toBe('Calibri')
        ->and($template->show_title)->toBeFalse();
});
