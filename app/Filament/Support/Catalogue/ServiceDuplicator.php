<?php

namespace App\Filament\Support\Catalogue;

use App\Models\AdminUser;
use App\Models\Faq;
use App\Models\Service;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies a service with its order form and FAQs. The copy starts inactive
 * and unfeatured so it never appears on the site before it has been reviewed.
 */
final class ServiceDuplicator
{
    public static function duplicate(Service $source, ?AdminUser $admin = null): Service
    {
        return DB::transaction(function () use ($source, $admin): Service {
            $copy = $source->replicate(['slug', 'deleted_at', 'fields_count', 'orders_count', 'faqs_count']);
            $copy->name = Str::limit($source->name.' (copy)', 160, '');
            $copy->slug = self::uniqueSlug($source->slug.'-copy');
            $copy->is_active = false;
            $copy->is_featured = false;
            $copy->display_order = (int) Service::withTrashed()->max('display_order') + 1;
            $copy->save();

            foreach ($source->fields()->get() as $field) {
                $copy->fields()->create($field->replicate(['service_id'])->attributesToArray());
            }

            foreach ($source->faqs()->get() as $faq) {
                Faq::query()->create($faq->replicate(['service_id'])->attributesToArray() + ['service_id' => $copy->id, 'scope' => 'service']);
            }

            Audit::log('service.duplicated', $copy, null, AuditDiff::snapshot($copy, ['name', 'slug', 'price', 'currency', 'is_active']), [
                'source_service_id' => $source->id,
                'source_slug' => $source->slug,
            ], $admin);

            return $copy;
        });
    }

    /** A slug no other service (archived ones included) is using. */
    public static function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($base) ?: 'service', 150, '');
        $slug = $base;
        $suffix = 2;

        while (Service::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
