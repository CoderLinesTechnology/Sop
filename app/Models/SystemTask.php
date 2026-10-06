<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/** Bookkeeping for one heartbeat task: when it last ran and how it went. */
#[Unguarded]
class SystemTask extends Model
{
    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_finished_at' => 'datetime',
        ];
    }

    /** Overdue by more than three intervals: the heartbeat is not getting enough traffic or pings. */
    public function isOverdue(): bool
    {
        return $this->last_started_at === null
            || $this->last_started_at->lt(now()->subSeconds($this->interval_seconds * 3));
    }
}
