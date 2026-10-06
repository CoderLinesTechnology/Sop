<?php

namespace App\Filament\Support\Catalogue;

use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Models\AdminUser;
use App\Models\PromptVersion;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Puts a prompt version into production. The previously active version of
 * the same key is archived first (the database allows one active version per
 * key), all inside one transaction, and the change is audited. This is the
 * only way a prompt becomes active: nothing activates prompts automatically.
 */
final class PromptActivation
{
    public static function activate(PromptVersion $version, AdminUser $admin): PromptVersion
    {
        if (! Gate::forUser($admin)->allows('activate', $version)) {
            throw new LogicException('This administrator may not activate prompt versions.');
        }

        return DB::transaction(function () use ($version, $admin): PromptVersion {
            /** @var PromptVersion $version */
            $version = PromptVersion::query()->lockForUpdate()->findOrFail($version->getKey());

            if ($version->status === PromptVersion::STATUS_ACTIVE) {
                return $version;
            }

            $previous = PromptVersion::query()
                ->where('prompt_key', $version->prompt_key)
                ->where('status', PromptVersion::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            $previous?->update(['status' => PromptVersion::STATUS_ARCHIVED]);

            $version->update([
                'status' => PromptVersion::STATUS_ACTIVE,
                'activated_by_admin_id' => $admin->id,
                'activated_at' => now(),
            ]);

            Audit::log(
                'prompt.activated',
                $version,
                ['active_version_id' => $previous?->id, 'active_version' => $previous?->version],
                ['active_version_id' => $version->id, 'active_version' => $version->version],
                ['prompt_key' => $version->prompt_key, 'label' => $version->label],
                $admin,
            );

            return $version;
        });
    }

    /** The "Activate" action used on the list, view and edit screens. */
    public static function action(): Action
    {
        return Action::make('activate')
            ->label(fn (PromptVersion $record): string => $record->status === PromptVersion::STATUS_ARCHIVED ? 'Re-activate (roll back)' : 'Activate')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->authorize('activate')
            ->requiresConfirmation()
            ->modalHeading(fn (PromptVersion $record): string => "Activate {$record->prompt_key} v{$record->version}?")
            ->modalDescription(function (PromptVersion $record): string {
                $active = PromptVersion::activeFor($record->prompt_key);

                return ($active
                    ? "Version {$active->version} is currently active and will be archived. "
                    : 'There is no active version for this key yet. ')
                    .'New AI jobs start using this version immediately; jobs already running keep the version they started with.';
            })
            ->modalSubmitActionLabel('Activate')
            ->action(function (PromptVersion $record, Action $action): void {
                $admin = AdminAccess::user();
                abort_unless($admin && Gate::forUser($admin)->allows('activate', $record), 403);

                self::activate($record, $admin);

                Notification::make()
                    ->success()
                    ->title("{$record->prompt_key} v{$record->version} is now active")
                    ->send();

                $action->redirect(PromptVersionResource::getUrl('view', ['record' => $record]));
            });
    }
}
