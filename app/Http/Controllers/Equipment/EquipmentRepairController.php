<?php

namespace App\Http\Controllers\Equipment;

use App\Enums\Equipment\AssignmentStatus;
use App\Enums\Equipment\EquipmentStatus;
use App\Enums\Equipment\RepairStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentRepair;
use App\Models\Projects\Project;
use App\Services\Equipment\EquipmentRepairService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentRepairController extends Controller
{
    public function __construct(private readonly EquipmentRepairService $repairs) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [EquipmentRepair::class, $project]);
        $user = $request->user();

        $filters = $request->validate(['status' => ['nullable', Rule::enum(RepairStatus::class)]]);

        $page = $project->equipmentRepairs()->with(['equipment:id,code,name', 'vendor:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('repair_date')->latest('id')->paginate(25)->withQueryString();

        // Equipment that can be sent for repair from this project: assigned here, or not assigned anywhere.
        $assignedElsewhere = EquipmentAssignment::query()
            ->where('status', AssignmentStatus::Active)->where('project_id', '!=', $project->id)->pluck('equipment_id');

        return Inertia::render('Equipment/Repairs', [
            'project' => ProjectHeader::for($project),
            'repairs' => $page->through(fn (EquipmentRepair $r) => [
                'id' => $r->id,
                'equipment_id' => $r->equipment_id,
                'equipment' => $r->equipment ? "{$r->equipment->code} · {$r->equipment->name}" : null,
                'repair_date' => $r->repair_date?->toDateString(),
                'completed_date' => $r->completed_date?->toDateString(),
                'description' => $r->description,
                'vendor_id' => $r->vendor_id,
                'vendor' => $r->vendor?->name,
                'cost' => $r->cost,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'remarks' => $r->remarks,
                'attachments' => ProcurementPresenter::attachments($r),
                'can_update' => $r->isOpen() && $user->can('update', $r),
            ]),
            'filters' => $filters,
            'statuses' => RepairStatus::options(),
            'options' => [
                'equipment' => Equipment::query()->active()
                    ->whereIn('status', [EquipmentStatus::Available, EquipmentStatus::Assigned])
                    ->whereNotIn('id', $assignedElsewhere)
                    ->orderBy('name')->get(['id', 'code', 'name'])
                    ->map(fn (Equipment $e) => ['value' => $e->id, 'label' => $e->name, 'description' => $e->code])->all(),
                'vendors' => ProcurementPresenter::vendorOptions(),
            ],
            'today' => now()->toDateString(),
            'can' => ['create' => $user->can('create', [EquipmentRepair::class, $project])],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [EquipmentRepair::class, $project]);

        $this->repairs->create($project, $request->validate([
            'equipment_id' => ['required', 'integer'],
            ...$this->rules(),
        ], [], ['equipment_id' => 'equipment']));

        return back()->with('success', 'Repair recorded; the equipment is under repair.');
    }

    public function update(Request $request, Project $project, EquipmentRepair $equipmentRepair): RedirectResponse
    {
        Gate::authorize('update', $equipmentRepair);

        $this->repairs->update($equipmentRepair, $request->validate($this->rules()));

        return back()->with('success', 'Repair updated.');
    }

    public function complete(Request $request, Project $project, EquipmentRepair $equipmentRepair): RedirectResponse
    {
        Gate::authorize('complete', $equipmentRepair);

        $data = $request->validate([
            'completed_date' => ['required', 'date', 'before_or_equal:today'],
            'cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->repairs->complete($equipmentRepair, $data);

        return back()->with('success', 'Repair completed; the equipment is back in service.');
    }

    public function cancel(Request $request, Project $project, EquipmentRepair $equipmentRepair): RedirectResponse
    {
        Gate::authorize('cancel', $equipmentRepair);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->repairs->cancel($equipmentRepair, $reason);

        return back()->with('success', 'Repair cancelled.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'repair_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:1000'],
            'vendor_id' => ['nullable', 'integer'],
            'cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
