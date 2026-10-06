<?php

namespace App\Filament\Support\Catalogue;

use App\Models\RequirementRule;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Verification and audit helpers for requirement rules. */
final class RequirementRuleAudit
{
    public static function verifyAction(): Action
    {
        return Action::make('markVerified')
            ->label('Mark verified now')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading('Mark this rule as verified?')
            ->modalDescription(fn (RequirementRule $record): string => 'Confirm that you checked the rule against its official source'
                .($record->source_url ? ' ('.$record->source_url.')' : '').' today.')
            ->modalSubmitActionLabel('Mark verified')
            ->action(function (RequirementRule $record): void {
                $before = AuditDiff::snapshot($record, ['last_verified_at', 'verified_by_admin_id']);

                $record->forceFill([
                    'last_verified_at' => now(),
                    'verified_by_admin_id' => AdminAccess::user()?->id,
                ])->save();

                Audit::log('requirement_rule.verified', $record, $before, AuditDiff::snapshot($record, ['last_verified_at', 'verified_by_admin_id']));

                Notification::make()->success()->title('Rule marked as verified')->send();
            });
    }

    /** @return list<string> */
    public static function auditedAttributes(): array
    {
        return [
            'name', 'scope', 'country_code', 'institution_name', 'institution_domain', 'programme_name', 'degree_level',
            'application_platform', 'document_kinds', 'min_words', 'max_words', 'min_characters', 'max_characters', 'max_pages',
            'font_family', 'font_size', 'margins_mm', 'line_spacing', 'page_size', 'file_types', 'naming_convention',
            'required_sections', 'prohibited_content', 'language_variant', 'date_format', 'special_instructions',
            'submission_method', 'source_name', 'source_url', 'priority', 'is_active', 'notes',
        ];
    }
}
