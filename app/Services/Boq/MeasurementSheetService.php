<?php

namespace App\Services\Boq;

use App\Enums\Boq\MeasurementFormulaType;
use App\Enums\Boq\MeasurementSheetStatus;
use App\Enums\Boq\QuantitySource;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMeasurementLine;
use App\Models\Boq\BoqMeasurementSheet;
use App\Models\Documents\DrawingRevision;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeasurementSheetService
{
    public function __construct(private readonly BoqService $boqs) {}

    /**
     * Ensure a draft/rejected sheet exists for the item (one working sheet).
     */
    public function ensureSheet(BoqItem $item, User $user): BoqMeasurementSheet
    {
        $item->loadMissing('boq');
        $boq = $item->boq;

        $sheet = BoqMeasurementSheet::query()
            ->where('boq_item_id', $item->id)
            ->whereIn('status', [MeasurementSheetStatus::Draft->value, MeasurementSheetStatus::Rejected->value, MeasurementSheetStatus::Submitted->value])
            ->latest('id')
            ->first();

        if ($sheet) {
            return $sheet;
        }

        $approved = BoqMeasurementSheet::query()
            ->where('boq_item_id', $item->id)
            ->where('status', MeasurementSheetStatus::Approved)
            ->latest('id')
            ->first();

        // If only approved exists and BOQ is editable, clone into a new draft for revisions.
        if ($approved && $boq->isEditable()) {
            return $this->cloneAsDraft($approved, $user);
        }

        if ($approved && ! $boq->isEditable()) {
            return $approved;
        }

        $sheet = new BoqMeasurementSheet;
        $sheet->forceFill([
            'company_id' => $boq->company_id,
            'project_id' => $boq->project_id,
            'boq_id' => $boq->id,
            'boq_item_id' => $item->id,
            'line_uid' => $item->line_uid,
            'title' => 'Measurement — '.($item->item_code ?: $item->name),
            'status' => MeasurementSheetStatus::Draft,
            'total_quantity' => '0',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ])->save();

        return $sheet;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function saveLines(BoqMeasurementSheet $sheet, array $lines, User $user, ?int $drawingRevisionId = null, ?string $title = null): BoqMeasurementSheet
    {
        return DB::transaction(function () use ($sheet, $lines, $user, $drawingRevisionId, $title) {
            $locked = BoqMeasurementSheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['sheet' => 'Only draft or rejected measurement sheets can be edited.']);
            }

            $boq = Boq::query()->whereKey($locked->boq_id)->lockForUpdate()->firstOrFail();
            $boq->assertEditable();

            $locked->forceFill([
                'title' => $title !== null && trim($title) !== '' ? trim($title) : $locked->title,
                'drawing_revision_id' => $this->validatedRevisionId($boq, $drawingRevisionId),
                'updated_by' => $user->id,
                'status' => MeasurementSheetStatus::Draft,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $locked->lines()->delete();

            $qtyValues = [];
            foreach (array_values($lines) as $i => $row) {
                $calc = MeasurementCalculator::line($row);
                $line = new BoqMeasurementLine;
                $line->forceFill([
                    'boq_measurement_sheet_id' => $locked->id,
                    'location' => $this->nullableString($row['location'] ?? null, 160),
                    'description' => $this->nullableString($row['description'] ?? null, 255),
                    'formula_type' => MeasurementFormulaType::from((string) $row['formula_type']),
                    'nos' => $this->nullableDecimal($row['nos'] ?? null),
                    'length' => $this->nullableDecimal($row['length'] ?? null),
                    'width' => $this->nullableDecimal($row['width'] ?? null),
                    'height_depth' => $this->nullableDecimal($row['height_depth'] ?? null),
                    'diameter' => $this->nullableDecimal($row['diameter'] ?? null),
                    'radius' => $calc['radius'] ?? $this->nullableDecimal($row['radius'] ?? null),
                    'manual_quantity' => $this->nullableDecimal($row['manual_quantity'] ?? null),
                    'addition_quantity' => $this->nullableDecimal($row['addition_quantity'] ?? '0') ?? '0',
                    'deduction_quantity' => $this->nullableDecimal($row['deduction_quantity'] ?? '0') ?? '0',
                    'calculated_base' => $calc['calculated_base'],
                    'calculated_quantity' => $calc['calculated_quantity'],
                    'unit_id' => isset($row['unit_id']) && $row['unit_id'] !== '' ? (int) $row['unit_id'] : null,
                    'drawing_revision_id' => $this->validatedRevisionId($boq, isset($row['drawing_revision_id']) ? (int) $row['drawing_revision_id'] : null),
                    'remarks' => $this->nullableString($row['remarks'] ?? null, 500),
                    'sort_order' => (int) ($row['sort_order'] ?? $i),
                ])->save();
                $qtyValues[] = $calc['calculated_quantity'];
            }

            $locked->forceFill([
                'total_quantity' => MeasurementCalculator::sheetTotal($qtyValues),
            ])->save();

            return $locked->fresh(['lines']);
        });
    }

    public function submit(BoqMeasurementSheet $sheet, User $user): BoqMeasurementSheet
    {
        return DB::transaction(function () use ($sheet, $user) {
            $locked = BoqMeasurementSheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['sheet' => 'Only draft or rejected sheets can be submitted.']);
            }
            if ($locked->lines()->count() === 0) {
                throw ValidationException::withMessages(['lines' => 'Add at least one measurement line before submitting.']);
            }

            $boq = Boq::query()->findOrFail($locked->boq_id);
            $boq->assertEditable();

            $locked->forceFill([
                'status' => MeasurementSheetStatus::Submitted,
                'updated_by' => $user->id,
            ])->save();

            return $locked->fresh(['lines']);
        });
    }

    public function approve(BoqMeasurementSheet $sheet, User $user): BoqMeasurementSheet
    {
        return DB::transaction(function () use ($sheet, $user) {
            $locked = BoqMeasurementSheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== MeasurementSheetStatus::Submitted) {
                throw ValidationException::withMessages(['sheet' => 'Only submitted measurement sheets can be approved.']);
            }

            $boq = Boq::query()->whereKey($locked->boq_id)->lockForUpdate()->firstOrFail();
            $boq->assertEditable();
            $item = BoqItem::query()->whereKey($locked->boq_item_id)->lockForUpdate()->firstOrFail();
            $item->setRelation('boq', $boq);

            // Supersede prior approved sheets for this item.
            BoqMeasurementSheet::query()
                ->where('boq_item_id', $item->id)
                ->where('status', MeasurementSheetStatus::Approved)
                ->whereKeyNot($locked->id)
                ->update(['status' => MeasurementSheetStatus::Rejected->value, 'rejection_reason' => 'Superseded by newer approved sheet', 'rejected_at' => now()]);

            $locked->forceFill([
                'status' => MeasurementSheetStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'updated_by' => $user->id,
            ])->save();

            if ($item->quantity_source === QuantitySource::Measurement) {
                $this->applyMeasurementQuantity($item, (string) $locked->total_quantity);
            }

            return $locked->fresh(['lines']);
        });
    }

    public function reject(BoqMeasurementSheet $sheet, User $user, string $reason): BoqMeasurementSheet
    {
        return DB::transaction(function () use ($sheet, $user, $reason) {
            $locked = BoqMeasurementSheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== MeasurementSheetStatus::Submitted) {
                throw ValidationException::withMessages(['sheet' => 'Only submitted measurement sheets can be rejected.']);
            }

            $locked->forceFill([
                'status' => MeasurementSheetStatus::Rejected,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
                'updated_by' => $user->id,
            ])->save();

            return $locked->fresh(['lines']);
        });
    }

    public function setQuantitySource(BoqItem $item, QuantitySource $source, ?string $takeoffReference, User $user): BoqItem
    {
        return DB::transaction(function () use ($item, $source, $takeoffReference, $user) {
            $boq = Boq::query()->whereKey($item->boq_id)->lockForUpdate()->firstOrFail();
            $boq->assertEditable();
            $locked = BoqItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('boq', $boq);

            if ($source === QuantitySource::Takeoff && blank($takeoffReference)) {
                // Interface-ready: allow setting source with empty reference for later linkage.
            }

            $locked->forceFill([
                'quantity_source' => $source->value,
                'takeoff_reference' => $source === QuantitySource::Takeoff ? ($takeoffReference ?: null) : null,
            ])->save();

            if ($source === QuantitySource::Measurement) {
                $approved = BoqMeasurementSheet::query()
                    ->where('boq_item_id', $locked->id)
                    ->where('status', MeasurementSheetStatus::Approved)
                    ->latest('id')
                    ->first();
                if ($approved) {
                    $this->applyMeasurementQuantity($locked, (string) $approved->total_quantity);
                }
            }

            return $locked->fresh();
        });
    }

    /**
     * Location breakdown from measurement lines (derived).
     *
     * @return list<array{location: string, quantity: string, lines: int}>
     */
    public function locationBreakdown(BoqMeasurementSheet $sheet): array
    {
        $groups = [];
        foreach ($sheet->lines as $line) {
            $key = filled($line->location) ? (string) $line->location : 'General';
            if (! isset($groups[$key])) {
                $groups[$key] = ['location' => $key, 'quantity' => Decimal::zero(), 'lines' => 0];
            }
            $groups[$key]['quantity'] = $groups[$key]['quantity']->plus($line->calculated_quantity);
            $groups[$key]['lines']++;
        }

        return array_values(array_map(fn ($g) => [
            'location' => $g['location'],
            'quantity' => $g['quantity']->toQuantity(),
            'lines' => $g['lines'],
        ], $groups));
    }

    private function applyMeasurementQuantity(BoqItem $item, string $quantity): void
    {
        $calc = BoqCalculator::line([
            'quantity' => $quantity,
            'material_rate' => $item->material_rate,
            'labour_rate' => $item->labour_rate,
            'equipment_rate' => $item->equipment_rate,
            'subcontract_rate' => $item->subcontract_rate,
            'margin_percent' => $item->margin_percent,
            'client_rate' => $item->client_rate,
        ]);

        // Bypass item saving guard carefully: BOQ already locked & editable.
        $item->forceFill($calc)->save();
        $this->boqs->recalculateTotals($item->boq ?? Boq::query()->findOrFail($item->boq_id));
    }

    private function cloneAsDraft(BoqMeasurementSheet $approved, User $user): BoqMeasurementSheet
    {
        return DB::transaction(function () use ($approved, $user) {
            $clone = new BoqMeasurementSheet;
            $clone->forceFill([
                'company_id' => $approved->company_id,
                'project_id' => $approved->project_id,
                'boq_id' => $approved->boq_id,
                'boq_item_id' => $approved->boq_item_id,
                'line_uid' => $approved->line_uid,
                'drawing_revision_id' => $approved->drawing_revision_id,
                'title' => $approved->title,
                'description' => $approved->description,
                'status' => MeasurementSheetStatus::Draft,
                'total_quantity' => $approved->total_quantity,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();

            foreach ($approved->lines as $line) {
                $copy = new BoqMeasurementLine;
                $copy->forceFill(collect($line->getAttributes())->except(['id', 'boq_measurement_sheet_id', 'created_at', 'updated_at'])->all());
                $copy->boq_measurement_sheet_id = $clone->id;
                $copy->save();
            }

            return $clone->fresh(['lines']);
        });
    }

    private function validatedRevisionId(Boq $boq, ?int $revisionId): ?int
    {
        if ($revisionId === null) {
            return null;
        }

        $revision = DrawingRevision::query()->with('drawing')->find($revisionId);
        if ($revision === null || (int) $revision->drawing?->project_id !== (int) $boq->project_id) {
            throw ValidationException::withMessages(['drawing_revision_id' => 'Drawing revision must belong to this project.']);
        }

        // Prefer approved, but allow linking any revision of the same project for historical MB notes.
        if (! in_array($revision->status, [
            DrawingRevisionStatus::Approved,
            DrawingRevisionStatus::Superseded,
            DrawingRevisionStatus::UnderReview,
            DrawingRevisionStatus::Submitted,
            DrawingRevisionStatus::Draft,
            DrawingRevisionStatus::Rejected,
        ], true)) {
            throw ValidationException::withMessages(['drawing_revision_id' => 'Invalid drawing revision.']);
        }

        return $revision->id;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Decimal::of((string) $value)->toQuantity();
    }
}
