<?php

namespace App\Filament\Support\Catalogue;

use App\Models\AiWorkflow;

/** Keeps exactly one default AI workflow. */
final class WorkflowDefaults
{
    /** Make $workflow the only default (call inside the saving transaction). */
    public static function makeDefault(AiWorkflow $workflow): void
    {
        // A query update: is_default is not configuration, so no version bump.
        AiWorkflow::query()
            ->whereKeyNot($workflow->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => now()]);
    }
}
