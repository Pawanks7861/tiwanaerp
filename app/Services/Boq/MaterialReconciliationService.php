<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsStatus;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\StockTxnType;
use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Boq\BoqBbs;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Material;
use App\Models\Planning\ProjectTask;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Support\Facades\DB;

/**
 * Stage 7 — Material & Steel reconciliation (read / analysis only).
 *
 * Planned: Stage 4 Material Norms, overridden by Approved BBS for mapped steel (Stage 5).
 * Procurement: MR / approved PO / approved GRN accepted qty.
 * Inventory: stock ledger issue_out / return_in for the project.
 *
 * Net Issued = Issue Out − Return In (ledger). Not labelled “Consumed”.
 * Issue Variance = Allowed Qty − Net Issued (positive = less issued than allowed).
 *
 * Zero side effects: no stock, MR, PO, GRN, cost ledger, or Tally writes.
 */
class MaterialReconciliationService
{
    public function __construct(private readonly MaterialRequirementService $requirements) {}

    /**
     * @param  array{material_id?: int|null, category_id?: int|null, requirement_source?: string|null, status?: string|null, search?: string|null, boq_head?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, formula_notes: array<string, string>, totals: array<string, string>}
     */
    public function materialSummary(Project $project, array $filters = []): array
    {
        $base = $this->requirements->projectSummary(
            $project,
            isset($filters['material_id']) ? (int) $filters['material_id'] : null,
        );

        $materialIds = collect($base)->pluck('material_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $allocation = $this->issueAllocationAggregates($project->id, $materialIds);
        $rates = $this->materialRates($materialIds);

        $filters['_project_id'] = $project->id;

        $rows = [];
        foreach ($base as $row) {
            $mid = (int) $row['material_id'];
            $enriched = $this->enrichMaterialRow($row, $allocation[$mid] ?? null, $rates[$mid] ?? null);
            if ($this->passesMaterialFilters($enriched, $filters)) {
                $rows[] = $enriched;
            }
        }

        return [
            'rows' => $rows,
            'formula_notes' => $this->formulaNotes(),
            'totals' => $this->sumQtyColumns($rows, [
                'allowed_qty', 'mr_qty', 'po_qty', 'grn_qty', 'issued_qty', 'returned_qty',
                'net_issued', 'unallocated_issue_qty', 'issue_variance',
            ]),
        ];
    }

    /**
     * Material detail: planning + procurement + inventory + BOQ item sources + variance.
     *
     * @return array<string, mixed>
     */
    public function materialDetail(Project $project, int $materialId, ?string $unit = null): array
    {
        $summary = collect($this->materialSummary($project, ['material_id' => $materialId])['rows'])
            ->first(fn ($r) => $unit === null || mb_strtolower((string) $r['unit']) === mb_strtolower($unit));

        $sources = $this->requirements->materialDrillDown($project, $materialId, $unit);
        $itemProgress = $this->boqItemProgressMap($project->id, collect($sources)->pluck('boq_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->all());
        $linkedIssues = $this->linkedIssueByBoqItem($project->id, $materialId);

        $items = [];
        foreach ($sources as $src) {
            $boqItemId = isset($src['boq_item_id']) ? (int) $src['boq_item_id'] : null;
            $netLinked = $boqItemId !== null ? ($linkedIssues[$boqItemId] ?? null) : null;
            $required = (string) ($src['required_qty'] ?? '0.0000');
            $progress = $boqItemId !== null ? ($itemProgress[$boqItemId] ?? null) : null;
            $progressVariance = null;
            if ($progress !== null && $netLinked !== null) {
                $expected = Decimal::of($required)->times($progress)->dividedBy(100, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
                $progressVariance = $expected->minus($netLinked['net_issued'])->toQuantity();
            }

            $items[] = array_merge($src, [
                'progress_percent' => $progress,
                'net_issued' => $netLinked['net_issued'] ?? null,
                'issued_qty' => $netLinked['issued'] ?? null,
                'returned_qty' => $netLinked['returned'] ?? null,
                'issue_traceable' => $netLinked !== null,
                'issue_variance' => $netLinked !== null
                    ? Decimal::of($required)->minus($netLinked['net_issued'])->toQuantity()
                    : null,
                'progress_variance' => $progressVariance,
                'progress_adjusted_note' => $progress !== null && $netLinked !== null
                    ? 'Expected at progress = Required × Progress % (item-level only; authoritative task progress).'
                    : null,
            ]);
        }

        return [
            'summary' => $summary,
            'items' => $items,
            'unallocated_issue_qty' => $summary['unallocated_issue_qty'] ?? '0.0000',
            'formula_notes' => $this->formulaNotes(),
        ];
    }

    /**
     * Steel reconciliation grouped by diameter + mapped material (Approved BBS only).
     *
     * @param  array{diameter_mm?: int|null, material_id?: int|null, boq_item_id?: int|null, bbs_id?: int|null, status?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, formula_notes: array<string, string>}
     */
    public function steelSummary(Project $project, array $filters = []): array
    {
        $groups = $this->approvedBbsByDiameterMaterial($project->id, $filters);
        if ($groups === []) {
            return ['rows' => [], 'formula_notes' => $this->steelFormulaNotes()];
        }

        $materialIds = collect($groups)->pluck('material_id')->unique()->values()->all();
        $proc = $this->procurementMaps($project->id, $materialIds);
        $stock = $this->centralStockMap($materialIds);
        $issues = $this->issueReturnMaps($project->id, $materialIds);

        $rows = [];
        foreach ($groups as $g) {
            $mid = (int) $g['material_id'];
            $issued = $issues['issued'][$mid] ?? '0.0000';
            $returned = $issues['returned'][$mid] ?? '0.0000';
            $net = Decimal::of($issued)->minus($returned)->toQuantity();
            $allowed = (string) $g['required_weight'];
            $variance = Decimal::of($allowed)->minus($net)->toQuantity();
            $status = $this->status(
                allowed: $allowed,
                netIssued: $net,
                mr: $proc['mr'][$mid] ?? '0.0000',
                po: $proc['po'][$mid] ?? '0.0000',
                grn: $proc['received'][$mid] ?? '0.0000',
                issued: $issued,
                returned: $returned,
            );

            $row = [
                'diameter_mm' => (int) $g['diameter_mm'],
                'material_id' => $mid,
                'material_code' => $g['material_code'],
                'material_name' => $g['material_name'],
                'unit' => 'Kg',
                'theoretical_weight' => $g['theoretical_weight'],
                'wastage_weight' => $g['wastage_weight'],
                'required_weight' => $allowed,
                'required_weight_mt' => Decimal::of($allowed)->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                'mr_qty' => $proc['mr'][$mid] ?? '0.0000',
                'po_qty' => $proc['po'][$mid] ?? '0.0000',
                'grn_qty' => $proc['received'][$mid] ?? '0.0000',
                'issued_qty' => $issued,
                'returned_qty' => $returned,
                'net_issued' => $net,
                'central_stock_available' => $stock[$mid] ?? '0.0000',
                'issue_variance' => $variance,
                'variance_percent' => Num::percent($variance, $allowed),
                'status' => $status,
                'bbs_count' => $g['bbs_count'],
                'requirement_source' => 'BBS',
            ];

            if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null
                && $row['status'] !== $filters['status']) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => [$a['diameter_mm'], $a['material_name']] <=> [$b['diameter_mm'], $b['material_name']]);

        return ['rows' => $rows, 'formula_notes' => $this->steelFormulaNotes()];
    }

    /**
     * Diameter (+ material) drill-down: BBS records, BOQ items, weights, mapped material.
     *
     * @return array{header: array<string, mixed>|null, lines: list<array<string, mixed>>, procurement: array<string, mixed>|null}
     */
    public function steelDiameterDetail(Project $project, int $diameterMm, int $materialId): array
    {
        $summary = collect($this->steelSummary($project, [
            'diameter_mm' => $diameterMm,
            'material_id' => $materialId,
        ])['rows'])->first();

        $bbsList = BoqBbs::query()
            ->where('project_id', $project->id)
            ->where('status', BbsStatus::Approved)
            ->whereHas('steelMaps', fn ($q) => $q->where('diameter_mm', $diameterMm)->where('material_id', $materialId))
            ->whereHas('boq', fn ($q) => $q->whereNull('deleted_at')->where('status', '!=', 'revised'))
            ->with(['lines', 'steelMaps.material:id,code,name', 'item:id,item_code,name', 'boq:id,boq_number'])
            ->get();

        $lines = [];
        foreach ($bbsList as $bbs) {
            $theoByDia = [];
            $lenByDia = [];
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            }
            $diaSummary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
            $map = $bbs->steelMaps->first(
                fn ($m) => (int) $m->diameter_mm === $diameterMm && (int) $m->material_id === $materialId
            );
            if (! $map) {
                continue;
            }
            $row = collect($diaSummary)->firstWhere('diameter_mm', $diameterMm);
            if (! $row) {
                continue;
            }
            $lines[] = [
                'bbs_id' => $bbs->id,
                'bbs_title' => $bbs->title,
                'boq_id' => $bbs->boq_id,
                'boq_number' => $bbs->boq?->boq_number,
                'boq_item_id' => $bbs->boq_item_id,
                'item_code' => $bbs->item?->item_code,
                'item_name' => $bbs->item?->name,
                'diameter_mm' => $diameterMm,
                'theoretical_weight' => $row['theoretical_weight'],
                'wastage_weight' => $row['wastage_weight'],
                'required_weight' => $row['required_weight'],
                'wastage_percent' => (string) $bbs->wastage_percent,
                'mapped_material_id' => $map->material_id,
                'mapped_material' => trim(($map->material?->code ?? '').' '.($map->material?->name ?? '')),
                'unit' => 'Kg',
            ];
        }

        return [
            'header' => $summary,
            'lines' => $lines,
            'procurement' => $summary ? [
                'mr_qty' => $summary['mr_qty'],
                'po_qty' => $summary['po_qty'],
                'grn_qty' => $summary['grn_qty'],
                'issued_qty' => $summary['issued_qty'],
                'returned_qty' => $summary['returned_qty'],
                'net_issued' => $summary['net_issued'],
                'note' => 'MR/PO/GRN/Issue aggregates are by mapped material (project scope), not split per BBS line.',
            ] : null,
        ];
    }

    /**
     * Source document lists for an aggregate cell (MR / PO / GRN / Issue / Return).
     *
     * @return list<array<string, mixed>>
     */
    public function sourceDocuments(Project $project, int $materialId, string $type): array
    {
        return match ($type) {
            'mr' => $this->mrDocuments($project->id, $materialId),
            'po' => $this->poDocuments($project->id, $materialId),
            'grn' => $this->grnDocuments($project->id, $materialId),
            'issue' => $this->issueDocuments($project->id, $materialId),
            'return' => $this->returnDocuments($project->id, $materialId),
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function formulaNotes(): array
    {
        return [
            'allowed' => 'Allowed Qty = Base Requirement + Wastage Qty (Material Norm), or BBS Required Weight (includes BBS wastage; not added twice).',
            'net_issued' => 'Net Issued = Issue Out − Return In (project stock ledger). Not labelled Consumed.',
            'issue_variance' => 'Issue Variance = Allowed Qty − Net Issued. Positive = less issued than allowed (work may be incomplete). Negative = over issued.',
            'variance_percent' => 'Variance % = Issue Variance / Allowed Qty × 100; null when Allowed = 0.',
            'procurement' => 'Unrequested = max(0, Required − MR); Unordered = max(0, Required − Approved PO); Pending Receipt = max(0, Approved PO − GRN Accepted); Over Received = max(0, GRN − Required).',
            'stock' => 'Central Stock Available is company warehouse balance — not reserved for this project. Project Net Issued is separate.',
            'unallocated' => 'Unallocated Issue Qty = project issue-out posted from material issue lines with no BOQ item link. Not fabricated into item drill-down.',
            'bbs_authority' => 'Approved BBS mapped steel overrides Material Norm for those materials (Stage 5 double-count rule).',
            'progress' => 'Progress-adjusted expected qty is shown only on item drill-down when the BOQ item has authoritative task progress and issues are traceable to that item. Project-wide average progress is never applied blindly.',
            'side_effects' => 'Opening / exporting reconciliation creates zero stock, MR, PO, GRN, cost ledger, or Tally rows.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function steelFormulaNotes(): array
    {
        return array_merge($this->formulaNotes(), [
            'steel_variance' => 'Steel Issue Variance = BBS Required Weight − Net Issued (kg).',
            'unit' => 'Canonical unit kg; MT = kg / 1000 for display only.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{linked_issued: string, linked_returned: string, unallocated_issued: string, unallocated_returned: string}|null  $alloc
     * @return array<string, mixed>
     */
    private function enrichMaterialRow(array $row, ?array $alloc, ?string $standardRate): array
    {
        $allowed = (string) $row['required_qty'];
        $issued = (string) $row['issued_qty'];
        $returned = (string) $row['returned_qty'];
        $net = Decimal::of($issued)->minus($returned)->toQuantity();
        $variance = Decimal::of($allowed)->minus($net)->toQuantity();
        $grn = (string) $row['received_qty'];
        $mr = (string) $row['mr_qty'];
        $po = (string) $row['po_qty'];

        $unallocatedIssued = $alloc['unallocated_issued'] ?? '0.0000';
        $unallocatedReturned = $alloc['unallocated_returned'] ?? '0.0000';
        $unallocatedNet = Decimal::of($unallocatedIssued)->minus($unallocatedReturned)->toQuantity();

        $estValue = null;
        if ($standardRate !== null && $standardRate !== '') {
            $estValue = Decimal::of($variance)->times($standardRate)->round(Decimal::MONEY_SCALE)->toMoney();
        }

        return [
            'material_id' => (int) $row['material_id'],
            'material_code' => $row['material_code'],
            'material_name' => $row['material_name'],
            'unit' => $row['unit'],
            'requirement_source' => $row['requirement_source'],
            'requirement_source_label' => $row['requirement_source_label'] ?? $row['requirement_source'],
            'base_requirement' => $row['base_requirement'],
            'wastage_percent' => $row['wastage_percent'],
            'wastage_qty' => $row['wastage_qty'],
            'allowed_qty' => $allowed,
            'required_qty' => $allowed,
            'mr_qty' => $mr,
            'po_qty' => $po,
            'grn_qty' => $grn,
            'received_qty' => $grn,
            'issued_qty' => $issued,
            'returned_qty' => $returned,
            'net_issued' => $net,
            'project_net_issued' => $net,
            'central_stock_available' => $row['available_stock'],
            'available_stock' => $row['available_stock'],
            'remaining_requirement' => $row['remaining_requirement'],
            'over_received_qty' => $row['over_procured_qty'],
            'over_procured_qty' => $row['over_procured_qty'],
            'unrequested_qty' => MaterialNormCalculator::remaining($allowed, $mr),
            'unordered_qty' => MaterialNormCalculator::remaining($allowed, $po),
            'pending_receipt_qty' => MaterialNormCalculator::remaining($po, $grn),
            'unallocated_issue_qty' => $unallocatedNet,
            'issue_variance' => $variance,
            'variance_percent' => Num::percent($variance, $allowed),
            'estimated_variance_value' => $estValue,
            'status' => $this->status($allowed, $net, $mr, $po, $grn, $issued, $returned),
            'sources_count' => $row['sources_count'],
        ];
    }

    private function status(
        string $allowed,
        string $netIssued,
        string $mr,
        string $po,
        string $grn,
        string $issued,
        string $returned,
    ): string {
        $activity = Decimal::of($mr)->plus($po)->plus($grn)->plus($issued)->plus($returned);
        if ($activity->isZero()) {
            return 'No Activity';
        }

        $net = Decimal::of($netIssued);
        $allow = Decimal::of($allowed);

        if ($net->greaterThan($allow)) {
            return 'Over Issued';
        }

        if (Decimal::of($grn)->lessThan($allow) && $net->isZero()) {
            return 'Procurement Pending';
        }

        if ($net->lessThan($allow)) {
            return 'Under Issued';
        }

        return 'Within Plan';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function passesMaterialFilters(array $row, array $filters): bool
    {
        if (! empty($filters['requirement_source'])
            && mb_strtolower((string) $row['requirement_source']) !== mb_strtolower((string) $filters['requirement_source'])) {
            return false;
        }
        if (! empty($filters['status']) && $row['status'] !== $filters['status']) {
            return false;
        }
        if (! empty($filters['search'])) {
            $needle = mb_strtolower(trim((string) $filters['search']));
            $hay = mb_strtolower($row['material_code'].' '.$row['material_name']);
            if (! str_contains($hay, $needle)) {
                return false;
            }
        }
        if (! empty($filters['category_id'])) {
            $cat = Material::query()->whereKey($row['material_id'])->value('material_category_id');
            if ((int) $cat !== (int) $filters['category_id']) {
                return false;
            }
        }
        if (! empty($filters['boq_head'])) {
            $needle = mb_strtolower(trim((string) $filters['boq_head']));

            return $this->materialMatchesBoqHead(
                (int) $row['material_id'],
                (int) ($filters['_project_id'] ?? 0),
                $needle,
            );
        }

        return true;
    }

    private function materialMatchesBoqHead(int $materialId, int $projectId, string $needle): bool
    {
        if ($projectId <= 0 || $needle === '') {
            return true;
        }

        $normHit = DB::table('boq_material_norms as n')
            ->join('boqs as b', 'b.id', '=', 'n.boq_id')
            ->join('boq_items as i', 'i.id', '=', 'n.boq_item_id')
            ->leftJoin('boq_sections as s', 's.id', '=', 'i.boq_section_id')
            ->where('n.project_id', $projectId)
            ->where('n.material_id', $materialId)
            ->where('n.is_active', true)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(COALESCE(s.code, \'\')) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(COALESCE(s.name, \'\')) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(b.boq_number) LIKE ?', ['%'.$needle.'%']);
            })
            ->exists();

        if ($normHit) {
            return true;
        }

        return BoqBbs::query()
            ->where('project_id', $projectId)
            ->where('status', BbsStatus::Approved)
            ->whereHas('steelMaps', fn ($q) => $q->where('material_id', $materialId))
            ->whereHas('boq', function ($q) use ($needle) {
                $q->whereNull('deleted_at')->where('status', '!=', 'revised')
                    ->whereRaw('LOWER(boq_number) LIKE ?', ['%'.$needle.'%']);
            })
            ->exists();
    }

    /**
     * @param  list<int>  $materialIds
     * @return array<int, array{linked_issued: string, linked_returned: string, unallocated_issued: string, unallocated_returned: string}>
     */
    private function issueAllocationAggregates(int $projectId, array $materialIds): array
    {
        if ($materialIds === []) {
            return [];
        }

        $unallocatedIssued = StockTransaction::query()
            ->selectRaw('stock_transactions.material_id, SUM(stock_transactions.qty_out) as qty')
            ->join('material_issue_items as mii', function ($j) {
                $j->on('mii.id', '=', 'stock_transactions.source_id')
                    ->where('stock_transactions.source_type', '=', 'material_issue_item');
            })
            ->where('stock_transactions.project_id', $projectId)
            ->where('stock_transactions.txn_type', StockTxnType::IssueOut)
            ->whereIn('stock_transactions.material_id', $materialIds)
            ->whereNull('mii.boq_item_id')
            ->groupBy('stock_transactions.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        $unallocatedReturned = StockTransaction::query()
            ->selectRaw('stock_transactions.material_id, SUM(stock_transactions.qty_in) as qty')
            ->join('material_return_items as mri', function ($j) {
                $j->on('mri.id', '=', 'stock_transactions.source_id')
                    ->where('stock_transactions.source_type', '=', 'material_return_item');
            })
            ->join('material_issue_items as mii', 'mii.id', '=', 'mri.material_issue_item_id')
            ->where('stock_transactions.project_id', $projectId)
            ->where('stock_transactions.txn_type', StockTxnType::ReturnIn)
            ->whereIn('stock_transactions.material_id', $materialIds)
            ->whereNull('mii.boq_item_id')
            ->groupBy('stock_transactions.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        $out = [];
        foreach ($materialIds as $mid) {
            $out[$mid] = [
                'linked_issued' => '0.0000',
                'linked_returned' => '0.0000',
                'unallocated_issued' => $unallocatedIssued[$mid] ?? '0.0000',
                'unallocated_returned' => $unallocatedReturned[$mid] ?? '0.0000',
            ];
        }

        return $out;
    }

    /**
     * Traceable net issued per BOQ item for a material (via issue item linkage).
     *
     * @return array<int, array{issued: string, returned: string, net_issued: string}>
     */
    private function linkedIssueByBoqItem(int $projectId, int $materialId): array
    {
        $issued = StockTransaction::query()
            ->selectRaw('mii.boq_item_id, SUM(stock_transactions.qty_out) as qty')
            ->join('material_issue_items as mii', function ($j) {
                $j->on('mii.id', '=', 'stock_transactions.source_id')
                    ->where('stock_transactions.source_type', '=', 'material_issue_item');
            })
            ->where('stock_transactions.project_id', $projectId)
            ->where('stock_transactions.txn_type', StockTxnType::IssueOut)
            ->where('stock_transactions.material_id', $materialId)
            ->whereNotNull('mii.boq_item_id')
            ->groupBy('mii.boq_item_id')
            ->pluck('qty', 'boq_item_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        $returned = StockTransaction::query()
            ->selectRaw('mii.boq_item_id, SUM(stock_transactions.qty_in) as qty')
            ->join('material_return_items as mri', function ($j) {
                $j->on('mri.id', '=', 'stock_transactions.source_id')
                    ->where('stock_transactions.source_type', '=', 'material_return_item');
            })
            ->join('material_issue_items as mii', 'mii.id', '=', 'mri.material_issue_item_id')
            ->where('stock_transactions.project_id', $projectId)
            ->where('stock_transactions.txn_type', StockTxnType::ReturnIn)
            ->where('stock_transactions.material_id', $materialId)
            ->whereNotNull('mii.boq_item_id')
            ->groupBy('mii.boq_item_id')
            ->pluck('qty', 'boq_item_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        $ids = collect(array_keys($issued))->merge(array_keys($returned))->unique();
        $out = [];
        foreach ($ids as $boqItemId) {
            $i = $issued[$boqItemId] ?? '0.0000';
            $r = $returned[$boqItemId] ?? '0.0000';
            $out[(int) $boqItemId] = [
                'issued' => $i,
                'returned' => $r,
                'net_issued' => Decimal::of($i)->minus($r)->toQuantity(),
            ];
        }

        return $out;
    }

    /**
     * Average leaf-task progress % per BOQ item (null when no linked tasks).
     *
     * @param  list<int>  $boqItemIds
     * @return array<int, string>
     */
    private function boqItemProgressMap(int $projectId, array $boqItemIds): array
    {
        if ($boqItemIds === []) {
            return [];
        }

        $rows = ProjectTask::query()
            ->where('project_id', $projectId)
            ->whereIn('boq_item_id', $boqItemIds)
            ->whereDoesntHave('children')
            ->get(['boq_item_id', 'progress_percent', 'status']);

        $grouped = $rows->groupBy('boq_item_id');
        $out = [];
        foreach ($grouped as $boqItemId => $tasks) {
            $sum = Decimal::zero();
            foreach ($tasks as $t) {
                $sum = $sum->plus(Num::dec($t->progress_percent ?? '0'));
            }
            $out[(int) $boqItemId] = $sum->dividedBy($tasks->count(), 2)->round(2)->toString();
        }

        return $out;
    }

    /**
     * @param  array{diameter_mm?: int|null, material_id?: int|null, boq_item_id?: int|null, bbs_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function approvedBbsByDiameterMaterial(int $projectId, array $filters): array
    {
        $bbsList = BoqBbs::query()
            ->where('project_id', $projectId)
            ->where('status', BbsStatus::Approved)
            ->when(! empty($filters['bbs_id']), fn ($q) => $q->whereKey((int) $filters['bbs_id']))
            ->when(! empty($filters['boq_item_id']), fn ($q) => $q->where('boq_item_id', (int) $filters['boq_item_id']))
            ->whereHas('boq', fn ($q) => $q->whereNull('deleted_at')->where('status', '!=', 'revised'))
            ->with(['lines', 'steelMaps.material:id,code,name'])
            ->get();

        /** @var array<string, array{diameter_mm: int, material_id: int, theo: Decimal, waste: Decimal, required: Decimal, count: int, code: string, name: string}> $agg */
        $agg = [];

        foreach ($bbsList as $bbs) {
            $theoByDia = [];
            $lenByDia = [];
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                if (! empty($filters['diameter_mm']) && $d !== (int) $filters['diameter_mm']) {
                    continue;
                }
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            }
            if ($theoByDia === []) {
                continue;
            }
            $summary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
            $maps = $bbs->steelMaps->keyBy(fn ($m) => (int) $m->diameter_mm);

            foreach ($summary as $row) {
                $map = $maps->get($row['diameter_mm']);
                if (! $map) {
                    continue;
                }
                $mid = (int) $map->material_id;
                if (! empty($filters['material_id']) && $mid !== (int) $filters['material_id']) {
                    continue;
                }
                if (! empty($filters['diameter_mm']) && (int) $row['diameter_mm'] !== (int) $filters['diameter_mm']) {
                    continue;
                }
                $key = $row['diameter_mm'].'|'.$mid;
                if (! isset($agg[$key])) {
                    $agg[$key] = [
                        'diameter_mm' => (int) $row['diameter_mm'],
                        'material_id' => $mid,
                        'theo' => Decimal::zero(),
                        'waste' => Decimal::zero(),
                        'required' => Decimal::zero(),
                        'count' => 0,
                        'code' => $map->material?->code ?? '',
                        'name' => $map->material?->name ?? '',
                    ];
                }
                $agg[$key]['theo'] = $agg[$key]['theo']->plus($row['theoretical_weight']);
                $agg[$key]['waste'] = $agg[$key]['waste']->plus($row['wastage_weight']);
                $agg[$key]['required'] = $agg[$key]['required']->plus($row['required_weight']);
                $agg[$key]['count']++;
            }
        }

        $out = [];
        foreach ($agg as $a) {
            $out[] = [
                'diameter_mm' => $a['diameter_mm'],
                'material_id' => $a['material_id'],
                'material_code' => $a['code'],
                'material_name' => $a['name'],
                'theoretical_weight' => $a['theo']->toQuantity(),
                'wastage_weight' => $a['waste']->toQuantity(),
                'required_weight' => $a['required']->toQuantity(),
                'bbs_count' => $a['count'],
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $materialIds
     * @return array{mr: array<int, string>, po: array<int, string>, received: array<int, string>}
     */
    private function procurementMaps(int $projectId, array $materialIds): array
    {
        if ($materialIds === []) {
            return ['mr' => [], 'po' => [], 'received' => []];
        }

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
            ->map(fn ($q) => Num::qty($q))
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
            ->map(fn ($q) => Num::qty($q))
            ->all();

        $received = GrnItem::query()
            ->selectRaw('grn_items.material_id, SUM(grn_items.accepted_qty) as qty')
            ->join('grns as g', 'g.id', '=', 'grn_items.grn_id')
            ->where('g.project_id', $projectId)
            ->where('g.status', GrnStatus::Approved->value)
            ->whereIn('grn_items.material_id', $materialIds)
            ->groupBy('grn_items.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        return ['mr' => $mr, 'po' => $po, 'received' => $received];
    }

    /**
     * @param  list<int>  $materialIds
     * @return array{issued: array<int, string>, returned: array<int, string>}
     */
    private function issueReturnMaps(int $projectId, array $materialIds): array
    {
        if ($materialIds === []) {
            return ['issued' => [], 'returned' => []];
        }

        $issued = StockTransaction::query()
            ->selectRaw('material_id, SUM(qty_out) as qty')
            ->where('project_id', $projectId)
            ->where('txn_type', StockTxnType::IssueOut)
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        $returned = StockTransaction::query()
            ->selectRaw('material_id, SUM(qty_in) as qty')
            ->where('project_id', $projectId)
            ->where('txn_type', StockTxnType::ReturnIn)
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        return ['issued' => $issued, 'returned' => $returned];
    }

    /**
     * @param  list<int>  $materialIds
     * @return array<int, string>
     */
    private function centralStockMap(array $materialIds): array
    {
        if ($materialIds === []) {
            return [];
        }

        return DB::table('stock_balances')
            ->selectRaw('material_id, SUM(quantity) as qty')
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();
    }

    /**
     * @param  list<int>  $materialIds
     * @return array<int, string>
     */
    private function materialRates(array $materialIds): array
    {
        if ($materialIds === []) {
            return [];
        }

        return Material::query()
            ->whereIn('id', $materialIds)
            ->pluck('standard_rate', 'id')
            ->map(fn ($r) => $r !== null ? (string) $r : null)
            ->filter()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mrDocuments(int $projectId, int $materialId): array
    {
        $statuses = [
            MaterialRequestStatus::Submitted->value,
            MaterialRequestStatus::Approved->value,
            MaterialRequestStatus::PartiallyOrdered->value,
            MaterialRequestStatus::Ordered->value,
            MaterialRequestStatus::Received->value,
            MaterialRequestStatus::Draft->value,
        ];

        return MaterialRequestItem::query()
            ->select([
                'material_requests.id as document_id',
                'material_requests.request_number as number',
                'material_requests.status',
                'material_request_items.quantity',
                'material_requests.created_at',
            ])
            ->join('material_requests', 'material_requests.id', '=', 'material_request_items.material_request_id')
            ->where('material_requests.project_id', $projectId)
            ->whereIn('material_requests.status', $statuses)
            ->where('material_request_items.material_id', $materialId)
            ->orderByDesc('material_requests.id')
            ->get()
            ->map(fn ($r) => [
                'type' => 'mr',
                'id' => (int) $r->document_id,
                'number' => $r->number,
                'status' => $r->status,
                'quantity' => Num::qty($r->quantity),
                'url' => route('projects.material-requests.show', [$projectId, $r->document_id]),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function poDocuments(int $projectId, int $materialId): array
    {
        $statuses = [
            PurchaseOrderStatus::Approved->value,
            PurchaseOrderStatus::PartiallyReceived->value,
            PurchaseOrderStatus::Received->value,
        ];

        return PurchaseOrderItem::query()
            ->select([
                'purchase_orders.id as document_id',
                'purchase_orders.po_number as number',
                'purchase_orders.status',
                'purchase_order_items.quantity',
            ])
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.project_id', $projectId)
            ->whereIn('purchase_orders.status', $statuses)
            ->where('purchase_order_items.material_id', $materialId)
            ->orderByDesc('purchase_orders.id')
            ->get()
            ->map(fn ($r) => [
                'type' => 'po',
                'id' => (int) $r->document_id,
                'number' => $r->number,
                'status' => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status,
                'quantity' => Num::qty($r->quantity),
                'url' => route('projects.purchase-orders.show', [$projectId, $r->document_id]),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grnDocuments(int $projectId, int $materialId): array
    {
        return GrnItem::query()
            ->select([
                'grns.id as document_id',
                'grns.grn_number as number',
                'grns.status',
                'grn_items.accepted_qty as quantity',
            ])
            ->join('grns', 'grns.id', '=', 'grn_items.grn_id')
            ->where('grns.project_id', $projectId)
            ->where('grns.status', GrnStatus::Approved->value)
            ->where('grn_items.material_id', $materialId)
            ->orderByDesc('grns.id')
            ->get()
            ->map(fn ($r) => [
                'type' => 'grn',
                'id' => (int) $r->document_id,
                'number' => $r->number,
                'status' => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status,
                'quantity' => Num::qty($r->quantity),
                'url' => route('projects.grns.show', [$projectId, $r->document_id]),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function issueDocuments(int $projectId, int $materialId): array
    {
        return DB::table('stock_transactions as st')
            ->join('material_issue_items as mii', function ($j) {
                $j->on('mii.id', '=', 'st.source_id')->where('st.source_type', '=', 'material_issue_item');
            })
            ->join('material_issues as mi', 'mi.id', '=', 'mii.material_issue_id')
            ->where('st.project_id', $projectId)
            ->where('st.txn_type', StockTxnType::IssueOut->value)
            ->where('st.material_id', $materialId)
            ->where('mi.status', InventoryDocumentStatus::Approved->value)
            ->orderByDesc('mi.id')
            ->select([
                'mi.id as document_id',
                'mi.issue_number as number',
                'mi.status',
                'st.qty_out as quantity',
                'mii.boq_item_id',
            ])
            ->get()
            ->map(fn ($r) => [
                'type' => 'issue',
                'id' => (int) $r->document_id,
                'number' => $r->number,
                'status' => (string) $r->status,
                'quantity' => Num::qty($r->quantity),
                'boq_item_id' => $r->boq_item_id !== null ? (int) $r->boq_item_id : null,
                'url' => route('projects.material-issues.show', [$projectId, $r->document_id]),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function returnDocuments(int $projectId, int $materialId): array
    {
        return DB::table('stock_transactions as st')
            ->join('material_return_items as mri', function ($j) {
                $j->on('mri.id', '=', 'st.source_id')->where('st.source_type', '=', 'material_return_item');
            })
            ->join('material_returns as mr', 'mr.id', '=', 'mri.material_return_id')
            ->where('st.project_id', $projectId)
            ->where('st.txn_type', StockTxnType::ReturnIn->value)
            ->where('st.material_id', $materialId)
            ->where('mr.status', InventoryDocumentStatus::Approved->value)
            ->orderByDesc('mr.id')
            ->select([
                'mr.id as document_id',
                'mr.return_number as number',
                'mr.status',
                'st.qty_in as quantity',
            ])
            ->get()
            ->map(fn ($r) => [
                'type' => 'return',
                'id' => (int) $r->document_id,
                'number' => $r->number,
                'status' => (string) $r->status,
                'quantity' => Num::qty($r->quantity),
                'url' => route('projects.material-returns.show', [$projectId, $r->document_id]),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function sumQtyColumns(array $rows, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $sum = Decimal::zero();
            foreach ($rows as $row) {
                $sum = $sum->plus((string) ($row[$key] ?? '0'));
            }
            $out[$key] = $sum->toQuantity();
        }

        return $out;
    }
}
