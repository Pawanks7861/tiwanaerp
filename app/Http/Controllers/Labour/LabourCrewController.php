<?php

namespace App\Http\Controllers\Labour;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAdvance;
use App\Models\Labour\LabourAttendance;
use App\Models\Projects\Project;
use App\Services\Labour\LabourAdvanceService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project labour: the crew (labourers whose current project this is) and their advances.
 */
class LabourCrewController extends Controller
{
    public function __construct(private readonly LabourAdvanceService $advances) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [LabourAttendance::class, $project]);
        $user = $request->user();

        $crew = Labour::query()->where('current_project_id', $project->id)
            ->with(['trade:id,name', 'subcontractor:id,name'])
            ->orderByDesc('is_active')->orderBy('name')->get();

        $advanced = LabourAdvance::query()->whereIn('labour_id', $crew->modelKeys())
            ->selectRaw('labour_id, SUM(amount) as advanced, SUM(recovered_amount) as recovered')
            ->groupBy('labour_id')->get()->keyBy('labour_id');

        $advances = $project->advances()->with('labour:id,code,name')->latest('advance_date')->latest('id')->limit(100)->get();

        return Inertia::render('Labour/Crew', [
            'project' => ProjectHeader::for($project),
            'crew' => $crew->map(fn (Labour $l) => [
                'id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'mobile' => $l->mobile,
                'trade' => $l->trade?->name,
                'subcontractor' => $l->subcontractor?->name,
                'daily_wage' => $l->daily_wage,
                'ot_rate_per_hour' => $l->ot_rate_per_hour,
                'is_active' => $l->is_active,
                'advance_outstanding' => isset($advanced[$l->id])
                    ? Decimal::of($advanced[$l->id]->advanced)->minus($advanced[$l->id]->recovered)->toMoney() : '0.00',
            ])->all(),
            'advances' => $advances->map(fn (LabourAdvance $a) => [
                'id' => $a->id,
                'labour' => $a->labour ? "{$a->labour->code} · {$a->labour->name}" : null,
                'advance_date' => $a->advance_date?->toDateString(),
                'amount' => $a->amount,
                'recovered_amount' => $a->recovered_amount,
                'outstanding' => $a->outstanding()->toMoney(),
                'remarks' => $a->remarks,
                'can_delete' => Decimal::of($a->recovered_amount)->isZero() && $user->can('delete', $a),
            ])->all(),
            'labourOptions' => Labour::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Labour $l) => ['value' => $l->id, 'label' => $l->name, 'description' => $l->code])->all(),
            'today' => now()->toDateString(),
            'can' => [
                'create_advance' => $user->can('create', [LabourAdvance::class, $project]),
                'manage_register' => $user->can('create', Labour::class),
            ],
        ]);
    }

    public function storeAdvance(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [LabourAdvance::class, $project]);

        $data = $request->validate([
            'labour_id' => ['required', 'integer'],
            'advance_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [], ['labour_id' => 'labourer']);

        $this->advances->create($project, $data);

        return back()->with('success', 'Advance recorded. It is recovered through labour payments and is not a project cost.');
    }

    public function destroyAdvance(Project $project, LabourAdvance $advance): RedirectResponse
    {
        Gate::authorize('delete', $advance);

        $this->advances->delete($advance);

        return back()->with('success', 'Advance deleted.');
    }
}
