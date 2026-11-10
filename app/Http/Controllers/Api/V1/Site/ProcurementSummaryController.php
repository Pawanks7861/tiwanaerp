<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Lightweight hub counts for the Expo Procurement tab. Aggregates only — no document payloads.
 */
class ProcurementSummaryController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $canMr = Gate::allows('viewAny', [MaterialRequest::class, $project]);
        $canPo = Gate::allows('viewAny', [PurchaseOrder::class, $project]);
        $canGrn = Gate::allows('viewAny', [Grn::class, $project]);

        abort_unless($canMr || $canPo || $canGrn || $user->can('approvals.view'), 403);

        return response()->json([
            'data' => [
                'material_requests' => $canMr ? $this->counts(MaterialRequest::query()->where('project_id', $project->id), [
                    'draft' => MaterialRequestStatus::Draft->value,
                    'submitted' => MaterialRequestStatus::Submitted->value,
                    'approved' => MaterialRequestStatus::Approved->value,
                ]) : $this->empty(['draft', 'submitted', 'approved']),
                'purchase_orders' => $canPo ? $this->purchaseOrderCounts($project) : $this->empty([
                    'open', 'pending_approval', 'approved', 'partially_received',
                ]),
                'grns' => $canGrn ? $this->counts(Grn::query()->where('project_id', $project->id), [
                    'pending_approval' => GrnStatus::Submitted->value,
                    'approved' => GrnStatus::Approved->value,
                ]) : $this->empty(['pending_approval', 'approved']),
                'pending_approvals' => $user->can('approvals.view')
                    ? $this->pendingApprovalsFor($user, $project)
                    : 0,
                'can' => [
                    'create_material_request' => Gate::allows('create', [MaterialRequest::class, $project]),
                    'create_purchase_order' => Gate::allows('create', [PurchaseOrder::class, $project]),
                    'create_grn' => $user->can('grn.create') && Gate::allows('view', $project),
                ],
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, string>  $buckets  label => status value
     * @return array<string, int>
     */
    private function counts($query, array $buckets): array
    {
        $rows = (clone $query)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $out = ['total' => (int) $rows->sum()];
        foreach ($buckets as $label => $status) {
            $out[$label] = (int) ($rows[$status] ?? 0);
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function purchaseOrderCounts(Project $project): array
    {
        $rows = PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $draft = (int) ($rows[PurchaseOrderStatus::Draft->value] ?? 0);
        $submitted = (int) ($rows[PurchaseOrderStatus::Submitted->value] ?? 0);
        $approved = (int) ($rows[PurchaseOrderStatus::Approved->value] ?? 0);
        $partial = (int) ($rows[PurchaseOrderStatus::PartiallyReceived->value] ?? 0);

        return [
            'total' => (int) $rows->sum(),
            'open' => $draft + $submitted + $approved + $partial,
            'pending_approval' => $submitted,
            'approved' => $approved,
            'partially_received' => $partial,
        ];
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<string, int>
     */
    private function empty(array $labels): array
    {
        return ['total' => 0] + array_fill_keys($labels, 0);
    }

    private function pendingApprovalsFor($user, Project $project): int
    {
        return $this->approvals->pendingFor($user)
            ->filter(function ($row) use ($project) {
                $doc = $row->approvable;
                if ($doc === null || ! isset($doc->project_id)) {
                    return false;
                }
                if ((int) $doc->project_id !== (int) $project->id) {
                    return false;
                }

                return $doc instanceof MaterialRequest
                    || $doc instanceof PurchaseOrder
                    || $doc instanceof Grn;
            })
            ->count();
    }
}
