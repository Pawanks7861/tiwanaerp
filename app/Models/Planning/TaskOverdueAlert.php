<?php

namespace App\Models\Planning;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Deduplication marker for the overdue-task notification: one row per task and planned finish.
 * Re-planning the task (a new planned finish) allows one new alert.
 */
class TaskOverdueAlert extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'planned_finish' => 'date',
            'alerted_at' => 'datetime',
        ];
    }
}
