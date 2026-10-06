<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned prompt. Exactly one version per prompt key is "active" (enforced
 * by a unique index on a generated column). New versions start as drafts and
 * only change production behaviour when an administrator deliberately
 * activates them; the AI never edits prompts itself.
 */
#[Fillable(['prompt_key', 'version', 'label', 'description', 'system_prompt', 'user_template', 'model', 'reasoning_effort', 'status', 'created_by_admin_id', 'activated_by_admin_id', 'activated_at'])]
class PromptVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'version' => 'integer'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'activated_by_admin_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public static function activeFor(string $key): ?self
    {
        return static::query()->where('prompt_key', $key)->where('status', self::STATUS_ACTIVE)->first();
    }

    public static function nextVersionNumber(string $key): int
    {
        return (int) static::query()->where('prompt_key', $key)->max('version') + 1;
    }
}
