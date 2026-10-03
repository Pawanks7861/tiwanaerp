<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Planning\DependencyType;
use App\Http\Controllers\Controller;
use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskDependency;
use App\Models\Projects\Project;
use App\Services\Planning\TaskDependencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskDependencyController extends Controller
{
    public function __construct(private readonly TaskDependencyService $dependencies) {}

    public function store(Request $request, Project $project, ProjectTask $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'predecessor_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(DependencyType::class)],
            'lag_days' => ['nullable', 'integer', 'between:-365,365'],
        ]);

        $predecessor = $project->tasks()->find($data['predecessor_id']);
        if ($predecessor === null) {
            return back()->withErrors(['predecessor_id' => 'Choose a task of this project.']);
        }

        $this->dependencies->add($task, $predecessor, DependencyType::from($data['type']), (int) ($data['lag_days'] ?? 0));

        return back()->with('success', 'Dependency added.');
    }

    public function destroy(Project $project, ProjectTask $task, TaskDependency $dependency): RedirectResponse
    {
        Gate::authorize('update', $task);

        $this->dependencies->remove($dependency);

        return back()->with('success', 'Dependency removed.');
    }
}
