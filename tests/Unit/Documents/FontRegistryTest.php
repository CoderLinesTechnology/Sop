<?php

use App\Domain\Documents\FontRegistry;

it('maps the standard Word fonts to embedded metric-compatible files', function () {
    foreach (FontRegistry::families() as $family) {
        foreach (['R', 'B', 'I', 'BI'] as $style) {
            expect(FontRegistry::file($family, $style))->toBeFile();
        }
    }

    expect(FontRegistry::families())->toBe(['Times New Roman', 'Arial', 'Calibri', 'Cambria'])
        ->and(FontRegistry::normalize('helvetica'))->toBe('Arial')
        ->and(FontRegistry::normalize(' "times new roman" '))->toBe('Times New Roman')
        ->and(FontRegistry::normalize('Georgia'))->toBeNull()
        ->and(FontRegistry::resolve('Georgia'))->toBe('Times New Roman')
        ->and(FontRegistry::pdfKey('Calibri'))->toBe('calibri')
        ->and(FontRegistry::lineFactor('Calibri'))->toBeGreaterThan(FontRegistry::lineFactor('Arial'))
        ->and(glob(FontRegistry::fontsPath().'/LICENSE-*.txt'))->toHaveCount(3);
});

it('knows which characters each font can draw', function () {
    $latin = FontRegistry::glyphCoverage('Zoë Ångström-Nwosu, Łukasz Żółć — “quoted”', 'Times New Roman');
    $vietnamese = FontRegistry::glyphCoverage('Nguyễn Văn Đức', 'Cambria');
    $chinese = FontRegistry::glyphCoverage('王小明', 'Arial');

    expect($latin)->toBe(['fallback' => [], 'missing' => []])
        ->and($vietnamese['fallback'])->toContain('ễ')
        ->and($vietnamese['missing'])->toBe([])
        ->and($chinese['missing'])->toBe(['王', '小', '明']);
});
