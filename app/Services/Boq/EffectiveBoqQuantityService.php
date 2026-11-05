<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\VariationChangeType;
use App\Enums\Boq\VariationStatus;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqVariationItem;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Support\Facades\DB;

/**
 * Stage 8 — centralized effective contractual quantity.
 *
 * Effective Qty = Original approved BOQ item quantity
 *               + Σ(quantity_change on Approved variations for that line_uid)
 *
 * Draft / Submitted / Rejected variations contribute ZERO.
 * New variation-originated items store their approved quantity on the materialized BoqItem;
 * their original row quantity is already the variation qty (no further delta).
 */
class EffectiveBoqQuantityService
{
    /**
     * Effective quantity for a BOQ item (original row never rewritten for qty variations).
     */
    public function forItem(BoqItem $item): string
    {
        $original = Decimal::of((string) $item->quantity);
        if ($item->is_variation_originated) {
            return $original->toQuantity();
        }

        $delta = $this->approvedQuantityDeltaByLineUid([(string) $item->line_uid])[(string) $item->line_uid] ?? '0.0000';
        $effective = $original->plus($delta);
        if ($effective->isNegative()) {
            return Decimal::zero()->toQuantity();
        }

        return $effective->toQuantity();
    }

    /**
     * @return array<string, string> line_uid => effective qty
     */
    public function mapForProject(Project $project): array
    {
        $items = BoqItem::query()
            ->whereHas('boq', fn ($q) => $q->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->where('status', '!=', BoqStatus::Revised->value))
            ->get(['id', 'line_uid', 'quantity', 'is_variation_originated']);

        $uids = $items->pluck('line_uid')->map(fn ($u) => (string) $u)->unique()->values()->all();
        $deltas = $this->approvedQuantityDeltaByLineUid($uids);

        $out = [];
        foreach ($items as $item) {
            $uid = (string) $item->line_uid;
            if ($item->is_variation_originated) {
                $out[$uid] = Num::qty($item->quantity);
                continue;
            }
            $effective = Decimal::of((string) $item->quantity)->plus($deltas[$uid] ?? '0');
            $out[$uid] = $effective->isNegative() ? Decimal::zero()->toQuantity() : $effective->toQuantity();
        }

        return $out;
    }

    /**
     * @param  list<string>  $lineUids
     * @return array<string, string> line_uid => signed quantity_change sum (approved only)
     */
    public function approvedQuantityDeltaByLineUid(array $lineUids): array
    {
        if ($lineUids === []) {
            return [];
        }

        $rows = BoqVariationItem::query()
            ->selectRaw('boq_variation_items.original_line_uid, SUM(boq_variation_items.quantity_change) as qty')
            ->join('boq_variations as v', 'v.id', '=', 'boq_variation_items.variation_id')
            ->where('v.status', VariationStatus::Approved->value)
            ->whereNull('v.deleted_at')
            ->whereNotNull('boq_variation_items.original_line_uid')
            ->whereIn('boq_variation_items.original_line_uid', $lineUids)
            ->whereIn('boq_variation_items.change_type', [
                VariationChangeType::QuantityIncrease->value,
                VariationChangeType::QuantityDecrease->value,
                VariationChangeType::OmittedItem->value,
                VariationChangeType::ScopeChange->value,
            ])
            ->groupBy('boq_variation_items.original_line_uid')
            ->pluck('qty', 'original_line_uid')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        return $rows;
    }

    /**
     * Effective selling rate for future billing (rate_change variations apply prospectively).
     * Historical certified invoices keep their snapshotted rates.
     */
    public function effectiveSellingRate(BoqItem $item): string
    {
        if ($item->is_variation_originated) {
            return Num::rate($item->client_rate ?: $item->selling_rate);
        }

        $latest = BoqVariationItem::query()
            ->select(['boq_variation_items.proposed_selling_rate', 'v.approved_at'])
            ->join('boq_variations as v', 'v.id', '=', 'boq_variation_items.variation_id')
            ->where('v.status', VariationStatus::Approved->value)
            ->whereNull('v.deleted_at')
            ->where('boq_variation_items.original_line_uid', $item->line_uid)
            ->where('boq_variation_items.change_type', VariationChangeType::RateChange->value)
            ->orderByDesc('v.approved_at')
            ->orderByDesc('boq_variation_items.id')
            ->first();

        if ($latest) {
            return Num::rate($latest->proposed_selling_rate);
        }

        return Num::rate($item->client_rate ?: $item->selling_rate);
    }

    /**
     * Project contract value snapshot (original vs approved variations vs effective).
     *
     * @return array{
     *   original_contract_value: string,
     *   approved_variation_value: string,
     *   pending_variation_value: string,
     *   effective_contract_value: string
     * }
     */
    public function contractValueSummary(Project $project): array
    {
        $original = Decimal::zero();

        // Prefer current approved BOQ only for original contract value.
        $approvedItems = BoqItem::query()
            ->whereHas('boq', fn ($q) => $q->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->where('status', BoqStatus::Approved->value)
                ->where('is_current', true))
            ->where(fn ($q) => $q->where('is_variation_originated', false)->orWhereNull('is_variation_originated'))
            ->get(['client_amount']);

        foreach ($approvedItems as $item) {
            $original = $original->plus((string) $item->client_amount);
        }

        $approvedVar = Decimal::of((string) (DB::table('boq_variations')
            ->where('project_id', $project->id)
            ->where('status', VariationStatus::Approved->value)
            ->whereNull('deleted_at')
            ->sum('revenue_impact') ?? '0'));

        $pendingVar = Decimal::of((string) (DB::table('boq_variations')
            ->where('project_id', $project->id)
            ->whereIn('status', [VariationStatus::Draft->value, VariationStatus::Submitted->value])
            ->whereNull('deleted_at')
            ->sum('revenue_impact') ?? '0'));

        return [
            'original_contract_value' => $original->toMoney(),
            'approved_variation_value' => $approvedVar->toMoney(),
            'pending_variation_value' => $pendingVar->toMoney(),
            'effective_contract_value' => $original->plus($approvedVar)->toMoney(),
        ];
    }
}
