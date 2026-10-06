<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Qa\LibreOffice;
use App\Domain\Documents\Qa\PdfInspector;
use App\Domain\Documents\Renderers\PdfRenderer;
use App\Domain\Files\FileVault;
use App\Domain\Files\MalwareScanner;
use App\Domain\Files\UploadRejected;
use App\Domain\Files\UploadValidator;
use App\Models\AdminUser;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\Revision;
use App\Support\Audit;
use App\Support\SecurityLog;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Manual document overrides for administrators; every operation creates a new version and is audited. (Owned by the document engine.)
 *
 * Earlier versions are never modified, so every delivered file stays
 * reproducible. Generated versions (rerender, editText) get the full file
 * QA; uploaded files (replaceFile, uploadFinal) are validated by magic bytes,
 * malware-scanned and get the basic QA (they open, page count, page limit).
 */
class DocumentAdminOperations
{
    public function __construct(
        private readonly DocumentFactory $factory,
        private readonly DocumentRenderer $renderer,
        private readonly DocumentQa $qa,
        private readonly FileVault $vault,
        private readonly UploadValidator $validator,
        private readonly MalwareScanner $scanner,
        private readonly PdfRenderer $pdfRenderer,
        private readonly PdfInspector $pdfInspector,
        private readonly DocxImporter $importer,
        private readonly LibreOffice $libreOffice,
        private readonly RequirementResolver $requirementResolver,
        private readonly TemplateResolver $templateResolver,
    ) {}

    /**
     * Re-render the same content, optionally with another template (otherwise
     * the version's template as it is now). An explicitly chosen template keeps
     * its own formatting over country conventions; real requirements still apply.
     */
    public function rerender(DocumentVersion $version, ?DocumentTemplate $template, AdminUser $admin): DocumentVersion
    {
        $chosen = $template !== null || (bool) ($version->template_snapshot['template_chosen'] ?? false);
        $template ??= $this->templateFor($version);
        $requirements = $this->requirementsFor($version);

        $new = $this->factory->storeVersion(
            $version->order,
            DocumentModel::fromArray((array) $version->content),
            $this->factory->snapshot($version->order, $template, $requirements, preferTemplate: $chosen),
            $template->exists ? $template->getKey() : null,
            $requirements,
            'admin_rerender',
            revision: $version->revision,
            admin: $admin,
            qualityScore: $version->quality_score !== null ? (float) $version->quality_score : null,
        );
        $new = $this->renderAndValidate($new);

        Audit::log('document.rerendered', $new,
            ['version' => $version->version_number, 'template' => $version->template_snapshot['template_name'] ?? null],
            ['version' => $new->version_number, 'template' => $template->name, 'qa_status' => $new->qa_status],
            ['order' => $version->order->reference, 'from_version' => $version->uuid],
            $admin,
        );

        return $new;
    }

    /** New version with administrator-edited content, same template and requirements. */
    public function editText(DocumentVersion $version, DocumentModel $model, AdminUser $admin): DocumentVersion
    {
        $new = $this->factory->createVersion(
            $version->order,
            $model,
            $this->templateFor($version),
            $this->requirementsFor($version),
            'admin_edit',
            revision: $version->revision,
            admin: $admin,
        );
        $new = $this->renderAndValidate($new);

        Audit::log('document.text_edited', $new,
            ['version' => $version->version_number, 'word_count' => $version->word_count, 'char_count' => $version->char_count],
            ['version' => $new->version_number, 'word_count' => $new->word_count, 'char_count' => $new->char_count, 'qa_status' => $new->qa_status],
            ['order' => $version->order->reference, 'from_version' => $version->uuid],
            $admin,
        );

        return $new;
    }

