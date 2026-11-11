<?php

namespace App\Http\Middleware;

use App\Models\Gate\GatePass;
use App\Models\Projects\Project;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every /projects/{project}/... route: the (already company-scoped) project must be
 * visible to the user through membership or projects.view_all.
 *
 * Narrow exception: Gate Pass show/PDF for users with gate_pass.view in the same company,
 * even when they are not assigned to the project (company-wide Gate Pass visibility).
 */
class EnsureProjectAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            if (! $this->allowsCompanyGatePassRead($request, $project)) {
                Gate::authorize('view', $project);
            }
        }

        return $next($request);
    }

    private function allowsCompanyGatePassRead(Request $request, Project $project): bool
    {
        $user = $request->user();
        if ($user === null || ! $user->can('gate_pass.view')) {
            return false;
        }

        $method = $request->route()?->getActionMethod();
        if (! in_array($method, ['show', 'pdf'], true)) {
            return false;
        }

        $name = (string) ($request->route()?->getName() ?? '');
        if (! str_starts_with($name, 'projects.gate-passes.')) {
            return false;
        }

        $pass = $request->route('gatePass');
        if (! $pass instanceof GatePass) {
            return false;
        }

        return (int) $pass->project_id === (int) $project->id
            && Gate::forUser($user)->allows('view', $pass);
    }
}
