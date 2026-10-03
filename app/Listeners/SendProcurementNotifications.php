<?php

namespace App\Listeners;

use App\Events\Procurement\GrnApproved;
use App\Events\Procurement\MaterialRequestSubmitted;
use App\Events\Procurement\PurchaseOrderApproved;
use App\Models\Procurement\MaterialRequest;
use App\Models\Projects\Project;
use App\Models\User;
use App\Notifications\Procurement\ProcurementNotification;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Architecture J.4: MR submitted → purchase team; PO approved → purchase and store teams;
 * GRN approved → MR requesters and the project manager. Recipients are found by permission, not role name.
 */
class SendProcurementNotifications
{
    public function handleMaterialRequestSubmitted(MaterialRequestSubmitted $event): void
    {
        $mr = $event->materialRequest;
        $recipients = $this->withPermission($mr->company_id, ['rfq.create'])->reject(fn (User $u) => $u->id === $mr->requested_by);

        Notification::send($recipients, new ProcurementNotification(
            $mr->company_id, 'procurement.mr_submitted', 'Material request submitted',
            "{$mr->request_number} was submitted for approval.", route('projects.material-requests.show', [$mr->project_id, $mr->id]),
        ));
    }

    public function handlePurchaseOrderApproved(PurchaseOrderApproved $event): void
    {
        $po = $event->purchaseOrder;

        Notification::send($this->withPermission($po->company_id, ['purchase.create', 'grn.create']), new ProcurementNotification(
            $po->company_id, 'procurement.po_approved', 'Purchase order approved',
            "{$po->po_number} was approved.", route('projects.purchase-orders.show', [$po->project_id, $po->id]),
        ));
    }

    public function handleGrnApproved(GrnApproved $event): void
    {
        $grn = $event->grn;
        $requesterIds = MaterialRequest::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $grn->company_id)
            ->whereHas('items.purchaseOrderItems.grnItems', fn (Builder $q) => $q->where('grn_id', $grn->id))
            ->pluck('requested_by');
        $managerId = Project::query()->withoutGlobalScope(CompanyScope::class)->whereKey($grn->project_id)->value('project_manager_id');

        $recipients = User::query()->where('is_active', true)
            ->whereIn('id', $requesterIds->push($managerId)->filter()->unique()->all())
            ->get();

        Notification::send($recipients, new ProcurementNotification(
            $grn->company_id, 'procurement.grn_approved', 'Goods received',
            "{$grn->grn_number} was approved.", route('projects.grns.show', [$grn->project_id, $grn->id]),
        ));
    }

    /**
     * Active members of the company whose roles there grant any of the permissions.
     *
     * @param  list<string>  $permissions
     * @return Collection<int, User>
     */
    private function withPermission(int $companyId, array $permissions): Collection
    {
        $tables = config('permission.table_names');
        $teamKey = config('permission.column_names.team_foreign_key');

        return User::query()
            ->where('is_active', true)
            ->whereHas('memberships', fn (Builder $m) => $m->where('company_id', $companyId)->where('is_active', true))
            ->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from($tables['model_has_roles'].' as mhr')
                ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
                ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
                ->whereColumn('mhr.model_id', 'users.id')
                ->where('mhr.model_type', (new User)->getMorphClass())
                ->where('mhr.'.$teamKey, $companyId)
                ->whereIn('p.name', $permissions))
            ->get();
    }
}
