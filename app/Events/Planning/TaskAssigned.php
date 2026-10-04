<?php

namespace App\Events\Planning;

use App\Models\Planning\ProjectTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A task was created or updated with a new assignee. */
class TaskAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ProjectTask $task) {}
}
