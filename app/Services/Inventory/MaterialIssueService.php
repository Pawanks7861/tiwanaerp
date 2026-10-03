<?php

namespace App\Services\Inventory;

use App\Enums\CostHead;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\StockTxnType;
use App\Models\Boq\BoqItem;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\MaterialIssueItem;
use App\Models\Inventory\MaterialReturnItem;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ProjectCostLedgerService;
use App\Services\Inventory\Concerns\ResolvesInventoryLines;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Inventory\InventoryScope;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Material issues: draft → submitted (approval engine) → approved = posted. Posting locks the
 * stock balances, writes issue_out at the weighted average and the matching 'material' cost
 * ledger row with the BOQ line and task. Cancelling a posted issue writes reversals of both.
 */
class MaterialIssueService
{
    use ResolvesInventoryLines;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly StockLedgerService $ledger,
        private readonly ProjectCostLedgerService $costs,
        private readonly InventoryScope $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): MaterialIssue
    {
        return DB::transaction(function () use ($project, $data) {
            $issue = new MaterialIssue($this->header($project, $data));
            $issue->forceFill([
                'project_id' => $project->id,
                'issue_number' => $this->numbers->next('material_issue', $project),
                'status' => InventoryDocumentStatus::Draft,
            ])->save();

            $this->replaceLines($issue, $project, $data['items'] ?? []);

            return $issue;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MaterialIssue $issue, array $data): MaterialIssue
    {
        return DB::transaction(function () use ($issue, $data) {
            $issue = MaterialIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $issue->assertEditable();
            $project = Project::query()->findOrFail($issue->project_id);

            $issue->fill($this->header($project, $data))->save();
            $this->replaceLines($issue, $project, $data['items'] ?? []);

            return $issue;
        });
    }

    public function delete(MaterialIssue $issue): void
    {
        $issue->assertEditable();
        $issue->delete();
    }

    public function submit(MaterialIssue $issue, User $user): void
    {
        DB::transaction(function () use ($issue, $user) {
            $locked = MaterialIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $items = $locked->items()->with('material:id,name')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['issue' => 'Add at least one line before submitting.']);
            }

            $warehouse = $this->scope->warehouse(Project::query()->findOrFail($locked->project_id), $locked->warehouse_id);
            $this->assertAvailable($warehouse, $items->all());

            $this->approvals->submit($locked, $user);
            $issue->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the approval engine's transaction). Insufficient stock aborts the
     * whole approval. Repeating it on a posted issue changes nothing.
     */
    public function post(MaterialIssue $issue, ?int $approverId): void
    {
        DB::transaction(function () use ($issue, $approverId) {
            $issue = MaterialIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            if ($issue->status === InventoryDocumentStatus::Approved) {
                return;
            }
            if ($issue->status !== InventoryDocumentStatus::Submitted) {
                throw ValidationException::withMessages(['issue' => 'Only a submitted issue can be approved.']);
            }

            $warehouse = Warehouse::query()->withTrashed()->findOrFail($issue->warehouse_id);
            $items = $issue->items()->reorder('material_id')->orderBy('id')->get();

            foreach ($items as $item) {
                $transaction = $this->ledger->post(new StockMovement(
                    source: $item,
                    type: StockTxnType::IssueOut,
                    warehouse: $warehouse,
                    materialId: $item->material_id,
                    quantity: Decimal::of($item->quantity),
                    date: $issue->issue_date->toDateString(),
                    projectId: $issue->project_id,
                    remarks: $issue->issue_number,
                    userId: $approverId,
                ));

                $item->forceFill(['unit_cost' => $transaction->unit_cost, 'amount' => $transaction->value])->save();

                $this->costs->post(
                    source: $item,
                    projectId: $issue->project_id,
                    head: CostHead::Material,
                    amount: Decimal::of($transaction->value),
                    date: $issue->issue_date->toDateString(),
                    boqItemId: $item->boq_item_id,
                    boqLineUid: $item->boq_item_id ? BoqItem::query()->whereKey($item->boq_item_id)->value('line_uid') : null,
                    taskId: $item->task_id,
                    remarks: $issue->issue_number,
                    userId: $approverId,
                );
            }

            $issue->forceFill([
                'status' => InventoryDocumentStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
        });
    }

    /**
     * Cancel a posted issue: reverse every issue_out (stock comes back at the issued cost) and its
     * cost ledger row. Blocked while a submitted or approved site return refers to the issue.
     */
    public function cancel(MaterialIssue $issue, User $user, string $reason): void
    {
        DB::transaction(function () use ($issue, $user, $reason) {
            $locked = MaterialIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryDocumentStatus::Cancelled) {
                return;
            }
            if ($locked->status !== InventoryDocumentStatus::Approved) {
                throw ValidationException::withMessages(['issue' => 'Only a posted issue can be cancelled; withdraw or delete it instead.']);
            }

            $items = $locked->items()->reorder('material_id')->orderBy('id')->get();
            $returned = MaterialReturnItem::query()
                ->whereIn('material_issue_item_id', $items->pluck('id'))
                ->whereHas('materialReturn', fn (Builder $q) => $q->whereIn('status', [InventoryDocumentStatus::Submitted, InventoryDocumentStatus::Approved]))
                ->exists();
            if ($returned) {
                throw ValidationException::withMessages(['issue' => 'Material from this issue has been returned (or a return is pending). Cancel those returns first.']);
            }

            $remarks = "Cancelled {$locked->issue_number}: {$reason}";
            foreach ($items as $item) {
                $forward = StockTransaction::query()
                    ->where('source_type', $item->getMorphClass())->where('source_id', $item->id)
                    ->where('txn_type', StockTxnType::IssueOut)->first();
                if ($forward) {
                    $this->ledger->reverse($forward, $remarks, $user->id, 'issue');
                }

                if ($entry = $this->costs->entryFor($item, CostHead::Material)) {
                    $this->costs->reverse($entry, $remarks, $user->id);
                }
            }

            $locked->forceFill([
                'status' => InventoryDocumentStatus::Cancelled,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $issue->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Early, unlocked availability check (submit). Approval re-checks under lock.
     *
     * @param  list<MaterialIssueItem>  $items
     */
    private function assertAvailable(Warehouse $warehouse, array $items): void
    {
        $required = [];
        foreach ($items as $item) {
            $required[$item->material_id] = Decimal::of($required[$item->material_id] ?? '0')->plus($item->quantity);
        }

        foreach ($required as $materialId => $quantity) {
            $available = $this->ledger->balance($warehouse->id, $materialId)['quantity'];
            if ($quantity->greaterThan($available)) {
                $name = collect($items)->firstWhere('material_id', $materialId)?->material?->name ?? 'item';
                throw ValidationException::withMessages(['items' => "Insufficient stock of {$name} in {$warehouse->name}: available {$available->toQuantity()}, required {$quantity->toQuantity()}."]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows  material_id, quantity, boq_item_id, task_id, remarks
     */
    private function replaceLines(MaterialIssue $issue, Project $project, array $rows): void
    {
        MaterialIssueItem::query()->where('material_issue_id', $issue->id)->get()->each->delete();

        foreach (array_values($rows) as $index => $row) {
            $material = $this->material($row['material_id'] ?? null, "items.{$index}.material_id");
            $quantity = $this->quantity($row['quantity'] ?? null, "items.{$index}.quantity");

            (new MaterialIssueItem)->forceFill([
                'material_issue_id' => $issue->id,
                'material_id' => $material->id,
                'unit_id' => $material->unit_id,
                'boq_item_id' => $this->boqItem($project, $row['boq_item_id'] ?? null, "items.{$index}.boq_item_id")?->id,
                'task_id' => $this->task($project, $row['task_id'] ?? null, "items.{$index}.task_id")?->id,
                'quantity' => $quantity->toQuantity(),
                'remarks' => $row['remarks'] ?? null,
            ])->save();
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Project $project, array $data): array
    {
        if (blank($data['issued_to_user_id'] ?? null) && blank($data['subcontractor_id'] ?? null) && blank($data['issued_to_name'] ?? null)) {
            throw ValidationException::withMessages(['issued_to_name' => 'Say who receives the material: a team member, a subcontractor or a name.']);
        }

        return [
            'warehouse_id' => $this->scope->warehouse($project, $data['warehouse_id'] ?? null)->id,
            'issue_date' => $data['issue_date'],
            'issued_to_user_id' => $data['issued_to_user_id'] ?? null,
            'subcontractor_id' => $data['subcontractor_id'] ?? null,
            'issued_to_name' => $data['issued_to_name'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }
}
