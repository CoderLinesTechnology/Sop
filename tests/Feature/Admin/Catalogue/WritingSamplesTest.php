<?php

use App\Enums\AdminRole;
use App\Enums\DocumentKind;
use App\Filament\Resources\WritingSamples\Pages\CreateWritingSample;
use App\Filament\Resources\WritingSamples\Pages\EditWritingSample;
use App\Filament\Resources\WritingSamples\Pages\ListWritingSamples;
use App\Filament\Resources\WritingSamples\WritingSampleResource;
use App\Models\AuditLog;
use App\Models\WritingSample;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function sampleText(): string
{
    return implode("\n\n", [
        'The first time I watched a cholera map update in real time, I was sitting in a district health office in Tamale with a laptop that overheated every hour. The map was mine: I had built it over three weekends from clinic ledgers that nobody had digitised before.',
        'That project taught me how much public health depends on unglamorous data work. I spent the following year cleaning reporting forms for eleven clinics, training nurses to enter cases on a shared spreadsheet, and writing short weekly summaries that the district officer actually read.',
        'I now want the formal training to do this work properly. The programme\'s modules in spatial epidemiology and health information systems are the two gaps I feel most when I try to explain why an outbreak spread the way it did.',
        'Reach me at kwame.mensah@example.com or +233 24 555 0199, or see linkedin.com/in/kwame-mensah and https://kwame.example.org/portfolio for the dashboards. I worked there from 2019 - 2021.',
    ]);
}

function sampleDocx(string $text): string
{
    $paragraphs = implode('', array_map(
        fn (string $p) => '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($p, ENT_XML1).'</w:t></w:r></w:p>',
        explode("\n\n", $text),
    ));

    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.'</w:body></w:document>');
    $zip->close();

    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function sampleForm(array $overrides = []): array
{
    return $overrides + [
        'title' => 'Public health SOP (2024 intake)',
        'document_kind' => DocumentKind::StatementOfPurpose->value,
        'degree_level' => 'masters',
        'field_of_study' => 'Public Health',
        'country_code' => 'GB',
        'priority' => 0,
        'notes' => 'Opens with one concrete moment and links each module to past work.',
        'is_active' => true,
        'rights_confirmed' => true,
    ];
}

it('lets AI admins open the writing sample screens and keeps other roles out', function () {
    actingAsAdmin(AdminRole::Ai);
    $sample = WritingSample::query()->create([
        'title' => 'Engineering SOP', 'document_kind' => 'statement_of_purpose', 'content' => sampleText(),
        'word_count' => 180, 'source' => 'pasted', 'rights_confirmed_at' => now(),
    ]);

    $this->get(WritingSampleResource::getUrl('index'))->assertOk()->assertSee('Engineering SOP')->assertSee('Writing samples');
    $this->get(WritingSampleResource::getUrl('create'))->assertOk();
    $this->get(WritingSampleResource::getUrl('edit', ['record' => $sample]))->assertOk()->assertSee('cholera map');

    $this->app['auth']->forgetGuards();
    $this->flushSession();
    actingAsAdmin(AdminRole::Content);
    expect(WritingSampleResource::canAccess())->toBeFalse();
    $this->get(WritingSampleResource::getUrl('index'))->assertForbidden();
    $this->get(WritingSampleResource::getUrl('edit', ['record' => $sample]))->assertForbidden();
});

it('imports a DOCX upload as redacted, encrypted text and discards the file', function () {
    $admin = actingAsAdmin(AdminRole::Ai);
    $filesBefore = count(Storage::disk('private')->allFiles());

    Livewire::test(CreateWritingSample::class)
        ->fillForm(sampleForm(['source' => 'upload', 'file' => UploadedFile::fake()->createWithContent('Kwame Mensah SOP.docx', sampleDocx(sampleText()))]))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(WritingSampleResource::getUrl('edit', ['record' => WritingSample::query()->sole()]));

    $sample = WritingSample::query()->sole();
    expect($sample->document_kind)->toBe(DocumentKind::StatementOfPurpose)
        ->and($sample->source)->toBe('upload')
        ->and($sample->content)->toContain('cholera map update in real time')
        ->and($sample->content)->toContain('Reach me at [email] or [phone], or see [link] and [link]')
        ->and($sample->content)->toContain('from 2019 - 2021')
        ->and($sample->content)->not->toContain('kwame.mensah@example.com')->not->toContain('555 0199')->not->toContain('linkedin')
        ->and($sample->redactions)->toEqual(['emails' => 1, 'links' => 2, 'phones' => 1])
        ->and($sample->word_count)->toBeGreaterThan(150)
        ->and($sample->rights_confirmed_at)->not->toBeNull()
        ->and($sample->created_by_admin_id)->toBe($admin->id);

    // Encrypted at rest; the uploaded file itself is not kept anywhere.
    $raw = (string) DB::table('writing_samples')->where('id', $sample->id)->value('content');
    expect($raw)->not->toContain('cholera')
        ->and(count(Storage::disk('private')->allFiles()))->toBe($filesBefore);

    $audit = AuditLog::query()->where('action', 'writing_sample.created')->sole();
    expect($audit->after)->toMatchArray(['title' => 'Public health SOP (2024 intake)', 'document_kind' => 'statement_of_purpose', 'source' => 'upload'])
        ->and(json_encode([$audit->before, $audit->after, $audit->meta]))->not->toContain('cholera');
});

