<?php

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Support\Catalogue\AdminAccounts;
use App\Models\AdminUser;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateAdminUser extends CreateRecord
{
    protected static string $resource = AdminUserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $roles = array_values(array_filter((array) ($data['roles'] ?? [])));
        unset($data['roles'], $data['password_confirmation']);
        $data['email'] = Str::lower(trim((string) $data['email']));

        /** @var AdminUser $admin */
        $admin = AdminUser::query()->create($data);
        $admin->syncRoles($roles);

        Audit::log('admin.created', $admin, null, [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_active' => (bool) $admin->is_active,
            'roles' => AdminAccounts::roleNames($admin->load('roles')),
        ]);

        return $admin;
    }
}
