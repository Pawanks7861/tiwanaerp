<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'notification_type', 'channels'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return [
            'channels' => 'array',
        ];
    }
}
