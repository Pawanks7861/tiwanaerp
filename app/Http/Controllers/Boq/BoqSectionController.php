<?php

namespace App\Http\Controllers\Boq;

use App\Http\Controllers\Controller;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqSection;
use App\Models\Projects\Project;
use App\Services\Boq\BoqService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BoqSectionController extends Controller
{
    public function __construct(private readonly BoqService $boqs) {}

    public function store(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('update', $boq);

        $section = $this->boqs->saveSection($boq, $this->validated($request));

        return back()->with('success', "Section {$section->name} added.");
    }

    public function update(Request $request, Project $project, Boq $boq, BoqSection $section): RedirectResponse
    {
        Gate::authorize('update', $boq);

        $this->boqs->saveSection($boq, $this->validated($request), $section);

        return back()->with('success', 'Section updated.');
    }

    public function destroy(Project $project, Boq $boq, BoqSection $section): RedirectResponse
    {
        Gate::authorize('update', $boq);

        $this->boqs->deleteSection($boq, $section);

        return back()->with('success', 'Section deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:200'],
            'discipline' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
