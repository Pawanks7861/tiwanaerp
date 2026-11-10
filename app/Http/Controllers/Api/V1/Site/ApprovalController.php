<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\DprResource;
use App\Http\Resources\GrnResource;
use App\Http\Resources\MaterialIssueResource;
use App\Http\Resources\MaterialReturnResource;
use App\Http\Resources\MaterialRequisitionResource;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\ClientInvoiceResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\RetentionReleaseResource;
use App\Http\Resources\SubcontractorBillResource;
use App\Http\Resources\VendorBillResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Expense;
use App\Models\Finance\RetentionRelease;
use App\Models\Finance\VendorBill;
use App\Models\Gate\GatePass;
use App\Http\Resources\GatePassResource;
use App\Models\Approval\ApprovalRequest;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\MaterialReturn;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\WorkOrder;
use App\Services\Approval\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('approvals.view');

        $pending = $this->approvals->pendingFor($request->user())
            ->filter(function (ApprovalRequest $row) use ($project) {
                $doc = $row->approvable;
                if (! $doc instanceof Model || ! isset($doc->project_id)) {
                    return false;
                }

                return (int) $doc->project_id === (int) $project->id
                    && ($doc instanceof MaterialRequest
                        || $doc instanceof PurchaseOrder
                        || $doc instanceof Grn
                        || $doc instanceof MaterialIssue
                        || $doc instanceof MaterialReturn
                        || $doc instanceof Dpr
                        || $doc instanceof WorkOrder
                        || $doc instanceof SubcontractorBill
                        || $doc instanceof ClientInvoice
                        || $doc instanceof VendorBill
                        || $doc instanceof Expense
                        || $doc instanceof RetentionRelease
                        || $doc instanceof GatePass);
            })
            ->values();

        $data = $pending->map(function (ApprovalRequest $row) {
            $doc = $row->approvable;
            $type = match (true) {
                $doc instanceof MaterialRequest => 'material_request',
                $doc instanceof PurchaseOrder => 'purchase_order',
                $doc instanceof Grn => 'grn',
                $doc instanceof MaterialIssue => 'material_issue',
                $doc instanceof MaterialReturn => 'material_return',
                $doc instanceof Dpr => 'dpr',
                $doc instanceof WorkOrder => 'work_order',
                $doc instanceof SubcontractorBill => 'subcontractor_bill',
                $doc instanceof ClientInvoice => 'client_invoice',
                $doc instanceof VendorBill => 'vendor_bill',
                $doc instanceof Expense => 'expense',
                $doc instanceof RetentionRelease => 'retention_release',
                $doc instanceof GatePass => 'gate_pass',
                default => 'unknown',
            };

            return [
                'approval_request_id' => $row->id,
                'type' => $type,
                'id' => $doc->id,
                'number' => $this->documentNumber($doc),
                'status' => $doc->status?->value ?? $doc->status,
                'submitted_at' => $row->submitted_at?->toIso8601String(),
                'current_level' => $row->current_level,
            ];
        })->all();

        return response()->json(['data' => $data]);
    }

    public function approveMr(Request $request, Project $project, int $id): JsonResponse
    {
        return $this->decide($request, $this->pendingFor($project, MaterialRequest::class, $id), 'approve');
    }

    public function rejectMr(Request $request, Project $project, int $id): JsonResponse
    {
        return $this->decide($request, $this->pendingFor($project, MaterialRequest::class, $id), 'reject');
    }

    public function sendBackMr(Request $request, Project $project, int $id): JsonResponse
    {
        return $this->decide($request, $this->pendingFor($project, MaterialRequest::class, $id), 'send_back');
    }

    public function approvePo(Request $request, Project $project, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);

        return $this->decide($request, $purchaseOrder->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectPo(Request $request, Project $project, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);

        return $this->decide($request, $purchaseOrder->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackPo(Request $request, Project $project, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);

        return $this->decide($request, $purchaseOrder->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    public function approveGrn(Request $request, Project $project, Grn $grn): JsonResponse
    {
        abort_unless((int) $grn->project_id === (int) $project->id, 404);

        return $this->decide($request, $grn->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectGrn(Request $request, Project $project, Grn $grn): JsonResponse
    {
        abort_unless((int) $grn->project_id === (int) $project->id, 404);

        return $this->decide($request, $grn->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackGrn(Request $request, Project $project, Grn $grn): JsonResponse
    {
        abort_unless((int) $grn->project_id === (int) $project->id, 404);

        return $this->decide($request, $grn->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    public function approveIssue(Request $request, Project $project, MaterialIssue $materialIssue): JsonResponse
    {
        abort_unless((int) $materialIssue->project_id === (int) $project->id, 404);

        return $this->decide($request, $materialIssue->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectIssue(Request $request, Project $project, MaterialIssue $materialIssue): JsonResponse
    {
        abort_unless((int) $materialIssue->project_id === (int) $project->id, 404);

        return $this->decide($request, $materialIssue->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function approveReturn(Request $request, Project $project, MaterialReturn $materialReturn): JsonResponse
    {
        abort_unless((int) $materialReturn->project_id === (int) $project->id, 404);

        return $this->decide($request, $materialReturn->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectReturn(Request $request, Project $project, MaterialReturn $materialReturn): JsonResponse
    {
        abort_unless((int) $materialReturn->project_id === (int) $project->id, 404);

        return $this->decide($request, $materialReturn->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function approveDpr(Request $request, Project $project, Dpr $dpr): JsonResponse
    {
        abort_unless((int) $dpr->project_id === (int) $project->id, 404);

        return $this->decide($request, $dpr->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectDpr(Request $request, Project $project, Dpr $dpr): JsonResponse
    {
        abort_unless((int) $dpr->project_id === (int) $project->id, 404);

        return $this->decide($request, $dpr->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackDpr(Request $request, Project $project, Dpr $dpr): JsonResponse
    {
        abort_unless((int) $dpr->project_id === (int) $project->id, 404);

        return $this->decide($request, $dpr->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    public function approveWo(Request $request, Project $project, WorkOrder $workOrder): JsonResponse
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);

        return $this->decide($request, $workOrder->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectWo(Request $request, Project $project, WorkOrder $workOrder): JsonResponse
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);

        return $this->decide($request, $workOrder->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackWo(Request $request, Project $project, WorkOrder $workOrder): JsonResponse
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);

        return $this->decide($request, $workOrder->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    public function approveBill(Request $request, Project $project, SubcontractorBill $subcontractorBill): JsonResponse
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);

        return $this->decide($request, $subcontractorBill->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectBill(Request $request, Project $project, SubcontractorBill $subcontractorBill): JsonResponse
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);

        return $this->decide($request, $subcontractorBill->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackBill(Request $request, Project $project, SubcontractorBill $subcontractorBill): JsonResponse
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);

        return $this->decide($request, $subcontractorBill->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    public function approveClientInvoice(Request $request, Project $project, ClientInvoice $clientInvoice): JsonResponse
    {
        abort_unless((int) $clientInvoice->project_id === (int) $project->id, 404);

        return $this->decide($request, $clientInvoice->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectClientInvoice(Request $request, Project $project, ClientInvoice $clientInvoice): JsonResponse
    {
        abort_unless((int) $clientInvoice->project_id === (int) $project->id, 404);

        return $this->decide($request, $clientInvoice->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackClientInvoice(Request $request, Project $project, ClientInvoice $clientInvoice): JsonResponse
    {
        abort_unless((int) $clientInvoice->project_id === (int) $project->id, 404);

        return $this->decide($request, $clientInvoice->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    public function approveVendorBill(Request $request, Project $project, VendorBill $vendorBill): JsonResponse
    {
        abort_unless((int) $vendorBill->project_id === (int) $project->id, 404);

        return $this->decide($request, $vendorBill->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectVendorBill(Request $request, Project $project, VendorBill $vendorBill): JsonResponse
    {
        abort_unless((int) $vendorBill->project_id === (int) $project->id, 404);

        return $this->decide($request, $vendorBill->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackVendorBill(Request $request, Project $project, VendorBill $vendorBill): JsonResponse
    {
        abort_unless((int) $vendorBill->project_id === (int) $project->id, 404);

        return $this->decide($request, $vendorBill->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    public function approveExpense(Request $request, Project $project, Expense $expense): JsonResponse
    {
        abort_unless((int) $expense->project_id === (int) $project->id, 404);

        return $this->decide($request, $expense->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectExpense(Request $request, Project $project, Expense $expense): JsonResponse
    {
        abort_unless((int) $expense->project_id === (int) $project->id, 404);

        return $this->decide($request, $expense->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackExpense(Request $request, Project $project, Expense $expense): JsonResponse
    {
        abort_unless((int) $expense->project_id === (int) $project->id, 404);

        return $this->decide($request, $expense->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    public function approveRetentionRelease(Request $request, Project $project, RetentionRelease $retentionRelease): JsonResponse
    {
        abort_unless((int) $retentionRelease->project_id === (int) $project->id, 404);

        return $this->decide($request, $retentionRelease->pendingApprovalRequest() ?? abort(404), 'approve');
    }

    public function rejectRetentionRelease(Request $request, Project $project, RetentionRelease $retentionRelease): JsonResponse
    {
        abort_unless((int) $retentionRelease->project_id === (int) $project->id, 404);

        return $this->decide($request, $retentionRelease->pendingApprovalRequest() ?? abort(404), 'reject');
    }

    public function sendBackRetentionRelease(Request $request, Project $project, RetentionRelease $retentionRelease): JsonResponse
    {
        abort_unless((int) $retentionRelease->project_id === (int) $project->id, 404);

        return $this->decide($request, $retentionRelease->pendingApprovalRequest() ?? abort(404), 'send_back');
    }

    /** @deprecated Prefer typed approveMr; kept for Phase 1 route compatibility. */
    public function approve(Request $request, Project $project, int $id): JsonResponse
    {
        return $this->approveMr($request, $project, $id);
    }

    /** @deprecated Prefer typed rejectMr; kept for Phase 1 route compatibility. */
    public function reject(Request $request, Project $project, int $id): JsonResponse
    {
        return $this->rejectMr($request, $project, $id);
    }

    private function decide(Request $request, ApprovalRequest $approval, string $action): JsonResponse
    {
        Gate::authorize('approvals.view');

        if ($action === 'approve') {
            $comments = $request->validate(['comments' => ['nullable', 'string', 'max:2000']])['comments'] ?? null;
            $this->approvals->approve($approval, $request->user(), $comments);
            $message = 'Approved.';
        } elseif ($action === 'reject') {
            $comments = $request->validate(['comments' => ['required', 'string', 'max:2000']])['comments'];
            $this->approvals->reject($approval, $request->user(), $comments);
            $message = 'Rejected.';
        } else {
            $comments = $request->validate(['comments' => ['required', 'string', 'max:2000']])['comments'];
            $this->approvals->sendBack($approval, $request->user(), $comments);
            $message = 'Sent back.';
        }

        $doc = $approval->fresh()?->approvable ?? $approval->approvable;

        return response()->json([
            'message' => $message,
            'data' => $this->resourceFor($doc),
        ]);
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function pendingFor(Project $project, string $class, int $id): ApprovalRequest
    {
        Gate::authorize('approvals.view');

        $doc = $class::query()
            ->where('project_id', $project->id)
            ->findOrFail($id);

        return $doc->pendingApprovalRequest() ?? abort(404);
    }

    private function documentNumber(Model $doc): ?string
    {
        return match (true) {
            $doc instanceof MaterialRequest => $doc->request_number,
            $doc instanceof PurchaseOrder => $doc->po_number,
            $doc instanceof Grn => $doc->grn_number,
            $doc instanceof MaterialIssue => $doc->issue_number,
            $doc instanceof MaterialReturn => $doc->return_number,
            $doc instanceof Dpr => $doc->dpr_number,
            $doc instanceof WorkOrder => $doc->wo_number,
            $doc instanceof SubcontractorBill => $doc->bill_number,
            $doc instanceof ClientInvoice => $doc->invoice_number ?? "RA-{$doc->ra_sequence}",
            $doc instanceof VendorBill => $doc->bill_number,
            $doc instanceof Expense => $doc->expense_number,
            $doc instanceof RetentionRelease => $doc->release_number,
            $doc instanceof GatePass => $doc->pass_number,
            default => null,
        };
    }

    private function resourceFor(?Model $doc): mixed
    {
        return match (true) {
            $doc instanceof MaterialRequest => new MaterialRequisitionResource(
                $doc->load(['site:id,name', 'requester:id,name'])->loadCount('items')
            ),
            $doc instanceof PurchaseOrder => new PurchaseOrderResource(
                $doc->load(['vendor:id,code,name'])->loadCount('items')
            ),
            $doc instanceof Grn => new GrnResource(
                $doc->load(['purchaseOrder:id,po_number', 'vendor:id,code,name', 'warehouse:id,code,name'])
            ),
            $doc instanceof MaterialIssue => new MaterialIssueResource(
                $doc->load(['warehouse:id,code,name,type', 'issuedToUser:id,name'])->loadCount('items')
            ),
            $doc instanceof MaterialReturn => new MaterialReturnResource(
                $doc->load(['warehouse:id,code,name,type', 'vendor:id,code,name'])->loadCount('items')
            ),
            $doc instanceof Dpr => new DprResource(
                $doc->load(['engineer:id,name'])->loadCount('items')
            ),
            $doc instanceof WorkOrder => new WorkOrderResource(
                $doc->load(['subcontractor:id,code,name,gstin,mobile'])->loadCount('items')
            ),
            $doc instanceof SubcontractorBill => new SubcontractorBillResource(
                $doc->load(['subcontractor:id,code,name', 'workOrder:id,wo_number'])
            ),
            $doc instanceof ClientInvoice => new ClientInvoiceResource(
                $doc->load(['client:id,code,company_name,gstin', 'items.unit:id,symbol'])
            ),
            $doc instanceof VendorBill => new VendorBillResource(
                $doc->load(['vendor:id,code,name', 'purchaseOrder:id,po_number'])
            ),
            $doc instanceof Expense => new ExpenseResource(
                $doc->load(['category:id,name', 'vendor:id,name'])
            ),
            $doc instanceof RetentionRelease => new RetentionReleaseResource(
                $doc->load('releasable')
            ),
            $doc instanceof GatePass => new GatePassResource(
                $doc->load(['site:id,name', 'approver:id,name', 'verifier:id,name'])->loadCount('items')
            ),
            default => null,
        };
    }
}
