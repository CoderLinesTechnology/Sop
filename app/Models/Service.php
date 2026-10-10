<?php

namespace App\Models;

use App\Enums\DocumentKind;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A purchasable service. Everything about it (copy, price, questions, upload
 * slots, AI workflow, formatting template, revision policy) is managed by
 * administrators; the public site renders whatever is active here.
 * Soft deletion is used as "archive" so historical orders keep their service.
 */
#[Unguarded]
class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'card_features' => 'array',
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'revision_fee' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'revisions_included' => 'integer',
            'revision_window_days' => 'integer',
            'default_word_limit' => 'integer',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ServiceField::class)->orderBy('display_order')->orderBy('id');
    }

    public function activeFields(): HasMany
    {
        return $this->fields()->where('is_active', true);
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class)->where('scope', 'service');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AiWorkflow::class, 'ai_workflow_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class);
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('display_order')->orderBy('id');
    }

    public function kind(): DocumentKind
    {
        return DocumentKind::tryFrom((string) $this->document_kind) ?? DocumentKind::Custom;
    }

    /** @return array{0:int,1:int} */
    public function deliveryWindow(): array
    {
        $min = $this->delivery_min_minutes ?: (int) Settings::get('orders.delivery_min_minutes', 10);
        $max = $this->delivery_max_minutes ?: (int) Settings::get('orders.delivery_max_minutes', 15);

        return [min($min, $max), max($min, $max)];
    }

    public function deliveryLabel(): string
    {
        [$min, $max] = $this->deliveryWindow();

        return Settings::formatMinutesRange($min, $max);
    }
}
