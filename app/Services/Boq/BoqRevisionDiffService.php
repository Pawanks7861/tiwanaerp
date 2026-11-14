<?php

namespace App\Services\Boq;

use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMaterialNorm;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;

/**
 * Compares two BOQ revisions by stable line_uid (never by description alone).
 */
class BoqRevisionDiffService
{
    /**
     * @return array{
     *   previous: array{id:int,version:int,total_client_amount:string,total_cost_amount:?string},
     *   current: array{id:int,version:int,total_client_amount:string,total_cost_amount:?string},
     *   summary: array<string,int>,
     *   cost_summary:?array<string,mixed>,
     *   changes: list<array<string,mixed>>
     * }
     */
    public function compare(Boq $previous, Boq $current, bool $withCosts = false): array
    {
        $prevItems = BoqItem::query()->where('boq_id', $previous->id)->orderBy('sort_order')->orderBy('id')->get()->keyBy('line_uid');
        $currItems = BoqItem::query()->where('boq_id', $current->id)->orderBy('sort_order')->orderBy('id')->get()->keyBy('line_uid');

        $uids = $prevItems->keys()->merge($currItems->keys())->unique()->values();
        $changes = [];
        $summary = [
            'added' => 0,
            'removed' => 0,
            'quantity_changed' => 0,
            'rate_changed' => 0,
            'description_changed' => 0,
            'labour_rate_changed' => 0,
            'material_rate_changed' => 0,
            'cost_rate_changed' => 0,
            'unchanged' => 0,
        ];

        foreach ($uids as $uid) {
            $before = $prevItems->get($uid);
            $after = $currItems->get($uid);

            if ($before === null && $after !== null) {
                $summary['added']++;
                $changes[] = $this->row('added', $uid, null, $after, $withCosts);

                continue;
            }
            if ($before !== null && $after === null) {
                $summary['removed']++;
                $changes[] = $this->row('removed', $uid, $before, null, $withCosts);

                continue;
            }

            $fields = [];
            if (! Decimal::of((string) $before->quantity)->equals((string) $after->quantity)) {
                $fields['quantity'] = ['from' => (string) $before->quantity, 'to' => (string) $after->quantity];
                $summary['quantity_changed']++;
            }
            if (! Decimal::of((string) $before->client_rate)->equals((string) $after->client_rate)) {
                $fields['client_rate'] = ['from' => (string) $before->client_rate, 'to' => (string) $after->client_rate];
                $summary['rate_changed']++;
            }
            if (trim((string) $before->name) !== trim((string) $after->name)
                || trim((string) ($before->description ?? '')) !== trim((string) ($after->description ?? ''))) {
                $fields['description'] = [
                    'from' => trim(($before->item_code ? $before->item_code.' ' : '').$before->name),
                    'to' => trim(($after->item_code ? $after->item_code.' ' : '').$after->name),
                ];
                $summary['description_changed']++;
            }
            if ($withCosts && ! Decimal::of((string) ($before->labour_rate ?? '0'))->equals((string) ($after->labour_rate ?? '0'))) {
                $fields['labour_rate'] = ['from' => (string) ($before->labour_rate ?? '0'), 'to' => (string) ($after->labour_rate ?? '0')];
                $summary['labour_rate_changed']++;
            }
            if ($withCosts && ! Decimal::of((string) ($before->material_rate ?? '0'))->equals((string) ($after->material_rate ?? '0'))) {
                $fields['material_rate'] = ['from' => (string) ($before->material_rate ?? '0'), 'to' => (string) ($after->material_rate ?? '0')];
                $summary['material_rate_changed']++;
            }
            if ($withCosts && ! Decimal::of((string) ($before->cost_rate ?? '0'))->equals((string) ($after->cost_rate ?? '0'))) {
                $fields['cost_rate'] = ['from' => (string) ($before->cost_rate ?? '0'), 'to' => (string) ($after->cost_rate ?? '0')];
                $summary['cost_rate_changed']++;
            }
            if ($withCosts && ! Decimal::of((string) ($before->cost_amount ?? '0'))->equals((string) ($after->cost_amount ?? '0'))) {
                $fields['cost_amount'] = ['from' => (string) ($before->cost_amount ?? '0'), 'to' => (string) ($after->cost_amount ?? '0')];
            }

            if ($fields === []) {
                $summary['unchanged']++;

                continue;
            }

            $changes[] = $this->row('changed', $uid, $before, $after, $withCosts, $fields);
        }

        $amountChanged = ! Decimal::of((string) $previous->total_client_amount)->equals((string) $current->total_client_amount);

        return [
            'previous' => [
                'id' => $previous->id,
                'version' => (int) $previous->version,
                'total_client_amount' => (string) $previous->total_client_amount,
                'total_cost_amount' => $withCosts ? (string) $previous->total_cost_amount : null,
            ],
            'current' => [
                'id' => $current->id,
                'version' => (int) $current->version,
                'total_client_amount' => (string) $current->total_client_amount,
                'total_cost_amount' => $withCosts ? (string) $current->total_cost_amount : null,
            ],
            'total_amount_changed' => $amountChanged,
            'summary' => $summary,
            'cost_summary' => $withCosts ? $this->costSummary($previous, $current) : null,
            'material_summary' => $withCosts ? $this->materialSummary($previous, $current) : null,
            'bbs_summary' => $withCosts ? $this->bbsSummary($previous, $current) : null,
            'changes' => $changes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function costSummary(Boq $previous, Boq $current): array
    {
        $prevItems = BoqItem::query()->where('boq_id', $previous->id)->get(['quantity', 'material_rate', 'labour_rate', 'cost_amount', 'client_rate']);
        $currItems = BoqItem::query()->where('boq_id', $current->id)->get(['quantity', 'material_rate', 'labour_rate', 'cost_amount', 'client_rate']);

        $prevMaterialCost = Decimal::zero();
        $prevLabourCost = Decimal::zero();
        foreach ($prevItems as $item) {
            $qty = Decimal::of((string) $item->quantity);
            $prevMaterialCost = $prevMaterialCost->plus($qty->times((string) ($item->material_rate ?? '0')));
            $prevLabourCost = $prevLabourCost->plus($qty->times((string) ($item->labour_rate ?? '0')));
        }
        $currMaterialCost = Decimal::zero();
        $currLabourCost = Decimal::zero();
        foreach ($currItems as $item) {
            $qty = Decimal::of((string) $item->quantity);
            $currMaterialCost = $currMaterialCost->plus($qty->times((string) ($item->material_rate ?? '0')));
            $currLabourCost = $currLabourCost->plus($qty->times((string) ($item->labour_rate ?? '0')));
        }

        $prevInternal = Decimal::of((string) $previous->total_cost_amount);
        $currInternal = Decimal::of((string) $current->total_cost_amount);
        $prevCommercial = Decimal::of((string) $previous->total_client_amount);
        $currCommercial = Decimal::of((string) $current->total_client_amount);

        return [
            'material_cost' => [
                'previous' => $prevMaterialCost->toMoney(),
                'new' => $currMaterialCost->toMoney(),
                'difference' => $currMaterialCost->minus($prevMaterialCost)->toMoney(),
            ],
            'labour_cost' => [
                'previous' => $prevLabourCost->toMoney(),
                'new' => $currLabourCost->toMoney(),
                'difference' => $currLabourCost->minus($prevLabourCost)->toMoney(),
            ],
            'internal_cost' => [
                'previous' => $prevInternal->toMoney(),
                'new' => $currInternal->toMoney(),
                'difference' => $currInternal->minus($prevInternal)->toMoney(),
            ],
            'commercial_amount' => [
                'previous' => $prevCommercial->toMoney(),
                'new' => $currCommercial->toMoney(),
                'difference' => $currCommercial->minus($prevCommercial)->toMoney(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function materialSummary(Boq $previous, Boq $current): array
    {
        $prev = BoqMaterialNorm::query()
            ->where('boq_id', $previous->id)
            ->where('is_active', true)
            ->with('item:id,line_uid,quantity')
            ->get()
            ->groupBy(fn (BoqMaterialNorm $n) => $n->material_id.'|'.$n->consumption_unit);

        $curr = BoqMaterialNorm::query()
            ->where('boq_id', $current->id)
            ->where('is_active', true)
            ->with('item:id,line_uid,quantity')
            ->get()
            ->groupBy(fn (BoqMaterialNorm $n) => $n->material_id.'|'.$n->consumption_unit);

        $keys = $prev->keys()->merge($curr->keys())->unique();
        $rows = [];
        foreach ($keys as $key) {
            $prevReq = $this->normRequired($prev->get($key, collect()));
            $currReq = $this->normRequired($curr->get($key, collect()));
            if (Decimal::of($prevReq)->equals($currReq)) {
                continue;
            }
            [$materialId, $unit] = explode('|', (string) $key, 2);
            $rows[] = [
                'material_id' => (int) $materialId,
                'unit' => $unit,
                'previous_required_qty' => $prevReq,
                'new_required_qty' => $currReq,
                'qty_variance' => Decimal::of($currReq)->minus($prevReq)->toQuantity(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, BoqMaterialNorm>  $norms
     */
    private function normRequired($norms): string
    {
        $total = Decimal::zero();
        foreach ($norms as $norm) {
            $boqQty = (string) ($norm->item?->quantity ?? '0');
            $calc = MaterialNormCalculator::line([
                'boq_qty' => $boqQty,
                'consumption_qty' => (string) $norm->consumption_qty,
                'wastage_percent' => (string) ($norm->wastage_percent ?? '0'),
            ]);
            $total = $total->plus($calc['required_qty']);
        }

        return $total->toQuantity();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bbsSummary(Boq $previous, Boq $current): array
    {
        $prev = BoqBbs::query()->where('boq_id', $previous->id)->with('lines')->get();
        $curr = BoqBbs::query()->where('boq_id', $current->id)->with('lines')->get();

        $sumByDia = function ($bbsList): array {
            $out = [];
            foreach ($bbsList as $bbs) {
                foreach ($bbs->lines as $line) {
                    $d = (int) $line->diameter_mm;
                    $out[$d] = Decimal::of($out[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                }
            }

            return $out;
        };

        $prevByDia = $sumByDia($prev);
        $currByDia = $sumByDia($curr);
        $dias = collect(array_keys($prevByDia))->merge(array_keys($currByDia))->unique()->sort()->values();
        $rows = [];
        foreach ($dias as $dia) {
            $from = $prevByDia[$dia] ?? '0.0000';
            $to = $currByDia[$dia] ?? '0.0000';
            if (Decimal::of($from)->equals($to)) {
                continue;
            }
            $rows[] = [
                'diameter_mm' => (int) $dia,
                'previous_kg' => Decimal::of($from)->toQuantity(),
                'new_kg' => Decimal::of($to)->toQuantity(),
                'difference_kg' => Decimal::of($to)->minus($from)->toQuantity(),
                'previous_mt' => Decimal::of($from)->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                'new_mt' => Decimal::of($to)->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{from:string,to:string}>  $fields
     * @return array<string, mixed>
     */
    private function row(string $kind, string $uid, ?BoqItem $before, ?BoqItem $after, bool $withCosts, array $fields = []): array
    {
        $item = $after ?? $before;

        return [
            'kind' => $kind,
            'line_uid' => $uid,
            'item_code' => $item?->item_code,
            'name' => $item?->name,
            'fields' => $fields,
            'before' => $before ? $this->snapshot($before, $withCosts) : null,
            'after' => $after ? $this->snapshot($after, $withCosts) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BoqItem $item, bool $withCosts): array
    {
        $data = [
            'id' => $item->id,
            'item_code' => $item->item_code,
            'name' => $item->name,
            'description' => $item->description,
            'quantity' => (string) $item->quantity,
            'client_rate' => (string) $item->client_rate,
            'client_amount' => (string) $item->client_amount,
        ];
        if ($withCosts) {
            $data['labour_rate'] = (string) ($item->labour_rate ?? '0');
            $data['material_rate'] = (string) ($item->material_rate ?? '0');
            $data['cost_rate'] = (string) ($item->cost_rate ?? '0');
            $data['cost_amount'] = (string) ($item->cost_amount ?? '0');
        }

        return $data;
    }
}
