<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Imports\ImportMode;
use App\Enums\Planning\DependencyType;
use App\Enums\Planning\TaskPriority;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Planning\PlanningService;
use App\Services\Planning\TaskDependencyService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use Illuminate\Support\Facades\Validator;

class PlanningTaskImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(
        private readonly PlanningService $planning,
        private readonly TaskDependencyService $dependencies,
    ) {}

    public function key(): string
    {
        return 'planning.tasks';
    }

    public function label(): string
    {
        return 'Planning tasks';
    }

    public function permission(): string
    {
        return 'planning.import';
    }

    public function requiresProject(): bool
    {
        return true;
    }

    public function modes(): array
    {
        return [ImportMode::Create];
    }

    public function uniqueKey(): ?string
    {
        return 'wbs_code';
    }

    public function authorize(User $user, ?Project $project = null): bool
    {
        if (! $user->can($this->permission())) {
            return false;
        }

        return $project === null || $user->can('view', $project);
    }

    public function instructions(): string
    {
        return 'Creates project tasks in Not started status. Dependencies are applied after tasks exist. Progress and status are not changed by import. Example rows labelled EXAMPLE are ignored.';
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_Planning_WBS_Tasks_Import_Template';
    }

    public function fields(): array
    {
        return [
            new ImportField('wbs_code', 'WBS code', true, ['wbs', 'code']),
            new ImportField('name', 'Task name', true, ['task', 'title']),
            new ImportField('parent_wbs', 'Parent WBS', false, ['parent']),
            new ImportField('description', 'Description', false),
            new ImportField('planned_start', 'Planned start', false, ['start_date', 'start'], 'date'),
            new ImportField('planned_finish', 'Planned finish', false, ['end_date', 'finish'], 'date'),
            new ImportField('priority', 'Priority', false, [], 'text', array_map(fn (TaskPriority $p) => $p->value, TaskPriority::cases())),
            new ImportField('assignee', 'Assignee', false, ['assigned_to', 'email'], help: 'Company user email on the project team.'),
            new ImportField('unit', 'Unit', false, ['uom']),
            new ImportField('planned_qty', 'Planned quantity', false, ['qty', 'quantity'], 'number'),
            new ImportField('predecessor_wbs', 'Predecessor WBS', false, ['predecessor']),
            new ImportField('dependency_type', 'Dependency type', false, ['dep_type'], 'text', array_map(fn (DependencyType $t) => $t->value, DependencyType::cases()), 'FS, SS, FF or SF. Default FS.'),
            new ImportField('lag_days', 'Lag days', false, ['lag'], 'number'),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'wbs_code' => 'EXAMPLE-1.0',
            'name' => 'Site mobilisation',
            'parent_wbs' => '',
            'description' => 'EXAMPLE ROW — ignored',
            'planned_start' => '2026-04-01',
            'planned_finish' => '2026-04-15',
            'priority' => 'medium',
            'assignee' => '',
            'unit' => '',
            'planned_qty' => '',
            'predecessor_wbs' => '',
            'dependency_type' => 'FS',
            'lag_days' => '0',
        ]];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $project = $context->project;
        if ($project === null) {
            $this->fail(['project' => ['A project is required.']]);
        }

        $wbs = $this->str($mapped['wbs_code'] ?? null);
        $name = $this->str($mapped['name'] ?? null);
        $missing = [];
        if ($wbs === null) {
            $missing['wbs_code'] = ['WBS code is required.'];
        }
        if ($name === null) {
            $missing['name'] = ['Task name is required.'];
        }
        if ($missing !== []) {
            $this->fail($missing);
        }

        if (ProjectTask::query()->where('project_id', $project->id)->where('wbs_code', $wbs)->exists()) {
            $this->fail(['wbs_code' => ["WBS code {$wbs} already exists on this project."]]);
        }

        $parentWbs = $this->str($mapped['parent_wbs'] ?? null);
        $parentId = null;
        if ($parentWbs !== null) {
            $parentId = ProjectTask::query()->where('project_id', $project->id)->where('wbs_code', $parentWbs)->value('id');
            if ($parentId === null) {
                // Parent may appear later in the same file — resolve again at commit.
                $parentId = null;
            }
        }

        $assignee = $this->str($mapped['assignee'] ?? null);
        $assignedTo = null;
        if ($assignee !== null) {
            $assignedTo = User::query()
                ->where(function ($q) use ($assignee) {
                    $q->whereRaw('LOWER(email) = ?', [strtolower($assignee)])
                        ->orWhereRaw('LOWER(name) = ?', [strtolower($assignee)]);
                })
                ->whereHas('companies', fn ($q) => $q->where('companies.id', $context->company->id))
                ->value('id');
            if ($assignedTo === null) {
                $this->fail(['assignee' => ['No matching company user for this assignee.']]);
            }
        }

        $unitName = $this->str($mapped['unit'] ?? null);
        $unitId = null;
        if ($unitName !== null) {
            $unitId = Unit::query()->active()
                ->where(function ($q) use ($unitName) {
                    $q->whereRaw('LOWER(name) = ?', [strtolower($unitName)])
                        ->orWhereRaw('LOWER(symbol) = ?', [strtolower($unitName)]);
                })
                ->value('id');
            if ($unitId === null) {
                $this->fail(['unit' => ["Unit \"{$unitName}\" was not found."]]);
            }
        }

        $priorityRaw = strtolower((string) ($this->str($mapped['priority'] ?? null) ?? TaskPriority::Medium->value));
        $priority = TaskPriority::tryFrom($priorityRaw) ?? TaskPriority::Medium;

        $depTypeRaw = strtoupper((string) ($this->str($mapped['dependency_type'] ?? null) ?? 'FS'));
        $depType = DependencyType::tryFrom($depTypeRaw) ?? DependencyType::FinishToStart;

        $data = [
            'wbs_code' => $wbs,
            'name' => $name,
            'parent_wbs' => $parentWbs,
            'parent_id' => $parentId,
            'description' => $this->str($mapped['description'] ?? null),
            'planned_start' => $this->date($mapped['planned_start'] ?? null),
            'planned_finish' => $this->date($mapped['planned_finish'] ?? null),
            'priority' => $priority->value,
            'assigned_to' => $assignedTo,
            'unit_id' => $unitId,
            'planned_qty' => $this->number($mapped['planned_qty'] ?? null),
            'predecessor_wbs' => $this->str($mapped['predecessor_wbs'] ?? null),
            'dependency_type' => $depType->value,
            'lag_days' => (int) ($this->number($mapped['lag_days'] ?? null) ?? 0),
        ];

        Validator::make($data, [
            'wbs_code' => ['required', 'string', 'max:40', 'regex:'.PlanningService::WBS_PATTERN],
            'name' => ['required', 'string', 'max:200'],
            'planned_start' => ['nullable', 'date'],
            'planned_finish' => ['nullable', 'date', 'after_or_equal:planned_start'],
        ])->validate();

        return [
            'action' => 'create',
            'data' => $data,
            'existing_id' => null,
            'preview' => ['code' => $wbs, 'name' => $name],
        ];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] !== 'create') {
            return 'skipped';
        }

        $data = $prepared['data'];
        if (! empty($data['parent_wbs']) && empty($data['parent_id'])) {
            $data['parent_id'] = ProjectTask::query()
                ->where('project_id', $context->project->id)
                ->where('wbs_code', $data['parent_wbs'])
                ->value('id');
            if ($data['parent_id'] === null) {
                $this->fail(['parent_wbs' => ["Parent WBS {$data['parent_wbs']} was not found."]]);
            }
        }

        $payload = [
            'wbs_code' => $data['wbs_code'],
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'planned_start' => $data['planned_start'] ?? null,
            'planned_finish' => $data['planned_finish'] ?? null,
            'priority' => $data['priority'],
            'assigned_to' => $data['assigned_to'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'planned_qty' => $data['planned_qty'] ?? null,
        ];

        $task = $this->planning->saveTask($context->project, $payload, null);

        $map = $context->remember('task_wbs_map', fn () => (object) ['ids' => []]);
        $map->ids[$task->wbs_code] = $task->id;

        if (! empty($data['predecessor_wbs'])) {
            $deps = $context->remember('pending_deps', fn () => (object) ['rows' => []]);
            $deps->rows[] = [
                'successor_id' => $task->id,
                'predecessor_wbs' => $data['predecessor_wbs'],
                'type' => $data['dependency_type'],
                'lag_days' => $data['lag_days'],
            ];
        }

        return 'created';
    }

    public function afterCommit(ImportContext $context): void
    {
        $deps = $context->remember('pending_deps', fn () => null);
        $map = $context->remember('task_wbs_map', fn () => null);
        if (! is_object($deps) || ! is_object($map)) {
            return;
        }

        foreach ($deps->rows as $row) {
            $predId = $map->ids[$row['predecessor_wbs']]
                ?? ProjectTask::query()->where('project_id', $context->project->id)->where('wbs_code', $row['predecessor_wbs'])->value('id');
            if ($predId === null) {
                $this->fail(['predecessor_wbs' => ["Predecessor WBS {$row['predecessor_wbs']} was not found."]]);
            }

            $successor = ProjectTask::query()->findOrFail($row['successor_id']);
            $predecessor = ProjectTask::query()->findOrFail($predId);
            $type = DependencyType::from($row['type']);
            $this->dependencies->add($successor, $predecessor, $type, (int) $row['lag_days']);
        }
    }
}
