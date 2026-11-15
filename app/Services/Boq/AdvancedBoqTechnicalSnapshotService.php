<?php

namespace App\Services\Boq;

use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMeasurementLine;
use App\Models\Boq\BoqMeasurementSheet;
use App\Models\User;
use App\Support\Boq\BoqCompanySettings;

/**
 * Freezes authoritative Advanced BOQ technical costing after Technical Approval.
 */
class AdvancedBoqTechnicalSnapshotService
{
    public function __construct(
        private readonly BoqBudgetMaterialSyncService $budgetSync,
    ) {}

    /**
     * @return array{snapshot: array<string, mixed>, hash: string}
     */
    public function capture(Boq $boq): array
    {
        $items = [];
        foreach ($boq->items()->orderBy('sort_order')->orderBy('id')->get() as $item) {
            $items[] = $this->itemPayload($item);
        }

        $recon = $this->budgetSync->proposedSyncPayload($boq)['reconciliation'];

        $snapshot = [
            'captured_at' => now()->toIso8601String(),
            'boq_id' => $boq->id,
            'version' => $boq->version,
            'totals' => [
                'material' => $recon['material'],
                'labour' => $recon['labour'],
                'equipment' => $recon['equipment'],
                'other' => $recon['other'],
                'grand_internal' => $recon['grand'],
                'total_cost_amount' => (string) $boq->total_cost_amount,
            ],
            'items' => $items,
        ];

        return [
            'snapshot' => $snapshot,
            'hash' => $this->hash($snapshot),
        ];
    }

    public function freeze(Boq $boq, User $approver): void
    {
        if (! BoqCompanySettings::enabled()) {
            return;
        }

        $captured = $this->capture($boq);

        $boq->forceFill([
            'technical_approved_at' => now(),
            'technical_approved_by' => $approver->id,
            'technical_approval_snapshot' => $captured['snapshot'],
            'technical_snapshot_hash' => $captured['hash'],
        ])->save();

        $boq->writeAudit('technical_approved', null, [
            'technical_approved_by' => $approver->id,
            'technical_snapshot_hash' => $captured['hash'],
            'grand_internal' => $captured['snapshot']['totals']['grand_internal'] ?? null,
        ]);
    }

    public function clear(Boq $boq, string $reason = 'cleared'): void
    {
        if ($boq->technical_approved_at === null && $boq->technical_approval_snapshot === null) {
            return;
        }

        $oldHash = $boq->technical_snapshot_hash;
        $boq->forceFill([
            'technical_approved_at' => null,
            'technical_approved_by' => null,
            'technical_approval_snapshot' => null,
            'technical_snapshot_hash' => null,
        ])->save();

        $boq->writeAudit('technical_approval_cleared', [
            'technical_snapshot_hash' => $oldHash,
        ], [
            'reason' => $reason,
        ]);
    }

    public function currentHash(Boq $boq): string
    {
        return $this->capture($boq)['hash'];
    }

    public function matchesFrozen(Boq $boq): bool
    {
        if ($boq->technical_snapshot_hash === null) {
            return false;
        }

        return hash_equals((string) $boq->technical_snapshot_hash, $this->currentHash($boq));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function hash(array $snapshot): string
    {
        $copy = $snapshot;
        unset($copy['captured_at']);

        return hash('sha256', json_encode($copy, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(BoqItem $item): array
    {
        $sheets = BoqMeasurementSheet::query()
            ->where('boq_item_id', $item->id)
            ->orderByDesc('id')
            ->get();

        $measurements = [];
        foreach ($sheets as $sheet) {
            $lines = BoqMeasurementLine::query()
                ->where('boq_measurement_sheet_id', $sheet->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            $measurements[] = [
                'sheet_id' => $sheet->id,
                'status' => $sheet->status?->value ?? (string) $sheet->status,
                'total_quantity' => (string) $sheet->total_quantity,
                'lines' => $lines->map(fn (BoqMeasurementLine $line) => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'formula_type' => $line->formula_type?->value ?? (string) $line->formula_type,
                    'nos' => (string) $line->nos,
                    'length' => (string) $line->length,
                    'width' => (string) $line->width,
                    'height_depth' => (string) $line->height_depth,
                    'calculated_quantity' => (string) $line->calculated_quantity,
                ])->all(),
            ];
        }

        $components = BoqCostComponent::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $bbsRows = BoqBbs::query()
            ->where('boq_item_id', $item->id)
            ->with(['lines' => fn ($q) => $q->orderBy('sequence')->orderBy('id')])
            ->orderBy('id')
            ->get();

        return [
            'item_id' => $item->id,
            'line_uid' => $item->line_uid,
            'quantity' => (string) $item->quantity,
            'quantity_source' => $item->quantity_source?->value ?? (string) $item->quantity_source,
            'unit_id' => $item->unit_id,
            'rate_analysis_id' => $item->rate_analysis_id,
            'cost_rate' => (string) $item->cost_rate,
            'cost_amount' => (string) $item->cost_amount,
            'measurements' => $measurements,
            'cost_components' => $components->map(fn (BoqCostComponent $c) => [
                'id' => $c->id,
                'component_type' => $c->component_type?->value ?? (string) $c->component_type,
                'description' => $c->description,
                'consumption_qty' => (string) $c->consumption_qty,
                'consumption_unit' => $c->consumption_unit,
                'rate' => (string) $c->rate,
                'amount_per_boq_unit' => (string) $c->amount_per_boq_unit,
                'wastage_percent' => (string) ($c->wastage_percent ?? '0'),
                'source_id' => $c->source_id,
            ])->all(),
            'bbs' => $bbsRows->map(fn (BoqBbs $schedule) => [
                'id' => $schedule->id,
                'status' => $schedule->status?->value ?? (string) $schedule->status,
                'wastage_percent' => (string) ($schedule->wastage_percent ?? '0'),
                'required_weight' => (string) ($schedule->required_weight ?? '0'),
                'lines' => $schedule->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'diameter_mm' => (int) $line->diameter_mm,
                    'total_length' => (string) $line->total_length,
                    'unit_weight' => (string) $line->unit_weight,
                    'theoretical_weight' => (string) $line->theoretical_weight,
                ])->all(),
            ])->all(),
        ];
    }
}
