<?php

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdminUsers extends ListRecords
{
    protected static string $resource = AdminUserResource::class;

    protected ?string $subheading = 'Everyone who can sign in to this panel. Accounts are deactivated rather than deleted so the audit trail stays intact.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New administrator'),
        ];
    }
}
