<?php

namespace Tests\Concerns;

use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\StockAdjustmentItem;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Material;
use App\Models\Masters\Warehouse;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\MaterialIssueService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Inventory\StockMovement;
use App\Support\Math\Decimal;

/**
 * Phase 4 fixtures on top of the procurement team: the project's site store (WH-SITE), a central
 * store shared by the company, a second project "Tower B" with its own site store, and a task.
 * Store manager ($this->storekeeper) operates stock; the PM approves issues and returns; the
 * director (inventory.approve_adjustment, inventory.view_valuation) approves adjustments.
 */
trait BuildsInventoryData
{
    use BuildsProcurementData;

    public Warehouse $central;

    public Project $otherProject;

    public Warehouse $otherStore;

    public ProjectTask $task;

    private int $fakeSourceId = 1_000_000;

    public function setUpInventory(): void
    {
        $this->setUpProcurement();
        $this->siteStore();
        $this->otherProject = $this->makeTeamProject('Tower B');

        $this->inCompany($this->company, function () {
            $this->central = Warehouse::query()->create(['code' => 'WH-CEN', 'name' => 'Central store', 'type' => 'central']);
            $this->otherStore = Warehouse::query()->create(['project_id' => $this->otherProject->id, 'code' => 'WH-TWB', 'name' => 'Tower B store', 'type' => 'site']);
            $task = new ProjectTask(['wbs_code' => '1.1', 'name' => 'Raft foundation']);
            $task->forceFill(['project_id' => $this->project->id])->save();
            $this->task = $task;
        });
    }

    /**
     * Receive stock straight through the ledger (unit tests of valuation; documents use real flows).
     */
    public function stockIn(Material $material, string $qty, string $unitCost, ?Warehouse $warehouse = null, ?Project $project = null): StockTransaction
    {
        return $this->inCompany($this->company, fn () => app(StockLedgerService::class)->post(new StockMovement(
            source: $this->fakeSource(),
            type: StockTxnType::GrnIn,
            warehouse: $warehouse ?? $this->siteStore(),
            materialId: $material->id,
            quantity: Decimal::of($qty),
            date: now()->toDateString(),
            projectId: ($project ?? $this->project)->id,
            unitCost: Decimal::of($unitCost),
        )));
    }

    public function stockOut(Material $material, string $qty, ?Warehouse $warehouse = null): StockTransaction
    {
        return $this->inCompany($this->company, fn () => app(StockLedgerService::class)->post(new StockMovement(
            source: $this->fakeSource(),
            type: StockTxnType::IssueOut,
            warehouse: $warehouse ?? $this->siteStore(),
            materialId: $material->id,
            quantity: Decimal::of($qty),
            date: now()->toDateString(),
            projectId: $this->project->id,
        )));
    }

    public function fakeSource(): StockAdjustmentItem
    {
        return (new StockAdjustmentItem)->forceFill(['id' => ++$this->fakeSourceId]);
    }

    /**
     * @return array{quantity: string, avg_cost: string, value: string}
     */
    public function balanceOf(Material $material, ?Warehouse $warehouse = null): array
    {
        $balance = $this->inCompany($this->company, fn () => StockBalance::query()
            ->where('warehouse_id', ($warehouse ?? $this->siteStore())->id)->where('material_id', $material->id)->first());

        return [
            'quantity' => $balance?->quantity ?? '0.0000',
            'avg_cost' => $balance?->avg_cost ?? '0.0000',
            'value' => $balance?->value ?? '0.00',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function makeIssue(array $items, ?Warehouse $warehouse = null): MaterialIssue
    {
        return $this->inCompany($this->company, fn () => app(MaterialIssueService::class)->create($this->project, [
            'warehouse_id' => ($warehouse ?? $this->siteStore())->id,
            'issue_date' => now()->toDateString(),
            'issued_to_name' => 'Foreman Ramesh',
            'items' => $items,
        ]));
    }

    /**
     * Store manager submits → PM approves (posting happens inside the approval).
     */
    public function approveIssue(MaterialIssue $issue): MaterialIssue
    {
        return $this->inCompany($this->company, function () use ($issue) {
            app(MaterialIssueService::class)->submit($issue->fresh(), $this->storekeeper);
            app(ApprovalService::class)->approve($issue->fresh()->pendingApprovalRequest(), $this->pm);

            return $issue->fresh();
        });
    }
}
