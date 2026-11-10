<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Equipment\AssignmentStatus;
use App\Enums\Equipment\EquipmentLocationType;
use App\Enums\Equipment\RepairStatus;
use App\Http\Controllers\Controller;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Equipment\EquipmentRepair;
use App\Models\Gate\GatePass;
use App\Models\Projects\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EquipmentSummaryController extends Controller
{
    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [EquipmentFuelLog::class, $project]);

        $assignedIds = EquipmentAssignment::query()
            ->where('project_id', $project->id)
            ->where('status', AssignmentStatus::Active)
            ->pluck('equipment_id');

        $active = $assignedIds->count();

        $scoped = fn () => Equipment::query()->whereIn('id', $assignedIds->isEmpty() ? [-1] : $assignedIds);

        return response()->json([
            'data' => [
                'active' => $active,
                'project_site' => Equipment::query()
                    ->where('location_project_id', $project->id)
                    ->where('location_type', EquipmentLocationType::ProjectSite)
                    ->count(),
                'in_transit' => $scoped()->where('location_type', EquipmentLocationType::InTransit)->count(),
                'workshop' => $scoped()->where('location_type', EquipmentLocationType::Workshop)->count(),
                'maintenance_due' => EquipmentRepair::query()
                    ->where('project_id', $project->id)
                    ->where('status', RepairStatus::Open)
                    ->count(),
                'fuel_logs_today' => EquipmentFuelLog::query()
                    ->where('project_id', $project->id)
                    ->whereDate('log_date', now()->toDateString())
                    ->count(),
                'can' => [
                    'view_register' => $request->user()->can('equipment.view'),
                    'create_fuel' => Gate::allows('create', [EquipmentFuelLog::class, $project]),
                    'create_gate_pass' => Gate::allows('create', [GatePass::class, $project]),
                ],
            ],
        ]);
    }
}
