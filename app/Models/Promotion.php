<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A time-boxed promotional campaign (limited-time offer, early-bird, seasonal
 * sale). Start and end times are server timestamps; the countdown shown to
 * customers is derived from them and the discount is always recalculated by
 * the backend at checkout.
 */
#[Unguarded]
class Promotion extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'percent_off' => 'decimal:2',
            'amount_off' => 'integer',
            'max_discount_amount' => 'integer',
            'applies_to_all_services' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'show_countdown' => 'boolean',
            'show_banner' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    public function scopeRunning(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function isRunning(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    public function appliesTo(Service $service): bool
    {
        if ($this->applies_to_all_services) {
            return true;
        }

        return $this->relationLoaded('services')
            ? $this->services->contains('id', $service->id)
            : $this->services()->whereKey($service->id)->exists();
    }
}
