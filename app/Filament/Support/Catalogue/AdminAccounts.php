<?php

namespace App\Filament\Support\Catalogue;

use App\Enums\AdminRole;
use App\Models\AdminUser;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Safety rules for administrator accounts: nobody deactivates themselves,
 * and the panel always keeps at least one active Super Admin.
 */
final class AdminAccounts
{
    public static function activeSuperAdminIds(bool $lock = false): array
    {
        return AdminUser::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', AdminRole::SuperAdmin->value)->where('guard_name', 'admin'))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** Why saving these roles / this status is not allowed, or null when it is. */
    public static function problem(?AdminUser $record, array $roles, bool $active, ?AdminUser $actor = null, bool $lock = false): ?string
    {
        $actor ??= AdminAccess::user();

        if ($record?->exists && $actor?->is($record) && ! $active) {
            return 'You cannot deactivate your own account.';
        }

        if (! $record?->exists) {
            return null;
        }

        $superAdmins = self::activeSuperAdminIds($lock);
        $isSuperAdminNow = in_array((int) $record->getKey(), $superAdmins, true);
        $staysSuperAdmin = $active && in_array(AdminRole::SuperAdmin->value, $roles, true);

        if ($isSuperAdminNow && ! $staysSuperAdmin && count($superAdmins) <= 1) {
            return 'This is the last active Super Admin. Make another administrator a Super Admin before removing this role or deactivating the account.';
        }

        return null;
    }

    /** @throws ValidationException */
    public static function assertAllowed(?AdminUser $record, array $roles, bool $active): void
    {
        if ($message = self::problem($record, $roles, $active, lock: true)) {
            throw ValidationException::withMessages(['data.roles' => $message]);
        }
    }

    /** Clear the authenticator secret and recovery codes; the admin enrols again at next sign-in. */
    public static function resetMfa(AdminUser $admin, ?AdminUser $actor = null): void
    {
        $admin->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        Audit::log('admin.mfa_reset', $admin, ['mfa_enabled' => true], ['mfa_enabled' => false], [], $actor ?? AdminAccess::user());
    }

    /** @return list<string> */
    public static function roleNames(AdminUser $admin): array
    {
        return $admin->roles->where('guard_name', 'admin')->pluck('name')->sort()->values()->all();
    }
}
