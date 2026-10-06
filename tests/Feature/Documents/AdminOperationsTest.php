<?php

use App\Domain\Documents\DocumentAdminOperations;
use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\Qa\DocxInspector;
use App\Domain\Documents\Renderers\DocxRenderer;
use App\Domain\Documents\Renderers\PdfRenderer;
use App\Domain\Documents\TemplateSnapshot;
use App\Domain\Files\UploadRejected;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Feature\Documents\Fixtures;

beforeEach(function () {
    Fixtures::isolatePrivateDisk();
    Fixtures::seed();
    $this->admin = AdminUser::factory()->create(['name' => 'Ada Admin']);
    $this->operations = app(DocumentAdminOperations::class);
});

afterEach(fn () => Fixtures::removePrivateDisk());

it('re-renders a version as a new, validated and audited version', function () {
    $original = Fixtures::rendered(Fixtures::order());
    $new = $this->operations->rerender($original, null, $this->admin);

    $audit = AuditLog::query()->where('action', 'document.rerendered')->sole();

    expect($new->id)->not->toBe($original->id)
        ->and($new->version_number)->toBe(2)
        ->and($new->source)->toBe('admin_rerender')
        ->and($new->created_by_admin_id)->toBe($this->admin->id)
        ->and($new->content)->toEqual($original->content)
        ->and($new->qa_status)->toBe('passed')
        ->and($new->pdf_path)->not->toBe($original->pdf_path)
        ->and($original->fresh()->pdf_path)->toBe($original->pdf_path) // earlier versions are never modified
        ->and($audit->admin_user_id)->toBe($this->admin->id)
        ->and($audit->target_id)->toBe((string) $new->id)
        ->and($audit->before['version'])->toBe(1)
        ->and($audit->after['version'])->toBe(2);
});

it('re-renders with another template', function () {
    $original = Fixtures::rendered(Fixtures::order());
    $letter = DocumentTemplate::query()->where('slug', 'us-letter')->sole();

    $new = $this->operations->rerender($original, $letter, $this->admin);

    expect($new->document_template_id)->toBe($letter->id)
        ->and($new->template_snapshot['page_size'])->toBe('Letter')
        ->and($new->template_snapshot['template_chosen'])->toBeTrue()
        ->and($new->template_snapshot['ignored_overrides'])->toHaveKey('page_size') // the A4 country convention
        ->and(Fixtures::pdfInfo(Fixtures::pdfBytes($new)))->toContain('(letter)')
        ->and($new->qa_status)->toBe('passed');

    // Re-rendering that version again keeps the administrator's choice.
    expect($this->operations->rerender($new, null, $this->admin)->template_snapshot['page_size'])->toBe('Letter');
});

it('re-renders from the snapshot when the template no longer exists', function () {
    $original = Fixtures::rendered(Fixtures::order());
    DocumentTemplate::query()->whereKey($original->document_template_id)->delete();

    $new = $this->operations->rerender($original->fresh(), null, $this->admin);

    expect($new->document_template_id)->toBeNull()
        ->and($new->template_snapshot['font_family'])->toBe('Times New Roman')
        ->and($new->qa_status)->toBe('passed');
});

it('creates a new version from administrator-edited text', function () {
    $original = Fixtures::rendered(Fixtures::order());
    $edited = DocumentModel::fromArray(['blocks' => [
        ['type' => 'paragraph', 'text' => 'An administrator rewrote this opening paragraph to fix a factual detail about the internship in Accra.'],
        ...array_slice($original->documentModel()->blocks, 1),
    ]] + $original->documentModel()->toArray());

    $new = $this->operations->editText($original, $edited, $this->admin);
    $audit = AuditLog::query()->where('action', 'document.text_edited')->sole();

    expect($new->source)->toBe('admin_edit')
        ->and($new->version_number)->toBe(2)
        ->and($new->qa_status)->toBe('passed')
        ->and(Fixtures::pdfText(Fixtures::pdfBytes($new)))->toContain('An administrator rewrote this opening paragraph')
        ->and($new->word_count)->toBe($edited->wordCount())
        ->and($audit->before['word_count'])->toBe($original->word_count)
        ->and($audit->after['word_count'])->toBe($new->word_count);
});

it('replaces one file with a validated administrator upload', function () {
    $original = Fixtures::rendered(Fixtures::order());
    $layout = DocumentLayout::make($original->documentModel(), $original->template_snapshot);
    $replacement = app(PdfRenderer::class)->render($layout)['bytes'];

    $new = $this->operations->replaceFile($original, 'pdf', UploadedFile::fake()->createWithContent('fixed statement.pdf', $replacement), $this->admin);

    expect($new->source)->toBe('admin_upload')
        ->and($new->version_number)->toBe(2)
        ->and($new->pdf_sha256)->toBe(hash('sha256', $replacement))
        ->and($new->docx_sha256)->toBe($original->docx_sha256) // the other file is carried over
        ->and($new->docx_path)->not->toBe($original->docx_path)  // ...as its own copy
        ->and($new->pdf_filename)->toBe('Daniel_Essel_Statement_of_Purpose.pdf')
        ->and($new->page_count)->toBe($original->page_count)
        ->and($new->qa_status)->toBe('passed')
        ->and($new->qa_results['mode'])->toBe('basic')
        ->and(AuditLog::query()->where('action', 'document.file_replaced')->sole()->meta['format'])->toBe('pdf');
});