    /**
     * Replace one file of a version with an administrator's upload. The new
     * version keeps the other file (copied) and the content of the original.
     *
     * $format: "pdf" or "docx".
     *
     * @throws UploadRejected when the upload is not a valid, safe file of that type
     */
    public function replaceFile(DocumentVersion $version, string $format, UploadedFile $file, AdminUser $admin): DocumentVersion
    {
        $format = strtolower($format);
        if (! in_array($format, ['pdf', 'docx'], true)) {
            throw new InvalidArgumentException('The format must be "pdf" or "docx".');
        }
        if (! $version->hasFiles()) {
            throw new RuntimeException('This version has no rendered files to replace; upload a final document instead.');
        }

        $upload = $this->acceptUpload($file, $format, $version->order);
        $other = $format === 'pdf' ? 'docx' : 'pdf';
        $otherBytes = $this->vault->get($version->{$other.'_path'}, $version->files_disk, (bool) $version->files_encrypted);

        $new = $this->factory->storeVersion(
            $version->order,
            DocumentModel::fromArray((array) $version->content),
            (array) $version->template_snapshot,
            $version->document_template_id,
            $this->requirementsFor($version),
            'admin_upload',
            revision: $version->revision,
            admin: $admin,
            notes: strtoupper($format)." replaced by an administrator upload (based on version {$version->version_number}).",
        );
        $this->attachFiles($new, [$format => $upload['contents'], $other => $otherBytes]);
        $this->qa->validate($new);
        $new->refresh();

        Audit::log('document.file_replaced', $new,
            ['version' => $version->version_number, $format.'_sha256' => $version->{$format.'_sha256'}],
            ['version' => $new->version_number, $format.'_sha256' => $new->{$format.'_sha256'}, 'qa_status' => $new->qa_status],
            ['order' => $version->order->reference, 'format' => $format, 'file_name' => $upload['name'], 'from_version' => $version->uuid],
            $admin,
        );

        return $new;
    }

    /**
     * Upload the final document: a DOCX and, optionally, its PDF. Without a
     * PDF one is produced from the DOCX: converted by LibreOffice when it is
     * enabled, otherwise rendered from the DOCX's text with the order's
     * formatting (so both files carry the same text).
     *
     * @throws UploadRejected when an upload is not a valid, safe file of its type
     */
    public function uploadFinal(Order $order, UploadedFile $docx, ?UploadedFile $pdf, AdminUser $admin, ?Revision $revision = null): DocumentVersion
    {
        $docxUpload = $this->acceptUpload($docx, 'docx', $order);
        $pdfUpload = $pdf ? $this->acceptUpload($pdf, 'pdf', $order) : null;

        $latest = $order->documentVersions()->first();
        $requirements = $latest?->requirements_snapshot
            ? ResolvedRequirements::fromArray((array) $latest->requirements_snapshot)
            : $this->requirementResolver->resolve($order);

        if ($latest?->template_snapshot) {
            $snapshot = TemplateSnapshot::normalize((array) $latest->template_snapshot);
            $templateId = $latest->document_template_id;
        } else {
            $template = $this->templateResolver->resolve($order, $requirements);
            $snapshot = $this->factory->snapshot($order, $template, $requirements);
            $templateId = $template->exists ? $template->getKey() : null;
        }

        $latestModel = $latest ? DocumentModel::fromArray((array) $latest->content) : null;
        try {
            $import = $this->importer->import(
                $docxUpload['contents'],
                $latestModel?->title ?: DocumentFactory::documentType($order),
                $requirements->languageVariant,
                $latestModel?->applicantName ?: ($order->applicant_name ?: $order->customer_name),
            );
        } catch (RuntimeException $e) {
            throw new UploadRejected($e->getMessage(), 'docx_unreadable');
        }

        // Formatting mirrors the upload: a PDF rendered from it shows only what the DOCX shows.
        $snapshot['show_title'] = $snapshot['show_title'] && $import['has_title'];
        if (! $import['has_applicant_name'] && in_array($snapshot['applicant_name_position'], ['below_title', 'above_title'], true)) {
            $snapshot['applicant_name_position'] = 'none';
        }

        if ($pdfUpload) {
            [$pdfBytes, $pdfSource] = [$pdfUpload['contents'], 'uploaded'];
        } elseif ($this->libreOffice->enabled() && ($converted = $this->libreOffice->docxToPdf($docxUpload['contents'])) !== null) {
            [$pdfBytes, $pdfSource] = [$converted, 'converted from the DOCX with LibreOffice'];
        } else {
            [$pdfBytes, $pdfSource] = [$this->pdfRenderer->render(DocumentLayout::make($import['model'], $snapshot))['bytes'], 'rendered from the DOCX text'];
        }

        $new = $this->factory->storeVersion(
            $order, $import['model'], $snapshot, $templateId, $requirements, 'admin_upload',
            revision: $revision,
            admin: $admin,
            notes: "Final document uploaded by an administrator; PDF {$pdfSource}.",
        );
        $this->attachFiles($new, ['pdf' => $pdfBytes, 'docx' => $docxUpload['contents']]);
        $this->qa->validate($new);
        $new->refresh();

        Audit::log('document.final_uploaded', $new, null,
            ['version' => $new->version_number, 'pdf' => $pdfSource, 'qa_status' => $new->qa_status],
            ['order' => $order->reference, 'docx_name' => $docxUpload['name'], 'pdf_name' => $pdfUpload['name'] ?? null, 'revision' => $revision?->number],
            $admin,
        );

        return $new;
    }