it('creates a sample from pasted text', function () {
    actingAsAdmin(AdminRole::Ai);

    Livewire::test(CreateWritingSample::class)
        ->fillForm(sampleForm(['source' => 'pasted', 'pasted_text' => sampleText(), 'document_kind' => DocumentKind::MotivationLetter->value]))
        ->call('create')
        ->assertHasNoFormErrors();

    $sample = WritingSample::query()->sole();
    expect($sample->source)->toBe('pasted')
        ->and($sample->document_kind)->toBe(DocumentKind::MotivationLetter)
        ->and($sample->content)->toContain('[email]');
});

it('refuses a sample without permission confirmation, a disguised file or too little text', function () {
    actingAsAdmin(AdminRole::Ai);

    Livewire::test(CreateWritingSample::class)
        ->fillForm(sampleForm(['source' => 'pasted', 'pasted_text' => sampleText(), 'rights_confirmed' => false]))
        ->call('create')
        ->assertHasFormErrors(['rights_confirmed']);

    Livewire::test(CreateWritingSample::class)
        ->fillForm(sampleForm(['source' => 'upload', 'file' => UploadedFile::fake()->createWithContent('sop.pdf', 'MZ'.str_repeat("\0", 64))]))
        ->call('create')
        ->assertHasFormErrors(['file']);

    Livewire::test(CreateWritingSample::class)
        ->fillForm(sampleForm(['source' => 'upload', 'file' => UploadedFile::fake()->createWithContent('sop.pdf', "%PDF-1.4\n1 0 obj <<>> endobj\n")]))
        ->call('create')
        ->assertHasFormErrors(['file'])
        ->assertSee('This PDF appears to be damaged or incomplete.');

    Livewire::test(CreateWritingSample::class)
        ->fillForm(sampleForm(['source' => 'pasted', 'pasted_text' => 'Too short to teach anything.']))
        ->call('create')
        ->assertHasFormErrors(['pasted_text']);

    expect(WritingSample::query()->count())->toBe(0);
});

it('saves edited text with redactions applied again and audits the change without the text', function () {
    actingAsAdmin(AdminRole::Ai);
    $sample = WritingSample::query()->create([
        'title' => 'Engineering SOP', 'document_kind' => 'statement_of_purpose', 'content' => sampleText(),
        'word_count' => 180, 'source' => 'upload', 'redactions' => ['emails' => 1], 'rights_confirmed_at' => now(),
    ]);

    $edited = str_replace('Tamale', 'a northern town', sampleText()).' Questions to someone@example.org please.';

    Livewire::test(EditWritingSample::class, ['record' => $sample->getRouteKey()])
        ->assertFormSet(['content' => sampleText()])
        ->fillForm(['content' => $edited, 'is_active' => false, 'priority' => 5])
        ->call('save')
        ->assertHasNoFormErrors();

    $sample->refresh();
    expect($sample->content)->toContain('a northern town')->not->toContain('Tamale')->not->toContain('someone@example.org')
        ->and($sample->is_active)->toBeFalse()
        ->and($sample->priority)->toBe(5)
        ->and($sample->source)->toBe('upload')
        ->and($sample->redactions['emails'])->toBe(3);

    $audit = AuditLog::query()->where('action', 'writing_sample.updated')->sole();
    expect($audit->meta)->toBe(['text_changed' => true])
        ->and($audit->after)->toMatchArray(['is_active' => false, 'priority' => 5])
        ->and(json_encode([$audit->before, $audit->after]))->not->toContain('northern');
});

it('deletes a sample from the list and audits it', function () {
    actingAsAdmin(AdminRole::Ai);
    $sample = WritingSample::query()->create([
        'title' => 'Old CV', 'document_kind' => 'resume', 'content' => sampleText(), 'word_count' => 180, 'source' => 'pasted', 'rights_confirmed_at' => now(),
    ]);

    Livewire::test(ListWritingSamples::class)
        ->assertCanSeeTableRecords([$sample])
        ->callAction(TestAction::make(DeleteAction::getDefaultName())->table($sample));

    expect(WritingSample::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'writing_sample.deleted')->value('before'))->toMatchArray(['title' => 'Old CV']);
});
