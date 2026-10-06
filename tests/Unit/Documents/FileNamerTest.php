<?php

use App\Domain\Documents\FileNamer;
use Tests\Unit\Documents\Samples;

it('builds clean professional file names', function () {
    expect(FileNamer::filename(Samples::snapshot(), 'Daniel Essel', 'pdf'))->toBe('Daniel_Essel_Statement_of_Purpose.pdf')
        ->and(FileNamer::filename(Samples::snapshot(), 'Daniel Essel', 'DOCX'))->toBe('Daniel_Essel_Statement_of_Purpose.docx');
});

it('transliterates accents and removes unsafe characters', function (string $name, string $expected) {
    expect(FileNamer::base(Samples::snapshot(), $name))->toBe($expected);
})->with([
    ['Zoë Ångström-Nwosu', 'Zoe_Angstrom-Nwosu_Statement_of_Purpose'],
    ['Łukasz Żółć', 'Lukasz_Zolc_Statement_of_Purpose'],
    ['José O’Neill', 'Jose_ONeill_Statement_of_Purpose'],
    ['  Mary-Jane   Watson / Parker ', 'Mary-Jane_Watson_Parker_Statement_of_Purpose'],
    ['../../etc/passwd', 'etc_passwd_Statement_of_Purpose'],
    ['Ama <script>alert(1)</script>', 'Ama_script_alert_1_script_Statement_of_Purpose'],
]);

it('leaves the name out when the template keeps names out of file names', function () {
    expect(FileNamer::filename(Samples::snapshot(['include_name_in_filename' => false]), 'Daniel Essel', 'pdf'))->toBe('Statement_of_Purpose.pdf');
});

it('falls back to the brand when there is no usable name', function (?string $name) {
    expect(FileNamer::filename(Samples::snapshot(), $name, 'pdf'))->toBe('Statementra_Statement_of_Purpose.pdf');
})->with([null, '', '   ', '!!!']);

it('supports institution and programme placeholders', function () {
    $snapshot = Samples::snapshot(['filename_pattern' => '{applicant_name} - {document_type} ({institution}, {programme})'], [
        'institution' => 'Université de Montréal', 'programme' => 'MSc Informatique',
    ]);

    expect(FileNamer::base($snapshot, 'Amara Okafor'))->toBe('Amara_Okafor_Statement_of_Purpose_Universite_de_Montreal_MSc_Informatique')
        ->and(FileNamer::base(Samples::snapshot(['filename_pattern' => '{document_type}_{institution}']), 'Amara Okafor'))->toBe('Statement_of_Purpose');
});

it('never exceeds the maximum length', function () {
    $snapshot = Samples::snapshot(['filename_pattern' => '{applicant_name}_{document_type}_{institution}_{programme}'], [
        'institution' => str_repeat('Very Long Institution Name ', 6), 'programme' => 'Master of Science in Advanced Computational Methods',
    ]);
    $base = FileNamer::base($snapshot, 'Daniel Essel');

    expect(strlen($base))->toBeLessThanOrEqual(FileNamer::MAX_LENGTH)
        ->and($base)->toStartWith('Daniel_Essel_Statement_of_Purpose')
        ->and($base)->not->toEndWith('_');
});

it('romanises non-Latin names when intl is available', function () {
    expect(FileNamer::base(Samples::snapshot(), '王小明'))->toBe('Wang_Xiao_Ming_Statement_of_Purpose');
})->skip(! function_exists('transliterator_transliterate'), 'The intl extension is not installed.');
