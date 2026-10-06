<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Renderers\DocxRenderer;
use App\Domain\Documents\Renderers\PdfRenderer;
use App\Domain\Files\FileVault;
use App\Models\DocumentVersion;
use RuntimeException;
use Throwable;

/**
 * Renders the PDF and DOCX of a version from its DocumentModel and template snapshot. (Owned by the document engine.)
 *
 * Both files come from the same DocumentLayout, so they always carry the
 * same text. Files are encrypted into the private vault under random paths;
 * the version records paths, sizes, checksums, clean download names, the
 * PDF's page count and when it was rendered.
 */
class DocumentRenderer
{
    public function __construct(
        private readonly FileVault $vault,
        private readonly PdfRenderer $pdfRenderer,
        private readonly DocxRenderer $docxRenderer,
    ) {}

    public function render(DocumentVersion $version): DocumentVersion
    {
        $model = DocumentModel::fromArray((array) $version->content);
        if ($model->blocks === []) {
            throw new RuntimeException("Document version {$version->uuid} has no content to render.");
        }
        $snapshot = TemplateSnapshot::normalize((array) $version->template_snapshot);
        $layout = DocumentLayout::make($model, $snapshot);

        // Render both before storing either, so a failure never leaves a half-rendered version.
        $pdf = $this->pdfRenderer->render($layout);
        $docx = $this->docxRenderer->render($layout);

        $storedPdf = $this->vault->put('documents', $pdf['bytes'], 'pdf');
        try {
            $storedDocx = $this->vault->put('documents', $docx, 'docx');
        } catch (Throwable $e) {
            $this->vault->delete($storedPdf['path'], $storedPdf['disk']);

            throw $e;
        }

        $previous = $version->hasFiles() ? [$version->files_disk, $version->pdf_path, $version->docx_path] : null;

        $version->forceFill([
            'files_disk' => $storedPdf['disk'],
            'files_encrypted' => $storedPdf['encrypted'],
            'pdf_path' => $storedPdf['path'],
            'pdf_size' => $storedPdf['size'],
            'pdf_sha256' => $storedPdf['sha256'],
            'docx_path' => $storedDocx['path'],
            'docx_size' => $storedDocx['size'],
            'docx_sha256' => $storedDocx['sha256'],
            'pdf_filename' => FileNamer::filename($snapshot, $model->applicantName, 'pdf'),
            'docx_filename' => FileNamer::filename($snapshot, $model->applicantName, 'docx'),
            'page_count' => $pdf['page_count'],
            'rendered_at' => now(),
            // New files have not been validated yet.
            'qa_status' => null,
            'qa_results' => null,
        ])->save();

        if ($previous !== null) {
            [$disk, $pdfPath, $docxPath] = $previous;
            $this->vault->delete($pdfPath, $disk);
            $this->vault->delete($docxPath, $disk);
        }

        return $version->refresh();
    }
}
