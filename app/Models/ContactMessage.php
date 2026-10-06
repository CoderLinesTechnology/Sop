<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Support request from the contact form (optionally referencing an order). */
#[Fillable(['name', 'email', 'order_reference', 'order_id', 'subject', 'message', 'status', 'ip_address', 'user_agent', 'handled_by_admin_id', 'handled_at'])]
class ContactMessage extends Model
{
    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'handled_by_admin_id');
    }
}
