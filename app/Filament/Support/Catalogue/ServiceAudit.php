<?php

namespace App\Filament\Support\Catalogue;

use App\Models\Service;
use App\Models\ServiceField;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/** Audit snapshots of a service's order form, and the post-save form warnings. */
final class ServiceAudit
{
    private const FIELD_ATTRIBUTES = [
        'key', 'label', 'type', 'section', 'requirement', 'help_text', 'placeholder', 'options', 'validation',
        'maps_to', 'ai_hint', 'optional_when_upload', 'show_when', 'width', 'display_order', 'is_active',
    ];

    /** @return array<int, array<string, mixed>> keyed by field id */
    public static function fields(Service $service): array
    {
        return $service->fields()->get()
            ->mapWithKeys(fn (ServiceField $field): array => [$field->id => AuditDiff::snapshot($field, self::FIELD_ATTRIBUTES)])
            ->all();
    }

    /**
     * Summary of what changed in the order form: keys added, removed and edited.
     *
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array<string, list<string>>
     */
    public static function fieldChanges(array $before, array $after): array
    {
        $added = array_values(array_map(fn (array $field): string => (string) $field['key'], array_diff_key($after, $before)));
        $removed = array_values(array_map(fn (array $field): string => (string) $field['key'], array_diff_key($before, $after)));
        $changed = [];

        foreach (array_intersect_key($after, $before) as $id => $field) {
            [$old] = AuditDiff::changes($before[$id], $field);
            if ($old !== []) {
                $changed[] = $field['key'].' ('.implode(', ', array_keys($old)).')';
            }
        }

        return array_filter(compact('added', 'removed', 'changed'));
    }

    public static function notifyFormWarnings(Service $service): void
    {
        $warnings = ServiceFormChecks::warningsFor($service);
        if ($warnings === []) {
            return;
        }

        Notification::make()
            ->warning()
            ->title($service->is_active ? 'This live service has order-form problems' : 'Check the order form')
            ->body(new HtmlString(implode('<br>', array_map('e', $warnings))))
            ->persistent()
            ->send();
    }
}
