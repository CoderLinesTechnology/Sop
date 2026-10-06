<?php

use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\ResolvedRequirements;
use Tests\Feature\Documents\Fixtures;

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
});

afterEach(fn () => Fixtures::removePrivateDisk());

it('renders accented and Polish names without missing glyphs', function (string $name, string $filename) {
    $model = Fixtures::model(['applicant_name' => $name, 'blocks' => [
        ['type' => 'paragraph', 'text' => "My grandmother in Łódź, Kraków and Århus taught me patience; ΕΛΛΗΝΙΚΆ and русский came later. — {$name}"],
    ]]);
    $version = Fixtures::rendered(Fixtures::order(['applicant_name' => $name, 'customer_name' => $name]), $model);
    $pdf = Fixtures::pdfBytes($version);
    $result = app(DocumentQa::class)->validate($version);
    $docxParagraphs = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')))->pluck('text');

    expect(Fixtures::pdfText($pdf))->toContain($name)->toContain('Łódź, Kraków and Århus')->toContain('ΕΛΛΗΝΙΚΆ and русский')
        ->and($docxParagraphs)->toContain($name)
        ->and(Fixtures::pdfInfo($pdf))->toMatch('/Author:\s+'.preg_quote($name, '/').'/u')
        ->and($result->check('pdf_glyphs')['passed'])->toBeTrue()
        ->and($result->check('pdf_glyphs')['detail'])->toContain('All characters are covered')
        ->and($result->passed)->toBeTrue($result->summary())
        ->and($version->pdf_filename)->toBe($filename);
})->with([
    ['Zoë Ångström-Nwosu', 'Zoe_Angstrom-Nwosu_Statement_of_Purpose.pdf'],
    ['Łukasz Żółć', 'Lukasz_Zolc_Statement_of_Purpose.pdf'],
]);

it('draws characters the main font lacks with the embedded fallback font', function () {
    $name = 'Nguyễn Ọláolúwa Đặng';
    $model = Fixtures::model(['applicant_name' => $name]);
    $requirements = new ResolvedRequirements(fontFamily: 'Cambria', languageVariant: 'en-GB'); // Caladea has no Vietnamese/Yoruba glyphs
    $version = Fixtures::rendered(Fixtures::order(['applicant_name' => $name]), $model, $requirements);
    $result = app(DocumentQa::class)->validate($version);

    expect(Fixtures::pdfText(Fixtures::pdfBytes($version)))->toContain($name)
        ->and(Fixtures::pdfFonts(Fixtures::pdfBytes($version)))->toContain('Caladea')->toContain('DejaVuSans')
        ->and($result->check('pdf_glyphs')['passed'])->toBeTrue()
        ->and($result->check('pdf_glyphs')['detail'])->toContain('fallback font')
        ->and($result->check('pdf_text_matches')['passed'])->toBeTrue()
        ->and(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/styles.xml'))->toContain('w:ascii="Cambria"');
});

it('flags characters no embedded font can draw', function () {
    $model = Fixtures::model(['applicant_name' => '王小明']);
    $version = Fixtures::rendered(Fixtures::order(['applicant_name' => '王小明']), $model);
    $result = app(DocumentQa::class)->validate($version);

    expect($result->check('pdf_glyphs')['passed'])->toBeFalse()
        ->and($result->check('pdf_glyphs')['detail'])->toContain('王')
        ->and($result->passed)->toBeFalse();
});
