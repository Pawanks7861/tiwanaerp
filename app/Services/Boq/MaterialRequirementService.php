<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsStatus;
use App\Enums\Inventory\StockTxnType;
use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMaterialNorm;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockTransaction;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Project material requirement planning query.
 *
 * Remaining Requirement = max(0, Required Qty − Received Qty)
 *
 * Stage 5 double-count rule: for materials mapped from approved BBS diameter
 * summaries, Approved BBS required weight (kg) is authoritative. Material Norm
 * rows for those same materials are ignored for requirement planning (norms
 * are retained historically). Non-steel / unmapped materials continue from norms.
 */
class MaterialRequirementService
{
    public function __construct(private readonly EffectiveBoqQuantityService $effectiveQty) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function projectSummary(Project $project, ?int $materialId = null): array
    {
        $bbsRows = $this->approvedBbsRequirements($project->id, $materialId);
        $bbsMaterialIds = collect($bbsRows)->pluck('material_id')->unique()->all();

        $effective = $this->effectiveQty->mapForProject($project);
        $normRows = $this->activeNorms($project->id, $materialId)
            ->map(function ($r) use ($effective) {
                $uid = (string) $r->boq_line_uid;
                if (isset($effective[$uid])) {
                    $r->boq_qty = $effective[$uid];
                }

                return $r;
            })
            ->reject(fn ($r) => in_array((int) $r->material_id, $bbsMaterialIds, true));

        $materialIds = collect($bbsRows)->pluck('material_id')
            ->merge($normRows->pluck('material_id'))
            ->unique()
            ->values()
            ->all();

        if ($materialIds === []) {
            return [];
        }

        $proc = $this->procurementAggregates($project->id, $materialIds);
        $stock = $this->centralStock($materialIds);
        $issues = $this->issueReturnAggregates($project->id, $materialIds);

        $out = [];

        foreach ($bbsRows as $row) {
            $mid = (int) $row['material_id'];
            $received = $proc['received'][$mid] ?? '0.0000';
            $requiredStr = $row['required_qty'];
            $out[] = [
                'material_id' => $mid,
                'material_code' => $row['material_code'],
                'material_name' => $row['material_name'],
                'unit' => $row['unit'],
                'base_requirement' => $row['base_requirement'],
                'wastage_percent' => $row['wastage_percent'],
                'wastage_qty' => $row['wastage_qty'],
                'required_qty' => $requiredStr,
                'mr_qty' => $proc['mr'][$mid] ?? '0.0000',
                'po_qty' => $proc['po'][$mid] ?? '0.0000',
                'received_qty' => $received,
                'issued_qty' => $issues['issued'][$mid] ?? '0.0000',
                'returned_qty' => $issues['returned'][$mid] ?? '0.0000',
                'available_stock' => $stock[$mid] ?? '0.0000',
                'remaining_requirement' => MaterialNormCalculator::remaining($requiredStr, $received),
                'over_procured_qty' => MaterialNormCalculator::overProcured($requiredStr, $received),
                'sources_count' => $row['sources_count'],
                'requirement_source' => 'BBS',
                'requirement_source_label' => 'BBS',
            ];
        }

        $grouped = $normRows->groupBy(fn ($r) => $r->material_id.'|'.mb_strtolower((string) $r->consumption_unit));

        foreach ($grouped as $rows) {
            /** @var Collection<int, object> $rows */
            $first = $rows->first();
            $base = Decimal::zero();
            $wastageQty = Decimal::zero();
            $required = Decimal::zero();
            $wastageWeighted = Decimal::zero();
            $baseForWeight = Decimal::zero();

            foreach ($rows as $row) {
                $calc = MaterialNormCalculator::line([
                    'boq_qty' => (string) $row->boq_qty,
                    'consumption_qty' => (string) $row->consumption_qty,
                    'wastage_percent' => (string) $row->wastage_percent,
                ]);
                $base = $base->plus($calc['base_requirement']);
                $wastageQty = $wastageQty->plus($calc['wastage_qty']);
                $required = $required->plus($calc['required_qty']);
                $lineBase = Decimal::of($calc['base_requirement']);
                if ($lineBase->isPositive()) {
                    $wastageWeighted = $wastageWeighted->plus($lineBase->times($row->wastage_percent));
                    $baseForWeight = $baseForWeight->plus($lineBase);
                }
            }

            $avgWastage = $baseForWeight->isPositive()
                ? $wastageWeighted->dividedBy($baseForWeight, Decimal::PERCENT_SCALE)->toString()
                : '0.0000';

            $mid = (int) $first->material_id;
            $received = $proc['received'][$mid] ?? '0.0000';
            $requiredStr = $required->toQuantity();

            $out[] = [
                'material_id' => $mid,
                'material_code' => $first->material_code,
                'material_name' => $first->material_name,
                'unit' => $first->consumption_unit,
                'base_requirement' => $base->toQuantity(),
                'wastage_percent' => $avgWastage,
                'wastage_qty' => $wastageQty->toQuantity(),
                'required_qty' => $requiredStr,
                'mr_qty' => $proc['mr'][$mid] ?? '0.0000',
                'po_qty' => $proc['po'][$mid] ?? '0.0000',
                'received_qty' => $received,
                'issued_qty' => $issues['issued'][$mid] ?? '0.0000',
                'returned_qty' => $issues['returned'][$mid] ?? '0.0000',
                'available_stock' => $stock[$mid] ?? '0.0000',
                'remaining_requirement' => MaterialNormCalculator::remaining($requiredStr, $received),
                'over_procured_qty' => MaterialNormCalculator::overProcured($requiredStr, $received),
                'sources_count' => $rows->count(),
                'requirement_source' => 'Material Norm',
                'requirement_source_label' => 'Material Norm',
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['material_name'], $b['material_name']));

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function materialDrillDown(Project $project, int $materialId, ?string $unit = null): array
    {
        $bbsAuthority = $this->approvedBbsRequirements($project->id, $materialId);
        if ($bbsAuthority !== []) {
            return $this->bbsDrillDown($project->id, $materialId);
        }

        $rows = $this->activeNorms($project->id, $materialId);
        if ($unit !== null) {
            $rows = $rows->filter(fn ($r) => mb_strtolower((string) $r->consumption_unit) === mb_strtolower($unit));
        }

        $effective = $this->effectiveQty->mapForProject($project);

        return $rows->map(function ($row) use ($effective) {
            $boqQty = $effective[(string) $row->boq_line_uid] ?? (string) $row->boq_qty;
            $calc = MaterialNormCalculator::line([
                'boq_qty' => $boqQty,
                'consumption_qty' => (string) $row->consumption_qty,
                'wastage_percent' => (string) $row->wastage_percent,
            ]);

            return [
                'requirement_source' => 'Material Norm',
                'norm_id' => $row->id,
                'boq_id' => $row->boq_id,
                'boq_number' => $row->boq_number,
                'boq_item_id' => $row->boq_item_id,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'line_uid' => $row->boq_line_uid,
                'boq_qty' => $boqQty,
                'boq_unit' => $row->boq_unit,
                'consumption_qty' => $calc['consumption_qty'],
                'consumption_unit' => $row->consumption_unit,
                'wastage_percent' => $calc['wastage_percent'],
                'base_requirement' => $calc['base_requirement'],
                'wastage_qty' => $calc['wastage_qty'],
                'required_qty' => $calc['required_qty'],
            ];
        })->values()->all();
    }

    /**
     * Item-level procurement snapshot for the workspace Procurement tab.
     *
     * @return list<array<string, mixed>>
     */
    public function itemProcurement(BoqItem $item): array
    {
        $item->loadMissing('boq');
        $summary = collect($this->projectSummary($item->boq->project, null));
        $out = [];

        $bbs = BoqBbs::query()
            ->where('boq_item_id', $item->id)
            ->where('status', BbsStatus::Approved)
            ->with(['steelMaps.material', 'lines'])
            ->first();

        if ($bbs) {
            $theoByDia = [];
            $lenByDia = [];
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            }
            $diaSummary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
            $maps = $bbs->steelMaps->keyBy(fn ($m) => (int) $m->diameter_mm);

            foreach ($diaSummary as $row) {
                $map = $maps->get($row['diameter_mm']);
                if (! $map) {
                    continue;
                }
                $proj = $summary->first(fn ($r) => (int) $r['material_id'] === (int) $map->material_id);
                $out[] = [
                    'material_id' => $map->material_id,
                    'material' => trim(($map->material?->code ?? '').' '.($map->material?->name ?? '')),
                    'unit' => 'Kg',
                    'required_qty' => $row['required_weight'],
                    'bbs_required_qty' => $row['required_weight'],
                    'diameter_mm' => $row['diameter_mm'],
                    'mr_qty' => $proj['mr_qty'] ?? '0.0000',
                    'po_qty' => $proj['po_qty'] ?? '0.0000',
                    'received_qty' => $proj['received_qty'] ?? '0.0000',
                    'issued_qty' => $proj['issued_qty'] ?? '0.0000',
                    'remaining_requirement' => $proj['remaining_requirement'] ?? $row['required_weight'],
                    'requirement_source' => 'BBS',
                ];
            }

            return $out;
        }

        $norms = BoqMaterialNorm::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->with('material:id,code,name')
            ->get();

        $effectiveQty = $this->effectiveQty->forItem($item);

        return $norms->map(function (BoqMaterialNorm $n) use ($summary, $effectiveQty) {
            $calc = MaterialNormCalculator::line([
                'boq_qty' => $effectiveQty,
                'consumption_qty' => (string) $n->consumption_qty,
                'wastage_percent' => (string) $n->wastage_percent,
            ]);
            $proj = $summary->first(
                fn ($r) => (int) $r['material_id'] === (int) $n->material_id
                    && mb_strtolower($r['unit']) === mb_strtolower($n->consumption_unit)
            );

            return [
                'material_id' => $n->material_id,
                'material' => trim(($n->material?->code ?? '').' '.($n->material?->name ?? '')),
                'unit' => $n->consumption_unit,
                'required_qty' => $calc['required_qty'],
                'mr_qty' => $proj['mr_qty'] ?? '0.0000',
                'po_qty' => $proj['po_qty'] ?? '0.0000',
                'received_qty' => $proj['received_qty'] ?? '0.0000',
                'issued_qty' => $proj['issued_qty'] ?? '0.0000',
                'remaining_requirement' => $proj['remaining_requirement'] ?? $calc['required_qty'],
                'requirement_source' => 'Material Norm',
            ];
        })->all();
    }

