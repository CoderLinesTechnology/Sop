<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Double opt-in newsletter subscriber. Tokens are stored hashed. */
#[Fillable(['email', 'status', 'confirm_token_hash', 'unsubscribe_token_hash', 'source', 'ip_address', 'consented_at', 'confirmed_at', 'unsubscribed_at'])]
class NewsletterSubscriber extends Model
{
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
