<?php

namespace App\Models;

use App\Enums\DocumentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An example document (SOP, motivation letter, essay, CV...) uploaded by an
 * administrator. The writing stages are shown a few matching samples as
 * style references: how strong documents are structured, paced and made
 * specific. Samples are never copied: their text reaches the model as
 * untrusted data, and passages repeated from a sample are removed by the
 * factual review.
 *
 * Only the text is kept (encrypted at rest, contact details redacted on
 * import); the uploaded file itself is discarded.
 */
#[Fillable([
    'title', 'document_kind', 'degree_level', 'field_of_study', 'country_code', 'notes', 'content', 'word_count',
    'source', 'redactions', 'priority', 'is_active', 'rights_confirmed_at', 'created_by_admin_id',
])]
#[Hidden(['content'])]
class WritingSample extends Model
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_PASTED = 'pasted';

    protected $attributes = [
        'priority' => 0,
        'is_active' => true,
        'word_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'document_kind' => DocumentKind::class,
            'content' => 'encrypted',
            'redactions' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'word_count' => 'integer',
            'rights_confirmed_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
