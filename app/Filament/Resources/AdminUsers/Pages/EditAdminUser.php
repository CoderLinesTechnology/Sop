<?php

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\AdminUsers\Tables\AdminUsersTable;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Filament\Support\Catalogue\AdminAccounts;
use App\Models\AdminUser;
use App\Support\Audit;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property AdminUser $record
 */
class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            AdminUsersTable::resetMfaAction(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Never send secrets to the browser.
        unset($data['password'], $data['remember_token'], $data['app_authentication_secret'], $data['app_authentication_recovery_codes']);
        $data['roles'] = AdminAccounts::roleNames($this->record);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var AdminUser $record */
        $roles = array_values(array_filter((array) ($data['roles'] ?? AdminAccounts::roleNames($record))));
        unset($data['roles'], $data['password_confirmation']);

        if (AdminAccess::user()?->is($record)) {
            $data['is_active'] = true;
        }
        $active = (bool) ($data['is_active'] ?? $record->is_active);

        // Re-checked with the super admin rows locked, so two concurrent edits cannot both remove one.
        AdminAccounts::assertAllowed($record, $roles, $active);

        $before = [
            'name' => $record->name,
            'email' => $record->email,
            'is_active' => (bool) $record->is_active,
            'roles' => AdminAccounts::roleNames($record),
        ];

        if (isset($data['email'])) {
            $data['email'] = Str::lower(trim((string) $data['email']));
        }
        $passwordChanged = filled($data['password'] ?? null);

        $record->update($data);
        $record->syncRoles($roles);
        $record->load('roles');

        $after = [
            'name' => $record->name,
            'email' => $record->email,
            'is_active' => (bool) $record->is_active,
            'roles' => AdminAccounts::roleNames($record),
        ];

        $changedBefore = array_filter($before, fn ($value, $key) => $value !== $after[$key], ARRAY_FILTER_USE_BOTH);
        $changedAfter = array_intersect_key($after, $changedBefore);

        if ($changedAfter !== [] || $passwordChanged) {
            Audit::log('admin.updated', $record, $changedBefore ?: null, $changedAfter ?: null, array_filter(['password_changed' => $passwordChanged ?: null]));
        }

        return $record;
    }
}