    private function renderAndValidate(DocumentVersion $version): DocumentVersion
    {
        try {
            $version = $this->renderer->render($version);
        } catch (Throwable $e) {
            $version->forceFill([
                'status' => 'failed',
                'notes' => trim($version->notes."\nRendering failed: ".mb_substr($e->getMessage(), 0, 300)),
            ])->save();

            throw $e;
        }

        $this->qa->validate($version);

        return $version->refresh();
    }

    /** Validate (magic bytes, size, DOCX safety) and malware-scan an administrator upload. */
    private function acceptUpload(UploadedFile $file, string $format, Order $order): array
    {
        $clean = $this->validator->validate($file, [$format]);

        $scan = $this->scanner->scan($clean['contents']);
        if ($scan['status'] === 'infected') {
            SecurityLog::record('malware_upload', 'high', ['signature' => $scan['result'], 'name' => $clean['name'], 'context' => 'admin_document_upload'], $order);

            throw new UploadRejected('This file was blocked by the security scan.', 'infected');
        }
        if ($scan['status'] === 'error' && config('statementra.scanning.fail_closed')) {
            throw new UploadRejected('The file could not be scanned right now. Please try again shortly.', 'scan_unavailable');
        }

        return $clean;
    }

    /** @param array{pdf:string, docx:string} $files */
    private function attachFiles(DocumentVersion $version, array $files): void
    {
        $pdf = $this->vault->put('documents', $files['pdf'], 'pdf');
        try {
            $docx = $this->vault->put('documents', $files['docx'], 'docx');
        } catch (Throwable $e) {
            $this->vault->delete($pdf['path'], $pdf['disk']);

            throw $e;
        }

        $snapshot = (array) $version->template_snapshot;
        $applicant = DocumentModel::fromArray((array) $version->content)->applicantName;
        $pages = $this->pdfInspector->inspect($files['pdf'])['pages'] ?: null;

        $version->forceFill([
            'files_disk' => $pdf['disk'],
            'files_encrypted' => $pdf['encrypted'],
            'pdf_path' => $pdf['path'],
            'pdf_size' => $pdf['size'],
            'pdf_sha256' => $pdf['sha256'],
            'docx_path' => $docx['path'],
            'docx_size' => $docx['size'],
            'docx_sha256' => $docx['sha256'],
            'pdf_filename' => FileNamer::filename($snapshot, $applicant, 'pdf'),
            'docx_filename' => FileNamer::filename($snapshot, $applicant, 'docx'),
            'page_count' => $pages,
            'rendered_at' => now(),
        ])->save();
    }

    /** The version's template as it is now, or its snapshot if the template was deleted. */
    private function templateFor(DocumentVersion $version): DocumentTemplate
    {
        return ($version->document_template_id ? DocumentTemplate::query()->find($version->document_template_id) : null)
            ?? TemplateSnapshot::toTemplate((array) $version->template_snapshot);
    }

    private function requirementsFor(DocumentVersion $version): ResolvedRequirements
    {
        return filled($version->requirements_snapshot)
            ? ResolvedRequirements::fromArray((array) $version->requirements_snapshot)
            : $this->requirementResolver->resolve($version->order);
    }
}
