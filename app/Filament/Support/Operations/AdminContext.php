<?php

namespace App\Filament\Support\Operations;

use App\Models\AdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * The signed-in administrator (guard "admin") and permission checks for the
 * operations side of the panel. Permissions are spatie permission names
 * (App\Enums\Permission); super admins hold all of them through their role.
 */
final class AdminContext
{
    public static function user(): ?AdminUser
    {
        $user = Auth::guard('admin')->user();

        return $user instanceof AdminUser ? $user : null;
    }

    /** The signed-in administrator, or an authorization failure. */
    public static function require(): AdminUser
    {
        return self::user() ?? throw new AuthorizationException('An administrator must be signed in.');
    }

    public static function can(string $permission): bool
    {
        return (bool) self::user()?->checkPermissionTo($permission, 'admin');
    }

    public static function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** Policy ability check for the signed-in administrator (e.g. allows('manage', $order)). */
    public static function allows(string $ability, mixed $arguments = []): bool
    {
        $user = self::user();

        return $user !== null && Gate::forUser($user)->allows($ability, $arguments);
    }
}
