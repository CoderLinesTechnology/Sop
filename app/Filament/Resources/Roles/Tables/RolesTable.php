<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Enums\AdminRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Catalogue\PermissionLabels;
use App\Models\AdminUser;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        // Administrators per role, counted once per render (spatie's users() relation cannot be counted for a guard).
        $counts = null;
        $admins = function (Role $role) use (&$counts): int {
            $counts ??= DB::table(config('permission.table_names.model_has_roles'))
                ->where('model_type', (new AdminUser)->getMorphClass())
                ->selectRaw(app(PermissionRegistrar::class)->pivotRole.' as role_id, COUNT(*) as aggregate')
                ->groupBy(app(PermissionRegistrar::class)->pivotRole)
                ->pluck('aggregate', 'role_id')
                ->all();

            return (int) ($counts[$role->getKey()] ?? 0);
        };

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('permissions'))
            ->defaultSort('id')
            ->paginated(false)
            ->recordUrl(fn (Role $record): string => RoleResource::getUrl(RoleResource::canEdit($record) ? 'edit' : 'view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->formatStateUsing(fn (string $state): string => PermissionLabels::role($state))
                    ->description(fn (Role $record): ?string => PermissionLabels::roleDescription($record->name))
                    ->weight('medium'),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->formatStateUsing(fn (int $state, Role $record): string => $record->name === AdminRole::SuperAdmin->value ? 'All' : (string) $state),
                TextColumn::make('admins')
                    ->label('Administrators')
                    ->state(fn (Role $record): int => $admins($record)),
                TextColumn::make('updated_at')->label('Updated')->since(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->label('Edit permissions'),
            ]);
    }
}
