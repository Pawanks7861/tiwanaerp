<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SiteRequest;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Site::class, $project]);

        $user = $request->user();

        return Inertia::render('Projects/Sites', [
            'project' => ProjectHeader::for($project),
            'sites' => $project->sites()->orderBy('name')->get()->map(fn (Site $s) => [
                ...$s->only(['id', 'name', 'address', 'latitude', 'longitude', 'geofence_radius_m', 'is_active']),
            ])->all(),
            'can' => [
                'create' => $user->can('create', [Site::class, $project]),
                'update' => $user->can('sites.update'),
                'delete' => $user->can('sites.delete'),
            ],
        ]);
    }

    public function store(SiteRequest $request, Project $project): RedirectResponse
    {
        $site = $this->projects->createSite($project, $request->validated() + ['is_active' => true]);

        return back()->with('success', "Site {$site->name} added.");
    }

    public function update(SiteRequest $request, Project $project, Site $site): RedirectResponse
    {
        $site->fill($request->validated())->save();

        return back()->with('success', 'Site updated.');
    }

    public function destroy(Project $project, Site $site): RedirectResponse
    {
        Gate::authorize('delete', $site);

        $this->projects->deleteSite($site);

        return back()->with('success', 'Site deleted.');
    }
}
