<?php

namespace App\Filament\Support\Catalogue;

use App\Models\Faq;
use App\Models\Service;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Archive (soft delete), restore and permanent deletion of services, with an
 * audit entry for each. Restored services come back inactive so they are
 * reviewed before customers can order them again.
 */
final class ServiceArchiver
{
    public static function archive(Service $service): bool
    {
        return DB::transaction(function () use ($service): bool {
            $before = AuditDiff::snapshot($service, ['name', 'slug', 'is_active', 'price', 'currency']);
            $service->is_active = false;
            $service->save();
            $service->delete();

            Audit::log('service.archived', $service, $before, ['is_active' => false, 'deleted_at' => $service->deleted_at?->toAtomString()]);

            return true;
        });
    }

    public static function restore(Service $service): bool
    {
        return DB::transaction(function () use ($service): bool {
            $service->restore();

            Audit::log('service.restored', $service, ['deleted_at' => $service->getOriginal('deleted_at')], ['deleted_at' => null, 'is_active' => (bool) $service->is_active]);

            return true;
        });
    }

    /** Only for archived services that never had an order (orders keep a hard reference). */
    public static function forceDelete(Service $service): bool
    {
        if (! $service->trashed() || $service->orders()->exists()) {
            return false;
        }

        return DB::transaction(function () use ($service): bool {
            $before = AuditDiff::snapshot($service, ['name', 'slug', 'price', 'currency', 'document_kind']);
            $fields = $service->fields()->count();

            // FAQs and fields cascade in the database; delete FAQs through
            // Eloquent so cache listeners see the change.
            Faq::query()->where('service_id', $service->id)->get()->each->delete();
            $service->forceDelete();

            Audit::log('service.deleted', $service, $before, null, ['fields_deleted' => $fields]);

            return true;
        });
    }
}