    /**
     * Aggregate approved BBS required steel by mapped material (kg).
     *
     * @return list<array<string, mixed>>
     */
    private function approvedBbsRequirements(int $projectId, ?int $materialId = null): array
    {
        $bbsList = BoqBbs::query()
            ->where('project_id', $projectId)
            ->where('status', BbsStatus::Approved)
            ->whereHas('boq', fn ($q) => $q->whereNull('deleted_at')->where('status', '!=', 'revised'))
            ->with(['lines', 'steelMaps.material:id,code,name', 'item:id,item_code,name'])
            ->get();

        /** @var array<int, array{theo: Decimal, waste: Decimal, required: Decimal, count: int, code: string, name: string}> $agg */
        $agg = [];

        foreach ($bbsList as $bbs) {
            $theoByDia = [];
            $lenByDia = [];
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            }
            $summary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
            $maps = $bbs->steelMaps->keyBy(fn ($m) => (int) $m->diameter_mm);

            foreach ($summary as $row) {
                $map = $maps->get($row['diameter_mm']);
                if (! $map) {
                    continue;
                }
                $mid = (int) $map->material_id;
                if ($materialId !== null && $mid !== $materialId) {
                    continue;
                }
                if (! isset($agg[$mid])) {
                    $agg[$mid] = [
                        'theo' => Decimal::zero(),
                        'waste' => Decimal::zero(),
                        'required' => Decimal::zero(),
                        'count' => 0,
                        'code' => $map->material?->code ?? '',
                        'name' => $map->material?->name ?? '',
                        'waste_pct_num' => Decimal::zero(),
                        'waste_pct_den' => Decimal::zero(),
                    ];
                }
                $theo = Decimal::of($row['theoretical_weight']);
                $waste = Decimal::of($row['wastage_weight']);
                $req = Decimal::of($row['required_weight']);
                $agg[$mid]['theo'] = $agg[$mid]['theo']->plus($theo);
                $agg[$mid]['waste'] = $agg[$mid]['waste']->plus($waste);
                $agg[$mid]['required'] = $agg[$mid]['required']->plus($req);
                $agg[$mid]['count']++;
                if ($theo->isPositive()) {
                    $agg[$mid]['waste_pct_num'] = $agg[$mid]['waste_pct_num']->plus($theo->times($bbs->wastage_percent));
                    $agg[$mid]['waste_pct_den'] = $agg[$mid]['waste_pct_den']->plus($theo);
                }
            }
        }

