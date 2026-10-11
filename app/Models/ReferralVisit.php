<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily visit count of one referrer's link (one row per referrer and day,
 * incremented with an upsert). No visitor data is stored. The date is kept
 * as a plain 'Y-m-d' string: it is never cast, so SQLite and MySQL compare it
 * the same way.
 */
#[WithoutTimestamps]
#[Fillable(['referrer_id', 'date', 'visits'])]
class ReferralVisit extends Model
{
    protected function casts(): array
    {
        return ['visits' => 'integer'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class);
    }
}
