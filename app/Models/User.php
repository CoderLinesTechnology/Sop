<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An optional customer account. Accounts are passwordless (magic link) and
 * exist only to let customers find previous orders; purchasing never
 * requires one.
 */
#[Fillable(['name', 'email', 'email_verified_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Orders placed with this account's (verified) email, with or without the account. */
    public function ordersByEmail()
    {
        return Order::query()->where(function ($query) {
            $query->where('user_id', $this->id)->orWhere('email', $this->email);
        });
    }
}
