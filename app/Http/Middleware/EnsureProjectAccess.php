<?php

namespace App\Http\Middleware;

use App\Models\Projects\Project;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every /projects/{project}/... route: the (already company-scoped) project must be
 * visible to the user through membership or projects.view_all.
 */
class EnsureProjectAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            Gate::authorize('view', $project);
        }

        return $next($request);
    }
}
