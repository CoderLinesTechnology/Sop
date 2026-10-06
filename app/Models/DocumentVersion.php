<?php

namespace App\Models;

use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\QaResult;
use App\Domain\Documents\ResolvedRequirements;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A version of a document. `content` is the structured DocumentModel; the PDF
 * and DOCX are both rendered from it, so the two files always carry the same
 * approved text.
 */
#[Fillable([
    'uuid', 'document_id', 'order_id', 'revision_id', 'ai_job_id', 'version_number', 'source', 'status', 'title',
    'content', 'plain_text', 'word_count', 'char_count', 'char_count_no_spaces', 'page_count', 'language_variant',
    'document_template_id', 'template_snapshot', 'requirements_snapshot', 'quality_score', 'files_disk',
    'files_encrypted', 'pdf_path', 'pdf_size', 'pdf_sha256', 'docx_path', 'docx_size', 'docx_sha256',
    'pdf_filename', 'docx_filename', 'qa_status', 'qa_results', 'rendered_at', 'created_by_admin_id', 'notes',
])]
#[Hidden(['pdf_path', 'docx_path'])]
class DocumentVersion extends Model
{
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'template_snapshot' => 'array',
            'requirements_snapshot' => 'array',
            'qa_results' => 'array',
            'files_encrypted' => 'boolean',
            'quality_score' => 'decimal:2',
            'rendered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (DocumentVersion $version) => $version->uuid ??= (string) Str::uuid());
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function hasFiles(): bool
    {
        return filled($this->pdf_path) && filled($this->docx_path);
    }

    public function isDeliverable(): bool
    {
        return $this->hasFiles() && $this->qa_status === 'passed';
    }

    /** The approved content as a DocumentModel. */
    public function documentModel(): DocumentModel
    {
        return DocumentModel::fromArray((array) $this->content);
    }

    /** The requirements this version was written and validated against. */
    public function resolvedRequirements(): ResolvedRequirements
    {
        return ResolvedRequirements::fromArray((array) $this->requirements_snapshot);
    }

    public function qaResult(): ?QaResult
    {
        return $this->qa_results ? QaResult::fromArray((array) $this->qa_results) : null;
    }
}
