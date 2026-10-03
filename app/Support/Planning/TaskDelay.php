<?php

namespace App\Support\Planning;

use App\Enums\Planning\TaskStatus;
use App\Models\Planning\ProjectTask;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Schedule delay in days, computed on read and never stored. Incomplete tasks: days past the
 * planned finish (0 if not yet due). Completed tasks: actual finish minus planned finish
 * (negative = finished early). Null when the task has no planned finish.
 */
final class TaskDelay
{
    public static function days(ProjectTask $task, ?CarbonInterface $today = null): ?int
    {
        if ($task->planned_finish === null) {
            return null;
        }

        $planned = CarbonImmutable::parse($task->planned_finish->toDateString());

        if ($task->status === TaskStatus::Completed) {
            return $task->actual_finish === null
                ? null
                : (int) $planned->diffInDays(CarbonImmutable::parse($task->actual_finish->toDateString()), false);
        }

        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        return max(0, (int) $planned->diffInDays($today, false));
    }

    public static function isOverdue(ProjectTask $task, ?CarbonInterface $today = null): bool
    {
        return $task->status !== TaskStatus::Completed && (self::days($task, $today) ?? 0) > 0;
    }
}
