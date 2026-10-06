<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Schemas\RoleInfolist;
use App\Filament\Resources\Roles\Tables\RolesTable;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Filament\Support\Catalogue\PermissionLabels;
use App\Policies\RolePolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Admin roles (spatie/laravel-permission, guard "admin") and the
 * permissions each one grants.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Roles & permissions';

    protected static ?string $modelLabel = 'role';

    protected static ?string $slug = 'roles';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('guard_name', 'admin');
    }

    /**
     * Laravel cannot discover a policy for spatie's Role model, so the
     * resource asks App\Policies\RolePolicy directly. Unknown abilities are denied.
     */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = AdminAccess::user();
        $ability = match (true) {
            $action instanceof BackedEnum => (string) $action->value,
            $action instanceof UnitEnum => $action->name,
            default => $action,
        };
        $policy = app(RolePolicy::class);

        if (! $user || ! method_exists($policy, $ability)) {
            return Response::deny();
        }

        $allowed = $record instanceof Role ? $policy->{$ability}($user, $record) : $policy->{$ability}($user);

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Role ? PermissionLabels::role($record->name) : 'Role';
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RoleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
