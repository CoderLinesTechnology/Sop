<?php

namespace App\Models;

use Database\Factories\AdminUserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Administrator account (guard "admin"). Completely separate from customers.
 * Multi-factor authentication (TOTP + recovery codes) is enforced by the panel.
 */
#[Fillable(['name', 'email', 'password', 'is_active', 'last_login_at', 'last_login_ip'])]
#[Hidden(['password', 'remember_token'])]
class AdminUser extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    protected string $guard_name = 'admin';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->roles()->exists();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function hasMfaEnabled(): bool
    {
        return filled($this->app_authentication_secret);
    }
}
