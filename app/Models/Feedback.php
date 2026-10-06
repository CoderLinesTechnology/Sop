<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer satisfaction for a delivered order, stored against the service,
 * workflow and prompt versions that produced the document so administrators
 * can compare prompt versions.
 */
#[Table(name: 'feedback')]
#[Fillable(['order_id', 'service_id', 'ai_job_id', 'ai_workflow_id', 'prompt_versions', 'rating', 'liked', 'improve'])]
class Feedback extends Model
{
    protected function casts(): array
    {
        return ['prompt_versions' => 'array', 'rating' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AiWorkflow::class, 'ai_workflow_id');
    }
}