        $out = [];
        foreach ($agg as $mid => $a) {
            $avgW = $a['waste_pct_den']->isPositive()
                ? $a['waste_pct_num']->dividedBy($a['waste_pct_den'], Decimal::PERCENT_SCALE)->toString()
                : '0.0000';
            $out[] = [
                'material_id' => $mid,
                'material_code' => $a['code'],
                'material_name' => $a['name'],
                'unit' => 'Kg',
                'base_requirement' => $a['theo']->toQuantity(),
                'wastage_percent' => $avgW,
                'wastage_qty' => $a['waste']->toQuantity(),
                'required_qty' => $a['required']->toQuantity(),
                'sources_count' => $a['count'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bbsDrillDown(int $projectId, int $materialId): array
    {
        $bbsList = BoqBbs::query()
            ->where('project_id', $projectId)
            ->where('status', BbsStatus::Approved)
            ->whereHas('steelMaps', fn ($q) => $q->where('material_id', $materialId))
            ->with(['lines', 'steelMaps.material:id,code,name', 'item:id,item_code,name', 'boq:id,boq_number'])
            ->get();

        $out = [];
        foreach ($bbsList as $bbs) {
            $theoByDia = [];
            $lenByDia = [];
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            }
            $summary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
            $maps = $bbs->steelMaps->keyBy(fn ($m) => (int) $m->diameter_mm);

            foreach ($summary as $row) {
                $map = $maps->get($row['diameter_mm']);
                if (! $map || (int) $map->material_id !== $materialId) {
                    continue;
                }
                $out[] = [
                    'requirement_source' => 'BBS',
                    'bbs_id' => $bbs->id,
                    'bbs_title' => $bbs->title,
                    'boq_id' => $bbs->boq_id,
                    'boq_number' => $bbs->boq?->boq_number,
                    'boq_item_id' => $bbs->boq_item_id,
                    'item_code' => $bbs->item?->item_code,
                    'item_name' => $bbs->item?->name,
                    'diameter_mm' => $row['diameter_mm'],
                    'theoretical_weight' => $row['theoretical_weight'],
                    'wastage_weight' => $row['wastage_weight'],
                    'required_qty' => $row['required_weight'],
                    'wastage_percent' => (string) $bbs->wastage_percent,
                    'mapped_material' => trim(($map->material?->code ?? '').' '.($map->material?->name ?? '')),
                    'unit' => 'Kg',
                ];
            }
        }

        return $out;
    }

    /**
     * @return Collection<int, object>
     */
    private function activeNorms(int $projectId, ?int $materialId = null): Collection
    {
        return DB::table('boq_material_norms as n')
            ->join('boqs as b', 'b.id', '=', 'n.boq_id')
            ->join('boq_items as i', 'i.id', '=', 'n.boq_item_id')
            ->join('materials as m', 'm.id', '=', 'n.material_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->where('n.project_id', $projectId)
            ->where('n.is_active', true)
            ->whereNull('b.deleted_at')
            ->where('b.status', '!=', 'revised')
            ->when($materialId, fn ($q) => $q->where('n.material_id', $materialId))
            ->orderBy('m.name')
            ->select([
                'n.id',
                'n.boq_id',
                'n.boq_item_id',
                'n.boq_line_uid',
                'n.material_id',
                'n.consumption_qty',
                'n.consumption_unit',
                'n.wastage_percent',
                'i.quantity as boq_qty',
                'i.item_code',
                'i.name as item_name',
                'u.symbol as boq_unit',
                'b.boq_number',
                'm.code as material_code',
                'm.name as material_name',
            ])
            ->get();
    }

    /**
     * @param  list<int>  $materialIds
     * @return array{mr: array<int, string>, po: array<int, string>, received: array<int, string>}
     */
    private function procurementAggregates(int $projectId, array $materialIds): array
    {
        $mrStatuses = [
            MaterialRequestStatus::Submitted->value,
            MaterialRequestStatus::Approved->value,
            MaterialRequestStatus::PartiallyOrdered->value,
            MaterialRequestStatus::Ordered->value,
            MaterialRequestStatus::Received->value,
            MaterialRequestStatus::Draft->value,
        ];

        $mr = MaterialRequestItem::query()
            ->selectRaw('material_request_items.material_id, SUM(material_request_items.quantity) as qty')
            ->join('material_requests as mr', 'mr.id', '=', 'material_request_items.material_request_id')
            ->where('mr.project_id', $projectId)
            ->whereIn('mr.status', $mrStatuses)
            ->whereIn('material_request_items.material_id', $materialIds)
            ->groupBy('material_request_items.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Decimal::of((string) $q)->toQuantity())
            ->all();

        $poStatuses = [
            PurchaseOrderStatus::Approved->value,
            PurchaseOrderStatus::PartiallyReceived->value,
            PurchaseOrderStatus::Received->value,
        ];

        $po = PurchaseOrderItem::query()
            ->selectRaw('purchase_order_items.material_id, SUM(purchase_order_items.quantity) as qty')
            ->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('po.project_id', $projectId)
            ->whereIn('po.status', $poStatuses)
            ->whereIn('purchase_order_items.material_id', $materialIds)
            ->groupBy('purchase_order_items.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Decimal::of((string) $q)->toQuantity())
            ->all();

        $received = GrnItem::query()
            ->selectRaw('grn_items.material_id, SUM(grn_items.accepted_qty) as qty')
            ->join('grns as g', 'g.id', '=', 'grn_items.grn_id')
            ->where('g.project_id', $projectId)
            ->where('g.status', GrnStatus::Approved->value)
            ->whereIn('grn_items.material_id', $materialIds)
            ->groupBy('grn_items.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Decimal::of((string) $q)->toQuantity())
            ->all();

        return ['mr' => $mr, 'po' => $po, 'received' => $received];
    }

    /**
     * @param  list<int>  $materialIds
     * @return array{issued: array<int, string>, returned: array<int, string>}
     */
    private function issueReturnAggregates(int $projectId, array $materialIds): array
    {
        $issued = StockTransaction::query()
            ->selectRaw('material_id, SUM(qty_out) as qty')
            ->where('project_id', $projectId)
            ->where('txn_type', StockTxnType::IssueOut)
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Decimal::of((string) $q)->toQuantity())
            ->all();

        $returned = StockTransaction::query()
            ->selectRaw('material_id, SUM(qty_in) as qty')
            ->where('project_id', $projectId)
            ->where('txn_type', StockTxnType::ReturnIn)
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Decimal::of((string) $q)->toQuantity())
            ->all();

        return ['issued' => $issued, 'returned' => $returned];
    }

    /**
     * @param  list<int>  $materialIds
     * @return array<int, string>
     */
    private function centralStock(array $materialIds): array
    {
        return StockBalance::query()
            ->selectRaw('material_id, SUM(quantity) as qty')
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Decimal::of((string) $q)->toQuantity())
            ->all();
    }
}
