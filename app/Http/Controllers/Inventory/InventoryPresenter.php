<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\MaterialIssueItem;
use App\Models\Inventory\MaterialReturnItem;
use App\Models\Inventory\StockAdjustmentItem;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockTransferItem;
use App\Models\Inventory\StockTransferReceiptItem;
use App\Models\Masters\Material;
use App\Models\Procurement\GrnItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Inventory\InventoryScope;
use Illuminate\Support\Collection;

/**
 * Shared shaping of inventory data for Inertia pages. Cost and value fields are only ever
 * included for holders of inventory.view_valuation.
 */
class InventoryPresenter
{
    public static function seesValuation(User $user): bool
    {
        return $user->can('inventory.view_valuation');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function materialOptions(): array
    {
        return Material::query()->active()->with('unit:id,symbol')->orderBy('name')->limit(2000)->get(['id', 'code', 'name', 'unit_id'])
            ->map(fn (Material $m) => [
                'value' => $m->id,
                'label' => $m->name,
                'description' => $m->code.($m->unit ? ' · '.$m->unit->symbol : ''),
                'unit' => $m->unit?->symbol,
            ])->all();
    }

    /**
     * Book quantity per warehouse and material for the stores reachable from the project
     * (non-zero balances only), for form hints. Quantities only.
     *
     * @return array<int, array<int, string>>
     */
    public static function stockMap(Project $project): array
    {
        $map = [];
        StockBalance::query()->whereIn('warehouse_id', app(InventoryScope::class)->warehouseIds($project))
            ->where('quantity', '>', 0)->get(['warehouse_id', 'material_id', 'quantity'])
            ->each(function (StockBalance $b) use (&$map) {
                $map[$b->warehouse_id][$b->material_id] = $b->quantity;
            });

        return $map;
    }

    /**
     * Document references for ledger rows, keyed "source_type:source_id". Rows belonging to other
     * projects (shared central store) are not resolved, so their documents never leak.
     *
     * @param  Collection<int, object>  $rows  objects with source_type, source_id, project_id
     * @return array<string, array{number: string, url: string|null}>
     */
    public static function references(Collection $rows, Project $project): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ((int) $row->project_id === $project->id) {
                $ids[$row->source_type][] = (int) $row->source_id;
            }
        }

        $refs = [];
        $add = function (string $type, iterable $models, callable $describe) use (&$refs) {
            foreach ($models as $model) {
                $refs["{$type}:{$model->id}"] = $describe($model);
            }
        };

        $add('grn_item', GrnItem::query()->whereIn('id', $ids['grn_item'] ?? [])->with('grn:id,project_id,grn_number')->get(),
            fn (GrnItem $i) => ['number' => $i->grn->grn_number, 'url' => route('projects.grns.show', [$i->grn->project_id, $i->grn_id])]);
        $add('material_issue_item', MaterialIssueItem::query()->whereIn('id', $ids['material_issue_item'] ?? [])->with('issue:id,project_id,issue_number')->get(),
            fn (MaterialIssueItem $i) => ['number' => $i->issue->issue_number, 'url' => route('projects.material-issues.show', [$i->issue->project_id, $i->material_issue_id])]);
        $add('stock_transfer_item', StockTransferItem::query()->whereIn('id', $ids['stock_transfer_item'] ?? [])->with('transfer:id,project_id,transfer_number')->get(),
            fn (StockTransferItem $i) => ['number' => $i->transfer->transfer_number, 'url' => route('projects.stock-transfers.show', [$i->transfer->project_id, $i->stock_transfer_id])]);
        $add('stock_transfer_receipt_item', StockTransferReceiptItem::query()->whereIn('id', $ids['stock_transfer_receipt_item'] ?? [])->with('transferItem.transfer:id,project_id,transfer_number')->get(),
            fn (StockTransferReceiptItem $i) => ['number' => $i->transferItem->transfer->transfer_number.' (receipt)', 'url' => route('projects.stock-transfers.show', [$i->transferItem->transfer->project_id, $i->transferItem->stock_transfer_id])]);
        $add('material_return_item', MaterialReturnItem::query()->whereIn('id', $ids['material_return_item'] ?? [])->with('materialReturn:id,project_id,return_number')->get(),
            fn (MaterialReturnItem $i) => ['number' => $i->materialReturn->return_number, 'url' => route('projects.material-returns.show', [$i->materialReturn->project_id, $i->material_return_id])]);
        $add('stock_adjustment_item', StockAdjustmentItem::query()->whereIn('id', $ids['stock_adjustment_item'] ?? [])->with('adjustment:id,project_id,adjustment_number')->get(),
            fn (StockAdjustmentItem $i) => ['number' => $i->adjustment->adjustment_number, 'url' => route('projects.stock-adjustments.show', [$i->adjustment->project_id, $i->stock_adjustment_id])]);

        return $refs;
    }

    public static function txnLabel(string|StockTxnType $type): string
    {
        return ($type instanceof StockTxnType ? $type : StockTxnType::from($type))->label();
    }
}