it('rejects replacement files whose contents do not match their type', function () {
    $original = Fixtures::rendered(Fixtures::order());

    expect(fn () => $this->operations->replaceFile($original, 'pdf', UploadedFile::fake()->createWithContent('statement.pdf', "MZ\x90\x00 not a pdf"), $this->admin))
        ->toThrow(UploadRejected::class);

    $macro = Fixtures::withDocxPart(Fixtures::docxBytes($original), 'word/vbaProject.bin', fn () => 'macro');
    expect(fn () => $this->operations->replaceFile($original, 'docx', UploadedFile::fake()->createWithContent('statement.docx', $macro), $this->admin))
        ->toThrow(UploadRejected::class);

    expect(fn () => $this->operations->replaceFile($original, 'txt', UploadedFile::fake()->createWithContent('statement.txt', 'text'), $this->admin))
        ->toThrow(InvalidArgumentException::class)
        ->and($original->document->versions()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'document.file_replaced')->count())->toBe(0);
});

it('accepts a final DOCX and renders its PDF from the same text', function () {
    $order = Fixtures::order();
    Fixtures::rendered($order);

    // A DOCX edited outside the system: no Title style, a bold centred first line, plain paragraphs.
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addText('Statement of Purpose', ['bold' => true, 'size' => 14], ['alignment' => 'center']);
    $section->addText('Academic preparation', ['bold' => true], ['styleName' => null]);
    $section->addText(Fixtures::paragraph(4));
    $section->addText(Fixtures::paragraph(4, 4));
    $path = storage_path('app/tmp/final-'.uniqid().'.docx');
    @mkdir(dirname($path), 0700, true);
    IOFactory::createWriter($word, 'Word2007')->save($path);
    $docx = file_get_contents($path);
    unlink($path);

    $version = $this->operations->uploadFinal($order, UploadedFile::fake()->createWithContent('Final SOP.docx', $docx), null, $this->admin);
    $model = $version->documentModel();
    $docxText = collect(app(DocxInspector::class)->paragraphs(Fixtures::docxPart(Fixtures::docxBytes($version), 'word/document.xml')))->pluck('text')->implode("\n");

    expect($version->source)->toBe('admin_upload')
        ->and($version->version_number)->toBe(2)
        ->and($version->docx_sha256)->toBe(hash('sha256', $docx)) // stored exactly as uploaded
        ->and($model->title)->toBe('Statement of Purpose')
        ->and(array_column($model->blocks, 'text'))->toBe(['Academic preparation', Fixtures::paragraph(4), Fixtures::paragraph(4, 4)])
        ->and(Fixtures::compact(Fixtures::pdfText(Fixtures::pdfBytes($version), ['-raw'])))->toContain(Fixtures::compact($docxText))
        ->and($version->notes)->toContain('rendered from the DOCX text')
        ->and($version->qa_status)->toBe('passed')
        ->and($version->isDeliverable())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'document.final_uploaded')->sole()->admin_user_id)->toBe($this->admin->id);
});

it('accepts a final DOCX with its PDF for a revision', function () {
    $order = Fixtures::order();
    $original = Fixtures::rendered($order);
    $revision = $order->revisions()->create(['number' => 1, 'request_text' => 'Shorter please', 'status' => 'processing', 'mode' => 'manual', 'currency' => 'USD', 'requested_at' => now()]);
    $layout = DocumentLayout::make($original->documentModel(), $original->template_snapshot);
    $docx = app(DocxRenderer::class)->render($layout);
    $pdf = app(PdfRenderer::class)->render($layout)['bytes'];

    $version = $this->operations->uploadFinal($order, UploadedFile::fake()->createWithContent('final.docx', $docx), UploadedFile::fake()->createWithContent('final.pdf', $pdf), $this->admin, $revision);

    expect($version->revision_id)->toBe($revision->id)
        ->and($version->pdf_sha256)->toBe(hash('sha256', $pdf))
        ->and($version->documentModel()->title)->toBe('Statement of Purpose')
        ->and($version->documentModel()->applicantName)->toBe('Daniel Essel')
        ->and(array_column($version->documentModel()->blocks, 'text'))->toBe(array_column($original->documentModel()->blocks, 'text'))
        ->and($version->qa_status)->toBe('passed');
});

it('converts a final DOCX with LibreOffice when enabled', function () {
    config(['statementra.documents.verify_docx_with_libreoffice' => true]);
    $order = Fixtures::order();
    $layout = DocumentLayout::make(Fixtures::model(), TemplateSnapshot::defaults());

    $version = $this->operations->uploadFinal($order, UploadedFile::fake()->createWithContent('final.docx', app(DocxRenderer::class)->render($layout)), null, $this->admin);

    expect($version->notes)->toContain('LibreOffice')
        ->and(Fixtures::pdfInfo(Fixtures::pdfBytes($version)))->toContain('LibreOffice') // its producer string
        ->and($version->qa_status)->toBe('passed');
})->skip(fn () => ! is_executable('/usr/bin/soffice'), 'LibreOffice is not installed.');
