<?php

namespace App\Services\Planning;

use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskOverdueAlert;
use App\Models\Projects\Project;
use App\Notifications\GeneralNotification;
use App\Support\Notifications\PermissionRecipients;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;

/**
 * One overdue notice per task and planned finish. Re-planning (a new finish date) allows one
 * new notice. Recipients are the assignee and the project manager.
 */
class TaskOverdueNotifier
{
    public function __construct(private readonly PermissionRecipients $recipients) {}

    public function notify(ProjectTask $task): void
    {
        $finish = $task->planned_finish?->toDateString();
        if ($finish === null) {
            return;
        }
        if (TaskOverdueAlert::query()->where('task_id', $task->id)->whereDate('planned_finish', $finish)->exists()) {
            return;
        }

        try {
            $alert = new TaskOverdueAlert;
            $alert->forceFill([
                'task_id' => $task->id,
                'planned_finish' => $finish,
                'alerted_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $managerId = Project::query()->whereKey($task->project_id)->value('project_manager_id');
        $users = $this->recipients->users((int) $task->company_id, [$task->assigned_to, $managerId]);
        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new GeneralNotification(
            (int) $task->company_id,
            'planning.task_overdue',
            'Task overdue',
            "{$task->wbs_code} {$task->name} is past its planned finish of ".date('d M Y', strtotime($finish)).'.',
            route('projects.planning.tasks.index', ['project' => $task->project_id], absolute: false),
            ['project_id' => (int) $task->project_id],
        ));
    }
}
