<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of sensitive actions. Written only through App\Support\Audit. */
#[Table(timestamps: false)]
#[Fillable(['admin_user_id', 'actor_type', 'actor_label', 'action', 'target_type', 'target_id', 'target_label', 'before', 'after', 'meta', 'ip_address', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
