<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A customer order. Status and payment status change only through
 * OrderStateMachine and PaymentConfirmationService; prices only through the
 * PriceCalculator. Route key is the ULID public_id, never the internal id.
 */
#[RouteKey('public_id')]
#[Fillable([
    'service_id', 'user_id', 'email', 'customer_name', 'customer_phone', 'applicant_name',
    'currency', 'subtotal_amount', 'promotion_id', 'promotion_discount', 'coupon_id', 'coupon_code',
    'coupon_discount', 'total_amount', 'pricing_snapshot', 'service_snapshot', 'institution', 'programme',
    'degree_level', 'country_code', 'intake', 'deadline', 'essay_prompt', 'word_limit', 'language_variant',
    'checkout_token_hash', 'revisions_allowed', 'revision_deadline_at', 'ip_address', 'user_agent', 'utm',
    'create_account', 'retention_until',
])]
class Order extends Model
{
    use HasFactory;

    protected $attributes = [
        'status' => 'NEW',
        'payment_status' => 'UNPAID',
        'promotion_discount' => 0,
        'coupon_discount' => 0,
        'access_version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal_amount' => 'integer',
            'promotion_discount' => 'integer',
            'coupon_discount' => 'integer',
            'total_amount' => 'integer',
            'pricing_snapshot' => 'array',
            'service_snapshot' => 'array',
            'risk_flags' => 'array',
            'utm' => 'array',
            'deadline' => 'date',
            'create_account' => 'boolean',
            'fulfillment_started_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'estimated_ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paused_at' => 'datetime',
            'delay_notified_at' => 'datetime',
            'needs_info_at' => 'datetime',
            'revision_deadline_at' => 'datetime',
            'retention_until' => 'datetime',
            'data_purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->public_id ??= (string) Str::ulid();
            $order->reference ??= self::generateReference();
            $order->email = Str::lower(trim((string) $order->email));
        });
    }

    /** Human-friendly, non-sequential reference such as ST-7KQ3-M9XD (40 random bits). */
    public static function generateReference(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32
        do {
            $chars = '';
            foreach (str_split(random_bytes(8)) as $byte) {
                $chars .= $alphabet[ord($byte) & 31];
            }
            $reference = 'ST-'.substr($chars, 0, 4).'-'.substr($chars, 4, 4);
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    // ----------------------------------------------------------------- relations

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function answers(): HasMany
    {
        return $this->hasMany(OrderAnswer::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->latest();
    }

    public function files(): HasMany
    {
        return $this->hasMany(UploadedFile::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function orderPayments(): HasMany
    {
        return $this->payments()->where('purpose', 'order');
    }

    public function successfulPayment(): HasOne
    {
        return $this->hasOne(Payment::class)
            ->where('purpose', 'order')
            ->whereIn('status', [PaymentRecordStatus::Success->value, PaymentRecordStatus::Waived->value, PaymentRecordStatus::PartiallyRefunded->value, PaymentRecordStatus::Refunded->value])
            ->latestOfMany();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class)->orderBy('number');
    }

    public function couponRedemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiJob::class);
    }

    public function latestAiJob(): HasOne
    {
        return $this->hasOne(AiJob::class)->latestOfMany();
    }

    public function applicant(): HasOne
    {
        return $this->hasOne(Applicant::class);
    }

    public function informationRequests(): HasMany
    {
        return $this->hasMany(InformationRequest::class)->latest('requested_at');
    }

    public function openInformationRequest(): HasOne
    {
        return $this->hasOne(InformationRequest::class)->where('status', 'open')->latestOfMany('requested_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function document(): HasOne
    {
        return $this->hasOne(Document::class)->latestOfMany();
    }

    public function documentVersions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function researchSources(): HasMany
    {
        return $this->hasMany(ResearchSource::class);
    }

    public function researchClaims(): HasMany
    {
        return $this->hasMany(ResearchClaim::class);
    }

    public function requirementLogs(): HasMany
    {
        return $this->hasMany(OrderRequirement::class)->latest();
    }

    public function qualityReviews(): HasMany
    {
        return $this->hasMany(QualityReview::class)->orderBy('round');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->latest();
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(Feedback::class);
    }

    // ------------------------------------------------------------------- scopes

    /** Orders that reached payment (excludes abandoned drafts). */
    public function scopeSubmitted(Builder $query): void
    {
        $query->whereNotIn('status', [OrderStatus::New->value, OrderStatus::FormSubmitted->value]);
    }

    public function scopePaid(Builder $query): void
    {
        $query->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value]);
    }

    // ------------------------------------------------------------------ helpers

    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Paid;
    }

    public function answer(string $key, mixed $default = null): mixed
    {
        $answer = $this->relationLoaded('answers')
            ? $this->answers->firstWhere('field_key', $key)
            : $this->answers()->where('field_key', $key)->first();

        return $answer?->value ?? $default;
    }

    public function serviceName(): string
    {
        return (string) data_get($this->service_snapshot, 'name', $this->service?->name ?? 'Application document');
    }

    public function documentKind(): string
    {
        return (string) data_get($this->service_snapshot, 'document_kind', $this->service?->document_kind ?? 'general_essay');
    }

    /** e.g. "MSc Computer Science — University of Oxford". */
    public function applicationTitle(): string
    {
        return collect([$this->programme, $this->institution])->filter()->implode(' — ') ?: $this->serviceName();
    }

    public function formattedTotal(): string
    {
        return Money::format($this->total_amount, $this->currency);
    }

    public function revisionsRemaining(): int
    {
        return max(0, (int) $this->revisions_allowed - (int) $this->revisions_used);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }
}
