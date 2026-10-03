<?php

namespace App\Services\Inventory;

use App\Enums\CostHead;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\MaterialReturnType;
use App\Enums\Inventory\StockTxnType;
use App\Enums\Procurement\GrnStatus;
use App\Models\Boq\BoqItem;
use App\Models\Inventory\MaterialIssueItem;
use App\Models\Inventory\MaterialReturn;
use App\Models\Inventory\MaterialReturnItem;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Vendor;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\Grn;
use App\Models\Procurement\GrnItem;
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
 * Material returns (approval engine, single PM step by default).
 *
 * site_to_store: lines refer to posted issue lines of this project; at most the issued quantity
 * less other returns comes back, valued at the original issue cost, and the project cost ledger
 * is credited on the same BOQ line / task. to_vendor: goods leave a store at the weighted average
 * (stock checked under lock); lines may refer to an approved GRN of the vendor. No debit note.
 */
class MaterialReturnService
{
    use ResolvesInventoryLines;

    private const COUNTED = [InventoryDocumentStatus::Submitted, InventoryDocumentStatus::Approved];

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
    public function create(Project $project, MaterialReturnType $type, array $data): MaterialReturn
    {
        return DB::transaction(function () use ($project, $type, $data) {
            $return = new MaterialReturn;
            $return->forceFill([
                'project_id' => $project->id,
                'return_type' => $type,
                'return_number' => $this->numbers->next('material_return', $project),
                'status' => InventoryDocumentStatus::Draft,
            ]);
            $return->fill($this->header($project, $type, $data))->save();

            $this->replaceLines($return, $project, $data['items'] ?? []);

            return $return;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MaterialReturn $return, array $data): MaterialReturn
    {
        return DB::transaction(function () use ($return, $data) {
            $return = MaterialReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->assertEditable();
            $project = Project::query()->findOrFail($return->project_id);

            $return->fill($this->header($project, $return->return_type, $data))->save();
            $this->replaceLines($return, $project, $data['items'] ?? []);

            return $return;
        });
    }

    public function delete(MaterialReturn $return): void
    {
        $return->assertEditable();
        $return->delete();
    }

    public function submit(MaterialReturn $return, User $user): void
    {
        DB::transaction(function () use ($return, $user) {
            $locked = MaterialReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $items = $locked->items()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['return' => 'Add at least one line before submitting.']);
            }
            $this->assertReturnable($locked, $items->all(), includePending: true);

            $this->approvals->submit($locked, $user);
            $return->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the approval engine's transaction). Repeating it changes nothing.
     */
    public function post(MaterialReturn $return, ?int $approverId): void
    {
        DB::transaction(function () use ($return, $approverId) {
            $return = MaterialReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status === InventoryDocumentStatus::Approved) {
                return;
            }
            if ($return->status !== InventoryDocumentStatus::Submitted) {
                throw ValidationException::withMessages(['return' => 'Only a submitted return can be approved.']);
            }

            $items = $return->items()->reorder('material_id')->orderBy('id')->get();
            $this->lockReferences($items->all());
            $this->assertReturnable($return, $items->all(), includePending: false);
            $warehouse = Warehouse::query()->withTrashed()->findOrFail($return->warehouse_id);

            foreach ($items as $item) {
                $return->return_type === MaterialReturnType::SiteToStore
                    ? $this->postSiteReturn($return, $item, $warehouse, $approverId)
                    : $this->postVendorReturn($return, $item, $warehouse, $approverId);
            }

            $return->forceFill([
                'status' => InventoryDocumentStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
        });
    }

    /**
     * Cancel a posted return by reversing its stock (and, for site returns, cost ledger) rows.
     * A site return whose stock has since been issued or moved cannot be reversed.
     */
    public function cancel(MaterialReturn $return, User $user, string $reason): void
    {
        DB::transaction(function () use ($return, $user, $reason) {
            $locked = MaterialReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryDocumentStatus::Cancelled) {
                return;
            }
            if ($locked->status !== InventoryDocumentStatus::Approved) {
                throw ValidationException::withMessages(['return' => 'Only a posted return can be cancelled; withdraw or delete it instead.']);
            }

            $type = $locked->return_type === MaterialReturnType::SiteToStore ? StockTxnType::ReturnIn : StockTxnType::ReturnToVendorOut;
            $remarks = "Cancelled {$locked->return_number}: {$reason}";

            foreach ($locked->items()->reorder('material_id')->orderBy('id')->get() as $item) {
                $forward = StockTransaction::query()
                    ->where('source_type', $item->getMorphClass())->where('source_id', $item->id)
                    ->where('txn_type', $type)->first();
                if ($forward) {
                    $this->ledger->reverse($forward, $remarks, $user->id, 'return');
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
            $return->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Returnable issue lines of the project (posted issues) with what may still come back.
     *
     * @return list<array<string, mixed>>
     */
    public function returnableIssueLines(Project $project, ?MaterialReturn $except = null): array
    {
        $items = MaterialIssueItem::query()
            ->whereHas('issue', fn (Builder $q) => $q->where('project_id', $project->id)->where('status', InventoryDocumentStatus::Approved))
            ->with(['issue:id,issue_number,issue_date,warehouse_id', 'material:id,code,name', 'unit:id,symbol', 'boqItem:id,item_code,name', 'task:id,wbs_code,name'])
            ->orderByDesc('material_issue_id')->orderBy('id')
            ->limit(500)
            ->get();
        $returned = $this->returnedQuantities('material_issue_item_id', $items->pluck('id')->all(), $except?->id, includePending: true);

        return $items->map(function (MaterialIssueItem $item) use ($returned) {
            $open = Decimal::of($item->quantity)->minus($returned[$item->id] ?? '0');

            return [
                'value' => $item->id,
                'label' => "{$item->issue->issue_number} · {$item->material->name}",
                'description' => 'Issued '.Decimal::of($item->quantity)->toQuantity().' '.$item->unit?->symbol.' · returnable '.$open->toQuantity(),
                'material_id' => $item->material_id,
                'unit' => $item->unit?->symbol,
                'issued_qty' => $item->quantity,
                'returnable_qty' => ($open->isNegative() ? Decimal::zero() : $open)->toQuantity(),
                'warehouse_id' => $item->issue->warehouse_id,
            ];
        })->filter(fn (array $row) => Decimal::of($row['returnable_qty'])->isPositive())->values()->all();
    }

    /**
     * Approved GRNs of the project, with their lines and what may still go back to the vendor.
     *
     * @return list<array<string, mixed>>
     */
    public function returnableGrns(Project $project, ?MaterialReturn $except = null): array
    {
        $grns = Grn::query()->where('project_id', $project->id)->where('status', GrnStatus::Approved)
            ->with(['vendor:id,name', 'items.material:id,code,name', 'items.unit:id,symbol'])
            ->orderByDesc('id')->limit(200)->get();
        $returned = $this->returnedQuantities('grn_item_id', $grns->flatMap->items->pluck('id')->all(), $except?->id, includePending: true);

        return $grns->map(fn (Grn $grn) => [
            'value' => $grn->id,
            'label' => $grn->grn_number,
            'description' => $grn->vendor?->name,
            'vendor_id' => $grn->vendor_id,
            'warehouse_id' => $grn->warehouse_id,
            'items' => $grn->items->map(function (GrnItem $item) use ($returned) {
                $open = Decimal::of($item->accepted_qty)->minus($returned[$item->id] ?? '0');

                return [
                    'grn_item_id' => $item->id,
                    'material_id' => $item->material_id,
                    'material' => $item->material?->name,
                    'unit' => $item->unit?->symbol,
                    'accepted_qty' => $item->accepted_qty,
                    'returnable_qty' => ($open->isNegative() ? Decimal::zero() : $open)->toQuantity(),
                ];
            })->values()->all(),
        ])->all();
    }

    private function postSiteReturn(MaterialReturn $return, MaterialReturnItem $item, Warehouse $warehouse, ?int $approverId): void
    {
        $issueItem = MaterialIssueItem::query()->findOrFail($item->material_issue_item_id);
        $quantity = Decimal::of($item->quantity);
        $unitCost = Decimal::of($issueItem->unit_cost);

        // The return that brings back the last of an issue line takes exactly the rest of its value.
        $approved = MaterialReturnItem::query()->where('material_issue_item_id', $issueItem->id)->whereKeyNot($item->id)
            ->whereHas('materialReturn', fn (Builder $q) => $q->where('status', InventoryDocumentStatus::Approved))
            ->get(['quantity', 'value']);
        $remainingQty = Decimal::of($issueItem->quantity)->minus(Decimal::sum($approved->pluck('quantity')->all()));
        $remainingValue = Decimal::of($issueItem->amount)->minus(Decimal::sum($approved->pluck('value')->all()));
        $value = $quantity->equals($remainingQty) ? $remainingValue : $quantity->times($unitCost)->round(Decimal::MONEY_SCALE);

        $transaction = $this->ledger->post(new StockMovement(
            source: $item,
            type: StockTxnType::ReturnIn,
            warehouse: $warehouse,
            materialId: $item->material_id,
            quantity: $quantity,
            date: $return->return_date->toDateString(),
            projectId: $return->project_id,
            unitCost: $unitCost,
            value: $value,
            remarks: $return->return_number,
            userId: $approverId,
        ));
        $item->forceFill(['unit_cost' => $transaction->unit_cost, 'value' => $transaction->value])->save();

        $this->costs->post(
            source: $item,
            projectId: $return->project_id,
            head: CostHead::Material,
            amount: Decimal::of($transaction->value)->negate(),
            date: $return->return_date->toDateString(),
            boqItemId: $issueItem->boq_item_id,
            boqLineUid: $issueItem->boq_item_id ? BoqItem::query()->whereKey($issueItem->boq_item_id)->value('line_uid') : null,
            taskId: $issueItem->task_id,
            remarks: $return->return_number,
            userId: $approverId,
        );
    }

    private function postVendorReturn(MaterialReturn $return, MaterialReturnItem $item, Warehouse $warehouse, ?int $approverId): void
    {
        $transaction = $this->ledger->post(new StockMovement(
            source: $item,
            type: StockTxnType::ReturnToVendorOut,
            warehouse: $warehouse,
            materialId: $item->material_id,
            quantity: Decimal::of($item->quantity),
            date: $return->return_date->toDateString(),
            projectId: $return->project_id,
            remarks: $return->return_number,
            userId: $approverId,
        ));
        $item->forceFill(['unit_cost' => $transaction->unit_cost, 'value' => $transaction->value])->save();
    }

    /**
     * Returned quantities may not exceed what was issued (site) or accepted (vendor + GRN).
     *
     * @param  list<MaterialReturnItem>  $items
     */
    private function assertReturnable(MaterialReturn $return, array $items, bool $includePending): void
    {
        $column = $return->return_type === MaterialReturnType::SiteToStore ? 'material_issue_item_id' : 'grn_item_id';
        $referenced = array_values(array_filter(array_map(fn (MaterialReturnItem $i) => $i->{$column}, $items)));
        if ($referenced === []) {
            return;
        }

        $elsewhere = $this->returnedQuantities($column, $referenced, $return->id, $includePending);
        $limits = $column === 'material_issue_item_id'
            ? MaterialIssueItem::query()->whereIn('id', $referenced)
                ->whereHas('issue', fn (Builder $q) => $q->where('status', InventoryDocumentStatus::Approved))
                ->pluck('quantity', 'id')
            : GrnItem::query()->whereIn('id', $referenced)->pluck('accepted_qty', 'id');

        foreach ($items as $index => $item) {
            $ref = $item->{$column};
            if ($ref === null) {
                continue;
            }
            if (! isset($limits[$ref])) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'The referenced issue is no longer posted.']);
            }

            $open = Decimal::of($limits[$ref])->minus($elsewhere[$ref] ?? '0');
            if (Decimal::of($item->quantity)->greaterThan($open)) {
                $what = $column === 'material_issue_item_id' ? 'issued' : 'accepted on the GRN';
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'At most '.($open->isNegative() ? Decimal::zero() : $open)->toQuantity()." can be returned (quantity {$what} less other returns)."]);
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function returnedQuantities(string $column, array $ids, ?int $exceptReturnId, bool $includePending): array
    {
        if ($ids === []) {
            return [];
        }

        $statuses = $includePending ? self::COUNTED : [InventoryDocumentStatus::Approved];
        $totals = [];

        MaterialReturnItem::query()
            ->whereIn($column, $ids)
            ->whereHas('materialReturn', fn (Builder $q) => $q->whereIn('status', $statuses)->when($exceptReturnId, fn (Builder $w, int $id) => $w->whereKeyNot($id)))
            ->get([$column, 'quantity'])
            ->each(function (MaterialReturnItem $line) use (&$totals, $column) {
                $totals[$line->{$column}] = Decimal::of($totals[$line->{$column}] ?? '0')->plus($line->quantity)->toQuantity();
            });

        return $totals;
    }

    /**
     * Serialise concurrent approvals against the same issue / GRN lines.
     *
     * @param  list<MaterialReturnItem>  $items
     */
    private function lockReferences(array $items): void
    {
        $issueItemIds = array_values(array_filter(array_map(fn (MaterialReturnItem $i) => $i->material_issue_item_id, $items)));
        $grnItemIds = array_values(array_filter(array_map(fn (MaterialReturnItem $i) => $i->grn_item_id, $items)));

        if ($issueItemIds !== []) {
            MaterialIssueItem::query()->whereIn('id', $issueItemIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        if ($grnItemIds !== []) {
            GrnItem::query()->whereIn('id', $grnItemIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceLines(MaterialReturn $return, Project $project, array $rows): void
    {
        MaterialReturnItem::query()->where('material_return_id', $return->id)->get()->each->delete();

        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }

        $seen = [];
        $lines = [];
        foreach (array_values($rows) as $index => $row) {
            $quantity = $this->quantity($row['quantity'] ?? null, "items.{$index}.quantity");
            $attributes = $return->return_type === MaterialReturnType::SiteToStore
                ? $this->siteLine($project, $row, $index)
                : $this->vendorLine($return, $row, $index);

            $key = ($attributes['material_issue_item_id'] ?? null) ?? ($attributes['grn_item_id'] ?? null) ?? 'm'.$attributes['material_id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["items.{$index}.material_id" => 'Each line can only appear once.']);
            }
            $seen[$key] = true;

            $line = (new MaterialReturnItem)->forceFill($attributes + [
                'material_return_id' => $return->id,
                'quantity' => $quantity->toQuantity(),
                'remarks' => $row['remarks'] ?? null,
            ]);
            $line->save();
            $lines[$index] = $line;
        }

        $this->assertReturnable($return, $lines, includePending: true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function siteLine(Project $project, array $row, int $index): array
    {
        $issueItem = is_numeric($row['material_issue_item_id'] ?? null)
            ? MaterialIssueItem::query()->whereKey((int) $row['material_issue_item_id'])
                ->whereHas('issue', fn (Builder $q) => $q->where('project_id', $project->id)->where('status', InventoryDocumentStatus::Approved))
                ->first()
            : null;

        if ($issueItem === null) {
            throw ValidationException::withMessages(["items.{$index}.material_issue_item_id" => 'Choose a line of a posted issue of this project.']);
        }

        return ['material_issue_item_id' => $issueItem->id, 'grn_item_id' => null, 'material_id' => $issueItem->material_id, 'unit_id' => $issueItem->unit_id];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function vendorLine(MaterialReturn $return, array $row, int $index): array
    {
        if ($return->grn_id !== null) {
            $grnItem = is_numeric($row['grn_item_id'] ?? null)
                ? GrnItem::query()->where('grn_id', $return->grn_id)->whereKey((int) $row['grn_item_id'])->first()
                : null;

            if ($grnItem === null) {
                throw ValidationException::withMessages(["items.{$index}.grn_item_id" => 'Choose a line of the selected GRN.']);
            }

            return ['material_issue_item_id' => null, 'grn_item_id' => $grnItem->id, 'material_id' => $grnItem->material_id, 'unit_id' => $grnItem->unit_id];
        }

        $material = $this->material($row['material_id'] ?? null, "items.{$index}.material_id");

        return ['material_issue_item_id' => null, 'grn_item_id' => null, 'material_id' => $material->id, 'unit_id' => $material->unit_id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Project $project, MaterialReturnType $type, array $data): array
    {
        $header = [
            'return_date' => $data['return_date'],
            'warehouse_id' => $this->scope->warehouse($project, $data['warehouse_id'] ?? null)->id,
            'reason' => $data['reason'],
            'remarks' => $data['remarks'] ?? null,
            'vendor_id' => null,
            'grn_id' => null,
        ];

        if ($type === MaterialReturnType::SiteToStore) {
            return $header;
        }

        if (filled($data['grn_id'] ?? null)) {
            $grn = is_numeric($data['grn_id'])
                ? Grn::query()->where('project_id', $project->id)->where('status', GrnStatus::Approved)->whereKey((int) $data['grn_id'])->first()
                : null;
            if ($grn === null) {
                throw ValidationException::withMessages(['grn_id' => 'Choose an approved GRN of this project.']);
            }

            return ['vendor_id' => $grn->vendor_id, 'grn_id' => $grn->id] + $header;
        }

        $vendor = is_numeric($data['vendor_id'] ?? null) ? Vendor::query()->whereKey((int) $data['vendor_id'])->first() : null;
        if ($vendor === null) {
            throw ValidationException::withMessages(['vendor_id' => 'Choose the vendor the goods go back to.']);
        }

        return ['vendor_id' => $vendor->id] + $header;
    }
}
