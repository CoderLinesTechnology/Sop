<?php

namespace App\Filament\Resources\WritingSamples;

use App\Domain\Ai\Samples\WritingSampleImporter;
use App\Domain\Files\UploadRejected;
use App\Models\WritingSample;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Form-data helpers for the writing sample pages: which keys are stored,
 * what the audit log records (never the text itself), and the import of an
 * uploaded file or pasted text through WritingSampleImporter.
 */
final class WritingSampleData
{
    /** Columns the form edits directly. */
    public const FIELDS = ['title', 'document_kind', 'degree_level', 'field_of_study', 'country_code', 'notes', 'priority', 'is_active'];

    public const AUDITED = ['title', 'document_kind', 'degree_level', 'field_of_study', 'country_code', 'priority', 'is_active', 'word_count', 'source'];

    /** Upload limit for a sample file. */
    public const MAX_UPLOAD_KB = 10 * 1024;

    public const ACCEPTED_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
        'text/plain',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(self::FIELDS));

        foreach (['title', 'field_of_study', 'notes'] as $key) {
            if (array_key_exists($key, $fields)) {
                $fields[$key] = filled($fields[$key]) ? trim((string) $fields[$key]) : null;
            }
        }
        $fields['country_code'] = filled($fields['country_code'] ?? null) ? strtoupper((string) $fields['country_code']) : null;
        $fields['degree_level'] = filled($fields['degree_level'] ?? null) ? (string) $fields['degree_level'] : null;
        $fields['priority'] = (int) ($fields['priority'] ?? 0);

        return $fields;
    }

    /**
     * Import the text from an upload (when present) or from text.
     *
     * @return array{content:string, word_count:int, redactions:array<string,int>, source:string}
     *
     * @throws ValidationException on the given field when the file or text is refused
     */
    public static function import(mixed $upload, ?string $text, string $fileField, string $textField): array
    {
        $importer = app(WritingSampleImporter::class);
        $file = self::uploaded($upload);

        try {
            return $file
                ? $importer->fromUpload($file) + ['source' => WritingSample::SOURCE_UPLOAD]
                : $importer->fromText((string) $text) + ['source' => WritingSample::SOURCE_PASTED];
        } catch (UploadRejected $e) {
            throw ValidationException::withMessages(['data.'.($file ? $fileField : $textField) => $e->getMessage()]);
        } finally {
            // Only the text is kept: remove Livewire's temporary copy of the file now, accepted
            // or not, instead of leaving it until the temporary-upload cleanup runs. Pages
            // clear the field when the import fails, so the file is chosen again.
            if ($file instanceof TemporaryUploadedFile) {
                $file->delete();
            }
        }
    }

    /** A short description of where the text came from, for the edit page. */
    public static function summary(WritingSample $sample): string
    {
        $parts = [number_format($sample->word_count).' words'];
        $parts[] = $sample->source === WritingSample::SOURCE_UPLOAD ? 'imported from a file' : 'pasted text';

        $redacted = [];
        foreach ((array) $sample->redactions as $kind => $count) {
            $redacted[] = $count.' '.($count === 1 ? rtrim((string) $kind, 's') : $kind);
        }
        $parts[] = $redacted !== [] ? 'removed automatically: '.implode(', ', $redacted) : 'no contact details found';

        if ($sample->rights_confirmed_at) {
            $by = $sample->rightsConfirmedBy()->value('name');
            $parts[] = 'permission confirmed '.$sample->rights_confirmed_at->format('j M Y').($by ? ' by '.$by : '');
        }

        return implode(' · ', $parts);
    }

    /** The uploaded file from a FileUpload field (temporary uploads only; stored paths are never trusted). */
    private static function uploaded(mixed $state): ?UploadedFile
    {
        $file = is_array($state) ? collect($state)->first(fn ($item) => $item instanceof UploadedFile) : $state;

        return $file instanceof UploadedFile ? $file : null;
    }
}
