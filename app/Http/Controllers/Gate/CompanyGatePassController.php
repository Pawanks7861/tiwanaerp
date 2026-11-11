<?php

namespace App\Http\Controllers\Gate;

use App\Http\Controllers\Controller;
use App\Models\Gate\GatePass;
use App\Models\Projects\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company-wide Gate Pass list for users with gate_pass.view (project membership not required).
 * Create/approve/verify remain on project-scoped routes and permissions.
 */
class CompanyGatePassController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAnyCompany', GatePass::class);

        $page = GatePass::query()
            ->with(['project:id,code,name'])
            ->latest('passed_at')
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return Inertia::render('Gate/CompanyIndex', [
            'passes' => $page->through(fn (GatePass $pass) => [
                'id' => $pass->id,
                'pass_number' => $pass->pass_number,
                'project_id' => $pass->project_id,
                'project' => $pass->project ? [
                    'id' => $pass->project->id,
                    'code' => $pass->project->code,
                    'name' => $pass->project->name,
                ] : null,
                'direction' => $pass->direction->value,
                'direction_label' => $pass->direction->label(),
                'returnable' => $pass->returnable,
                'party_name' => $pass->party_name,
                'vehicle_no' => $pass->vehicle_no,
                'purpose' => $pass->purpose,
                'passed_at' => $pass->passed_at?->toIso8601String(),
                'status' => $pass->status->value,
                'status_label' => $pass->status->label(),
                'show_url' => route('projects.gate-passes.show', [$pass->project_id, $pass->id]),
            ]),
        ]);
    }
}
