<?php

namespace Tests\Unit\Documents;

use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\TemplateSnapshot;
use App\Models\DocumentTemplate;
use Symfony\Component\Process\Process;

/** Template snapshots and models for unit tests (no database). */
final class Samples
{
    public static function snapshot(array $overrides = [], array $context = []): array
    {
        return TemplateSnapshot::normalize($overrides + ['context' => $context + ['document_type' => 'Statement of Purpose']]);
    }

    /** An unsaved template (no database needed). */
    public static function template(array $attributes = []): DocumentTemplate
    {
        return (new DocumentTemplate)->forceFill($attributes + ['name' => 'Standard', 'slug' => 'standard', 'font_family' => 'Times New Roman', 'page_size' => 'A4']);
    }

    public static function model(array $overrides = []): DocumentModel
    {
        return DocumentModel::fromArray($overrides + [
            'title' => 'Statement of Purpose',
            'subtitle' => 'MSc Computer Science',
            'applicant_name' => 'Daniel Essel',
            'blocks' => [
                ['type' => 'heading', 'text' => 'Background'],
                ['type' => 'paragraph', 'text' => 'First paragraph.'],
                ['type' => 'paragraph', 'text' => 'Second paragraph.'],
            ],
        ]);
    }

    /** pdftotext in drawing order (hyphens kept). */
    public static function pdftotext(string $pdf): string
    {
        $path = sys_get_temp_dir().'/unit-'.bin2hex(random_bytes(6)).'.pdf';
        file_put_contents($path, $pdf);
        try {
            return (new Process(['/usr/bin/pdftotext', '-raw', '-enc', 'UTF-8', $path, '-']))->mustRun()->getOutput();
        } finally {
            unlink($path);
        }
    }
}
