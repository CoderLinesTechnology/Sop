<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Support\Catalogue\PermissionLabels;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make('Changes apply immediately to every administrator with this role')
                ->description('Running the roles seeder (php artisan db:seed --class=RolesAndPermissionsSeeder) resets every role to its built-in permissions. Use “Reset to defaults” to do that for one role.')
                ->warning()
                ->columnSpanFull(),
            Section::make('Permissions')
                ->description(fn (?Role $record): ?string => $record ? PermissionLabels::roleDescription($record->name) : null)
                ->schema([
                    CheckboxList::make('permissions')
                        ->hiddenLabel()
                        ->options(fn (?Role $record): array => self::options($record))
                        ->descriptions(fn (?Role $record): array => array_combine(array_keys(self::options($record)), array_keys(self::options($record))))
                        ->columns(2)
                        ->bulkToggleable()
                        ->searchable(),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** Every known permission plus any extra one the role already has. @return array<string, string> */
    private static function options(?Role $record): array
    {
        return PermissionLabels::permissionOptions($record?->permissions->pluck('name')->all() ?? []);
    }
}
