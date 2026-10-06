<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A country, platform, institution or programme requirement (word limits,
 * language convention, formatting, required sections...). Rules are data,
 * maintained by administrators, each with its source and verification date.
 */
#[Unguarded]
class RequirementRule extends Model
{
    public const SCOPES = [
        'country' => 'Country convention',
        'platform' => 'Application platform',
        'institution' => 'Institution',
        'programme' => 'Programme',
        'scholarship' => 'Scholarship',
    ];

    /** Specificity used when merging rules: higher wins. */
    public const SPECIFICITY = [
        'country' => 1,
        'platform' => 2,
        'institution' => 3,
        'scholarship' => 3,
        'programme' => 4,
    ];

    protected function casts(): array
    {
        return [
            'document_kinds' => 'array',
            'file_types' => 'array',
            'required_sections' => 'array',
            'prohibited_content' => 'array',
            'last_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'font_size' => 'float',
            'margins_mm' => 'float',
            'line_spacing' => 'float',
        ];
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verified_by_admin_id');
    }

    public function specificity(): int
    {
        return self::SPECIFICITY[$this->scope] ?? 0;
    }
}
