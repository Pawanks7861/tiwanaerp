<?php

namespace App\Services\Planning;

use App\Enums\Planning\DependencyType;
use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskDependency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task-to-task links (FS/SS/FF/SF with lag). Links stay inside one project and the dependency
 * graph must remain acyclic; both are enforced here, server side.
 */
class TaskDependencyService
{
    public function add(ProjectTask $successor, ProjectTask $predecessor, DependencyType $type, int $lagDays = 0): TaskDependency
    {
        return DB::transaction(function () use ($successor, $predecessor, $type, $lagDays) {
            if ((int) $successor->id === (int) $predecessor->id) {
                throw $this->invalid('A task cannot depend on itself.');
            }
            if ((int) $successor->project_id !== (int) $predecessor->project_id || (int) $successor->company_id !== (int) $predecessor->company_id) {
                throw $this->invalid('Both tasks must belong to the same project.');
            }

            // Serialise concurrent edits of this project's dependency graph.
            ProjectTask::query()->where('project_id', $successor->project_id)->lockForUpdate()->pluck('id');

            if (TaskDependency::query()->where('predecessor_id', $predecessor->id)->where('successor_id', $successor->id)->exists()) {
                throw $this->invalid('This dependency already exists.');
            }
            if ($this->isAncestor($predecessor, $successor) || $this->isAncestor($successor, $predecessor)) {
                throw $this->invalid('A task cannot depend on its own parent or sub-task.');
            }
            if ($this->reachable((int) $successor->id, (int) $predecessor->id, (int) $successor->project_id)) {
                throw $this->invalid('This dependency would create a circular chain of tasks.');
            }

            $dependency = new TaskDependency;
            $dependency->forceFill([
                'predecessor_id' => $predecessor->id,
                'successor_id' => $successor->id,
                'type' => $type,
                'lag_days' => $lagDays,
            ])->save();

            return $dependency;
        });
    }

    public function remove(TaskDependency $dependency): void
    {
        $dependency->delete();
    }

    /**
     * Whether $to can be reached from $from by following predecessor → successor links.
     */
    public function reachable(int $from, int $to, int $projectId): bool
    {
        $edges = [];
        TaskDependency::query()
            ->whereIn('predecessor_id', ProjectTask::query()->where('project_id', $projectId)->select('id'))
            ->get(['predecessor_id', 'successor_id'])
            ->each(function (TaskDependency $d) use (&$edges) {
                $edges[(int) $d->predecessor_id][] = (int) $d->successor_id;
            });

        $queue = [$from];
        $seen = [$from => true];
        while ($queue !== []) {
            $node = array_shift($queue);
            if ($node === $to) {
                return true;
            }
            foreach ($edges[$node] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    private function isAncestor(ProjectTask $ancestor, ProjectTask $task): bool
    {
        $seen = [];
        $parentId = $task->parent_id;
        while ($parentId !== null && ! isset($seen[$parentId])) {
            if ((int) $parentId === (int) $ancestor->id) {
                return true;
            }
            $seen[$parentId] = true;
            $parentId = ProjectTask::query()->whereKey($parentId)->value('parent_id');
        }

        return false;
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['predecessor_id' => $message]);
    }
}
