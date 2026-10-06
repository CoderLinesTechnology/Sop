<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Key/value store behind App\Support\Settings (always read through that cache). */
#[Fillable(['key', 'group', 'value', 'updated_by_admin_id'])]
class SiteSetting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
