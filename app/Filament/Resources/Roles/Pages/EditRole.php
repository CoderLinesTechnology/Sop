<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Enums\AdminRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Catalogue\PermissionLabels;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property Role $record
 */
class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public function getTitle(): string|Htmlable
    {
        return 'Permissions: '.PermissionLabels::role($this->record->name);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetToDefaults')
                ->label('Reset to defaults')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn (): bool => AdminRole::tryFrom($this->record->name) !== null)
                ->requiresConfirmation()
                ->modalDescription('Restores the built-in permissions of this role.')
                ->action(function (): void {
                    $role = AdminRole::from($this->record->name);
                    $this->syncPermissions($role->permissions(), 'role.permissions_reset');
                    $this->fillForm();

                    Notification::make()->success()->title('Default permissions restored')->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions'] = $this->record->permissions->pluck('name')->sort()->values()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->syncPermissions(array_values(array_filter((array) ($data['permissions'] ?? []))), 'role.permissions_updated');

        return $record;
    }

    /** @param  list<string>  $names */
    private function syncPermissions(array $names, string $auditAction): void
    {
        abort_unless(RoleResource::canEdit($this->record), 403);

        $before = $this->record->permissions()->pluck('name')->sort()->values()->all();

        $permissions = array_map(fn (string $name) => Permission::findOrCreate($name, 'admin'), array_unique($names));
        $this->record->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $this->record->permissions()->pluck('name')->sort()->values()->all();
        $this->record->unsetRelation('permissions');

        if ($before !== $after) {
            Audit::log($auditAction, $this->record, ['permissions' => $before], ['permissions' => $after], [
                'role' => $this->record->name,
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ]);
        }
    }
}
