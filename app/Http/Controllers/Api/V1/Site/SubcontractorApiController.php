<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Labour\LabourPresenter;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SubcontractorApiController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->can('subcontract.view') && $request->user()->can('view', $project), 403);

        $search = trim((string) $request->query('search', ''));
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $subcontractorIds = WorkOrder::query()
            ->where('project_id', $project->id)
            ->distinct()
            ->pluck('subcontractor_id');

        $query = Subcontractor::query()
            ->whereIn('id', $subcontractorIds)
            ->when($search !== '', fn ($q) => $q->search($search))
            ->orderBy('name');

        $page = $query->paginate($perPage)->withQueryString();

        $woCounts = WorkOrder::query()
            ->where('project_id', $project->id)
            ->selectRaw('subcontractor_id, count(*) as aggregate')
            ->groupBy('subcontractor_id')
            ->pluck('aggregate', 'subcontractor_id');

        $billCounts = SubcontractorBill::query()
            ->where('project_id', $project->id)
            ->selectRaw('subcontractor_id, count(*) as aggregate')
            ->groupBy('subcontractor_id')
            ->pluck('aggregate', 'subcontractor_id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Subcontractor $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'trade' => $s->trade,
                'mobile' => $s->mobile,
                'work_orders_count' => (int) ($woCounts[$s->id] ?? 0),
                'bills_count' => (int) ($billCounts[$s->id] ?? 0),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, Subcontractor $subcontractor): JsonResponse
    {
        Gate::authorize('view', $subcontractor);
        $user = $request->user();
        $bank = LabourPresenter::seesSubcontractorBank($user);

        return response()->json([
            'data' => [
                'id' => $subcontractor->id,
                'code' => $subcontractor->code,
                'name' => $subcontractor->name,
                'trade' => $subcontractor->trade,
                'contact_person' => $subcontractor->contact_person,
                'mobile' => $subcontractor->mobile,
                'email' => $subcontractor->email,
                'gstin' => $subcontractor->gstin,
                'pan' => $subcontractor->pan,
                'state_code' => $subcontractor->state_code,
                'address' => $subcontractor->address,
                'city' => $subcontractor->city,
                'pincode' => $subcontractor->pincode,
                'payment_terms' => $subcontractor->payment_terms,
                'bank_name' => $bank ? $subcontractor->bank_name : null,
                'bank_account_no' => $bank ? $subcontractor->bank_account_no : null,
                'bank_ifsc' => $bank ? $subcontractor->bank_ifsc : null,
                'is_active' => (bool) $subcontractor->is_active,
            ],
        ]);
    }
}
