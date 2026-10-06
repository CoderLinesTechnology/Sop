<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\AdminRole;
use App\Filament\Support\Catalogue\PermissionLabels;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Role $record): string => PermissionLabels::role($record->name))
                ->description(fn (Role $record): ?string => $record->name === AdminRole::SuperAdmin->value
                    ? 'The Super Admin role always has every permission and cannot be edited.'
                    : PermissionLabels::roleDescription($record->name))
                ->schema([
                    TextEntry::make('permission_labels')
                        ->label('Permissions')
                        ->state(fn (Role $record): array => $record->permissions
                            ->pluck('name')
                            ->sort()
                            ->map(fn (string $name): string => PermissionLabels::permission($name).' ('.$name.')')
                            ->values()
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('No permissions'),
                ])
                ->columnSpanFull(),
        ]);
    }
}
