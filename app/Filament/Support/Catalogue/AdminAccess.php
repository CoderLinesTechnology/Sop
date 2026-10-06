<?php

namespace App\Filament\Support\Catalogue;

use App\Models\AdminUser;
use Filament\Facades\Filament;
use Throwable;

/**
 * Permission checks for the catalogue / content / AI / system half of the
 * admin panel. Permissions are spatie/laravel-permission names on the
 * "admin" guard (see App\Enums\Permission). A permission that does not exist
 * yet (e.g. before the seeder ran) is treated as "not granted".
 */
final class AdminAccess
{
    public static function user(): ?AdminUser
    {
        try {
            $user = Filament::auth()->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof AdminUser ? $user : null;
    }

    public static function allows(string $permission, ?AdminUser $user = null): bool
    {
        $user ??= self::user();

        return $user !== null
            && $user->is_active
            && $user->checkPermissionTo($permission, 'admin');
    }

    /** @param  list<string>  $permissions */
    public static function allowsAny(array $permissions, ?AdminUser $user = null): bool
    {
        foreach ($permissions as $permission) {
            if (self::allows($permission, $user)) {
                return true;
            }
        }

        return false;
    }
}
