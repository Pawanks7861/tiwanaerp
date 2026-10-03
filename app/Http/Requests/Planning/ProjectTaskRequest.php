<?php

namespace App\Http\Requests\Planning;

use App\Enums\Planning\TaskPriority;
use App\Http\Requests\Concerns\NormalizesDecimals;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use App\Rules\ExistsInCompany;
use App\Services\Planning\PlanningService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parent, milestone and BOQ line are re-checked against the route project in PlanningService.
 */
class ProjectTaskRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof ProjectTask
            ? $this->user()->can('update', $task)
            : $this->user()->can('create', [ProjectTask::class, $this->route('project')]);
    }

    protected function decimalFields(): array
    {
        return ['planned_qty', 'budget_amount'];
    }

    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'parent_id' => ['nullable', 'integer'],
            'milestone_id' => ['nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
            'wbs_code' => ['nullable', 'string', 'max:20', 'regex:'.PlanningService::WBS_PATTERN],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('project_users', 'user_id')
                ->where('project_id', $project->id)->where('is_active', true)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'planned_start' => ['nullable', 'date'],
            'planned_finish' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'unit_id' => ['nullable', ExistsInCompany::active(Unit::class)],
            'planned_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'parent_id' => 'parent task',
            'boq_item_id' => 'BOQ line',
            'wbs_code' => 'WBS code',
            'assigned_to' => 'assignee',
            'unit_id' => 'unit',
        ];
    }

    public function messages(): array
    {
        return [
            'wbs_code.regex' => 'Use letters/numbers separated by dots, e.g. 1.2.3.',
            'assigned_to.exists' => 'The assignee must be an active member of this project.',
        ];
    }

    /**
     * Validated data, with cost fields dropped for users who cannot see costs.
     *
     * @return array<string, mixed>
     */
    public function taskData(User $user): array
    {
        $data = $this->validated();
        if (! $user->can('boq.view_costs')) {
            unset($data['budget_amount']);
        }

        return $data;
    }
}
