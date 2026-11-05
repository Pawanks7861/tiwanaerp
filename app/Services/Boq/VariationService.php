<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\QuantitySource;
use App\Enums\Boq\VariationChangeType;
use App\Enums\Boq\VariationSourceType;
use App\Enums\Boq\VariationStatus;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Boq\BoqVariation;
use App\Models\Boq\BoqVariationItem;
use App\Models\Documents\DrawingRevision;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Math\Decimal;
use App\Support\Notifications\PermissionRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stage 8 — Variation / Extra Item workflow.
 *
 * Draft/Submitted/Rejected: ZERO effect on effective qty, material requirement, estimate,
 * progress caps, billing, or reconciliation.
 * Approved: updates effective contractual scope only (no stock / cost ledger / Tally).
 */
class VariationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly EffectiveBoqQuantityService $effective,
        private readonly PermissionRecipients $recipients,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function create(Project $project, Boq $boq, array $header, array $items, User $user): BoqVariation
    {
        $this->assertBoqBelongs($project, $boq);
        if ($boq->status !== BoqStatus::Approved || ! $boq->is_current) {
            throw ValidationException::withMessages(['boq' => 'Variations require the project\'s current approved BOQ.']);
        }

        return DB::transaction(function () use ($project, $boq, $header, $items, $user) {
            $variation = new BoqVariation;
            $variation->forceFill([
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'boq_id' => $boq->id,
                'variation_number' => $this->numbers->next('boq_variation', $project),
                'status' => VariationStatus::Draft,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $this->fillHeader($variation, $header, $project);
            $variation->save();

            $this->replaceItems($variation, $items);
            $this->recomputeImpacts($variation);

            return $variation->fresh(['items.unit', 'items.section', 'drawingRevision.drawing', 'boq']);
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function update(BoqVariation $variation, array $header, array $items, User $user): BoqVariation
    {
        return DB::transaction(function () use ($variation, $header, $items, $user) {
            $locked = BoqVariation::query()->whereKey($variation->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $this->fillHeader($locked, $header, $locked->project);
            $locked->forceFill(['updated_by' => $user->id])->save();
            $this->replaceItems($locked, $items);
            $this->recomputeImpacts($locked);

            return $locked->fresh(['items.unit', 'items.section', 'drawingRevision.drawing', 'boq']);
        });
    }

    public function submit(BoqVariation $variation, User $user): BoqVariation
    {
        return DB::transaction(function () use ($variation, $user) {
            $locked = BoqVariation::query()->whereKey($variation->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages(['variation' => 'Only draft or rejected variations can be submitted.']);
            }
            if ($locked->items()->count() === 0) {
                throw ValidationException::withMessages(['items' => 'Add at least one variation item before submitting.']);
            }

            $this->recomputeImpacts($locked);
            $locked->forceFill([
                'status' => VariationStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'updated_by' => $user->id,
            ])->save();

            $locked->writeAudit('submitted', ['status' => VariationStatus::Draft->value], [
                'status' => VariationStatus::Submitted->value,
                'submitted_by' => $user->id,
            ]);

            $this->notifyWorkflow($locked, 'boq.variation_submitted', 'Variation submitted',
                "{$locked->variation_number} was submitted for approval.", 'boq.approve_variations', excludeUserId: (int) $user->id);

            return $locked->fresh(['items']);
        });
    }

    public function approve(BoqVariation $variation, User $user): BoqVariation
    {
        return DB::transaction(function () use ($variation, $user) {
            $locked = BoqVariation::query()->whereKey($variation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== VariationStatus::Submitted) {
                throw ValidationException::withMessages(['variation' => 'Only submitted variations can be approved.']);
            }
            if (! config('approvals.allow_self_approval') && (int) $locked->submitted_by === (int) $user->id) {
                throw ValidationException::withMessages(['variation' => 'You cannot approve a variation you submitted.']);
            }

            $this->assertEffectiveQuantitiesValid($locked);
            $this->recomputeImpacts($locked);

            foreach ($locked->items as $item) {
                if ($item->change_type === VariationChangeType::NewItem) {
                    $this->materializeNewItem($locked, $item, $user);
                }
            }

            $locked->forceFill([
                'status' => VariationStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'updated_by' => $user->id,
            ])->save();

            $locked->writeAudit('approved', ['status' => VariationStatus::Submitted->value], [
                'status' => VariationStatus::Approved->value,
                'approved_by' => $user->id,
                'revenue_impact' => (string) $locked->revenue_impact,
                'cost_impact' => (string) $locked->cost_impact,
            ]);

            $this->notifyWorkflow($locked, 'boq.variation_approved', 'Variation approved',
                "{$locked->variation_number} was approved.", 'boq.view_variations',
                includeUserId: $locked->submitted_by ? (int) $locked->submitted_by : null,
                excludeUserId: (int) $user->id);

            return $locked->fresh(['items.materializedItem', 'items.originalItem']);
        });
    }

    public function reject(BoqVariation $variation, User $user, string $reason): BoqVariation
    {
        return DB::transaction(function () use ($variation, $user, $reason) {
            $locked = BoqVariation::query()->whereKey($variation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== VariationStatus::Submitted) {
                throw ValidationException::withMessages(['variation' => 'Only submitted variations can be rejected.']);
            }
            if (! config('approvals.allow_self_approval') && (int) $locked->submitted_by === (int) $user->id) {
                throw ValidationException::withMessages(['variation' => 'You cannot reject a variation you submitted.']);
            }
            if (mb_strlen(trim($reason)) < 3) {
                throw ValidationException::withMessages(['rejection_reason' => 'Provide a rejection reason.']);
            }

            $locked->forceFill([
                'status' => VariationStatus::Rejected,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
                'updated_by' => $user->id,
            ])->save();

            $locked->writeAudit('rejected', ['status' => VariationStatus::Submitted->value], [
                'status' => VariationStatus::Rejected->value,
                'rejection_reason' => trim($reason),
            ]);

            $this->notifyWorkflow($locked, 'boq.variation_rejected', 'Variation rejected',
                "{$locked->variation_number} was rejected.", 'boq.manage_variations',
                includeUserId: $locked->submitted_by ? (int) $locked->submitted_by : null,
                excludeUserId: (int) $user->id);

            return $locked->fresh(['items']);
        });
    }

    public function delete(BoqVariation $variation): void
    {
        DB::transaction(function () use ($variation) {
            $locked = BoqVariation::query()->whereKey($variation->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $locked->items()->delete();
            $locked->delete();
        });
    }

    /**
     * Approval preview (server-side). Does not mutate.
     *
     * @return array<string, mixed>
     */
    public function approvalPreview(BoqVariation $variation): array
    {
        $project = $variation->project;
        $summary = $this->effective->contractValueSummary($project);
        $this->recomputeImpacts($variation->fresh(['items']));
        $variation->refresh();

        return [
            'current_contract_value' => $summary['effective_contract_value'],
            'variation_revenue_impact' => (string) $variation->revenue_impact,
            'effective_contract_value_after' => Decimal::of($summary['effective_contract_value'])
                ->plus((string) $variation->revenue_impact)->toMoney(),
            'variation_cost_impact' => (string) $variation->cost_impact,
            'variation_margin_impact' => (string) $variation->margin_impact,
            'original_contract_value' => $summary['original_contract_value'],
            'approved_variation_value' => $summary['approved_variation_value'],
            'pending_variation_value' => $summary['pending_variation_value'],
        ];
    }

    /**
     * Apply MeasurementCalculator to a variation item measurement payload and return qty.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function measureQuantity(array $lines): string
    {
        $total = Decimal::zero();
        foreach ($lines as $line) {
            $calc = MeasurementCalculator::line($line);
            $total = $total->plus($calc['calculated_quantity']);
        }

        return $total->toQuantity();
    }

    private function fillHeader(BoqVariation $variation, array $header, Project $project): void
    {
        $source = VariationSourceType::tryFrom((string) ($header['source_type'] ?? ''))
            ?? throw ValidationException::withMessages(['source_type' => 'Select a valid source type.']);
        $changeOrder = VariationChangeType::tryFrom((string) ($header['change_order_type'] ?? VariationChangeType::ScopeChange->value))
            ?? VariationChangeType::ScopeChange;

        $reason = trim((string) ($header['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Reason is required.']);
        }
        if ($source === VariationSourceType::Other && mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Provide a clear reason when source is Other.']);
        }

        $drawingId = $header['drawing_revision_id'] ?? null;
        if ($drawingId) {
            $rev = DrawingRevision::query()->with('drawing')->whereKey((int) $drawingId)->firstOrFail();
            if ((int) $rev->company_id !== (int) $project->company_id
                || (int) ($rev->drawing?->project_id) !== (int) $project->id) {
                throw ValidationException::withMessages(['drawing_revision_id' => 'Drawing revision must belong to this project.']);
            }
            if (! in_array($rev->status, [DrawingRevisionStatus::Approved, DrawingRevisionStatus::Superseded], true)) {
                throw ValidationException::withMessages(['drawing_revision_id' => 'Link an approved or superseded drawing revision.']);
            }
        }

        $variation->forceFill([
            'title' => trim((string) ($header['title'] ?? '')),
            'description' => $header['description'] ?? null,
            'change_order_type' => $changeOrder,
            'source_type' => $source,
            'source_reference' => $header['source_reference'] ?? null,
            'drawing_revision_id' => $drawingId ? (int) $drawingId : null,
            'client_instruction_reference' => $header['client_instruction_reference'] ?? null,
            'client_instruction_date' => $header['client_instruction_date'] ?? null,
            'reason' => $reason,
        ]);

        if ($variation->title === '') {
            throw ValidationException::withMessages(['title' => 'Title is required.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceItems(BoqVariation $variation, array $items): void
    {
        if ($items === []) {
            $variation->items()->delete();

            return;
        }

        $variation->items()->delete();
        $seq = 0;
        foreach ($items as $row) {
            $built = $this->buildItemRow($variation, $row, $seq++);
            $item = new BoqVariationItem;
            $item->forceFill($built)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function buildItemRow(BoqVariation $variation, array $row, int $sequence): array
    {
        $type = VariationChangeType::tryFrom((string) ($row['change_type'] ?? ''))
            ?? throw ValidationException::withMessages(['change_type' => 'Select a valid change type.']);

        $uid = (string) ($row['variation_item_uid'] ?? Str::uuid());
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Item description is required.']);
        }

        $qtySource = QuantitySource::tryFrom((string) ($row['quantity_source'] ?? QuantitySource::Direct->value))
            ?? QuantitySource::Direct;

        $measurementPayload = $row['measurement_payload'] ?? null;
        $quantityChange = Decimal::of((string) ($row['quantity_change'] ?? '0'));

        if ($qtySource === QuantitySource::Measurement && is_array($measurementPayload) && $measurementPayload !== []) {
            $quantityChange = Decimal::of($this->measureQuantity($measurementPayload));
            if ($type === VariationChangeType::QuantityDecrease || $type === VariationChangeType::OmittedItem) {
                $quantityChange = $quantityChange->negated();
            }
        }

        $originalItem = null;
        $originalQty = Decimal::zero();
        $originalSell = Decimal::zero();
        $originalCost = Decimal::zero();
        $originalLineUid = null;
        $originalItemId = null;
        $unitId = isset($row['unit_id']) ? (int) $row['unit_id'] : null;
        $sectionId = isset($row['boq_section_id']) ? (int) $row['boq_section_id'] : null;
        $gst = Decimal::of((string) ($row['gst_rate'] ?? '0'))->round(Decimal::PERCENT_SCALE);

        if ($type !== VariationChangeType::NewItem) {
            $originalItemId = (int) ($row['original_boq_item_id'] ?? 0);
            $originalItem = BoqItem::query()->whereKey($originalItemId)
                ->where('boq_id', $variation->boq_id)->first();
            if (! $originalItem) {
                throw ValidationException::withMessages(['original_boq_item_id' => 'Select an item from the approved BOQ.']);
            }
            $originalQty = Decimal::of((string) $originalItem->quantity);
            $originalSell = Decimal::of((string) ($originalItem->client_rate ?: $originalItem->selling_rate));
            $originalCost = Decimal::of((string) $originalItem->cost_rate);
            $originalLineUid = (string) $originalItem->line_uid;
            $unitId = $unitId ?: (int) $originalItem->unit_id;
            $sectionId = $sectionId ?: (int) $originalItem->boq_section_id;
            if (! isset($row['gst_rate'])) {
                $gst = Decimal::zero();
            }
        } else {
            if (! $unitId || ! Unit::query()->whereKey($unitId)->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'Unit is required for new items.']);
            }
            if (! $sectionId) {
                $sectionId = $this->variationSectionId($variation->boq_id);
            }
            BoqSection::query()->whereKey($sectionId)->where('boq_id', $variation->boq_id)->firstOrFail();
        }

        // Normalize quantity change by type
        if ($type === VariationChangeType::QuantityIncrease) {
            if ($quantityChange->isNegative() || $quantityChange->isZero()) {
                throw ValidationException::withMessages(['quantity_change' => 'Quantity increase must be positive.']);
            }
        } elseif ($type === VariationChangeType::QuantityDecrease) {
            if ($quantityChange->isPositive()) {
                $quantityChange = $quantityChange->negated();
            }
            if ($quantityChange->isZero()) {
                throw ValidationException::withMessages(['quantity_change' => 'Quantity decrease must be non-zero.']);
            }
            if ($originalQty->plus($quantityChange)->isNegative()) {
                throw ValidationException::withMessages(['quantity_change' => 'Effective quantity cannot go below zero.']);
            }
        } elseif ($type === VariationChangeType::OmittedItem) {
            $quantityChange = $originalQty->negated();
        } elseif ($type === VariationChangeType::NewItem) {
            if ($quantityChange->isNegative() || $quantityChange->isZero()) {
                throw ValidationException::withMessages(['quantity_change' => 'New item quantity must be positive.']);
            }
            $originalQty = Decimal::zero();
        } elseif ($type === VariationChangeType::RateChange) {
            $quantityChange = Decimal::zero();
        }

        $proposedSell = isset($row['proposed_selling_rate'])
            ? Decimal::of((string) $row['proposed_selling_rate'])
            : $originalSell;
        $proposedCost = isset($row['proposed_cost_rate'])
            ? Decimal::of((string) $row['proposed_cost_rate'])
            : $originalCost;

        if ($type === VariationChangeType::QuantityIncrease || $type === VariationChangeType::QuantityDecrease
            || $type === VariationChangeType::OmittedItem) {
            // Quantity-only: default rates to original (do not accidentally change rate).
            $proposedSell = $originalSell;
            $proposedCost = $originalCost;
        }

        if ($type === VariationChangeType::RateChange) {
            if ($proposedSell->equals($originalSell) && $proposedCost->equals($originalCost)) {
                throw ValidationException::withMessages(['proposed_selling_rate' => 'Rate change requires a different proposed rate.']);
            }
        }

        if ($type === VariationChangeType::NewItem) {
            $revised = $quantityChange;
        } else {
            $revised = $originalQty->plus($quantityChange);
            if ($revised->isNegative()) {
                throw ValidationException::withMessages(['quantity_change' => 'Effective quantity cannot go below zero.']);
            }
        }

        if ($type === VariationChangeType::RateChange) {
            // Prospective commercial note: impact shown against original qty remaining conceptually = 0 operationally until billed.
            $revenue = Decimal::zero();
            $cost = Decimal::zero();
        } elseif ($type === VariationChangeType::NewItem) {
            $revenue = $quantityChange->times($proposedSell)->round(Decimal::MONEY_SCALE);
            $cost = $quantityChange->times($proposedCost)->round(Decimal::MONEY_SCALE);
        } else {
            $revenue = $quantityChange->times($proposedSell)->round(Decimal::MONEY_SCALE);
            $cost = $quantityChange->times($proposedCost)->round(Decimal::MONEY_SCALE);
        }
        $margin = $revenue->minus($cost)->round(Decimal::MONEY_SCALE);

        return [
            'variation_id' => $variation->id,
            'original_boq_item_id' => $originalItemId,
            'original_line_uid' => $originalLineUid,
            'variation_item_uid' => $uid,
            'change_type' => $type,
            'item_code' => $row['item_code'] ?? $originalItem?->item_code,
            'name' => $name,
            'description' => $row['description'] ?? $originalItem?->description,
            'hsn_sac' => $row['hsn_sac'] ?? $originalItem?->hsn_sac,
            'unit_id' => $unitId,
            'boq_section_id' => $sectionId,
            'quantity_source' => $qtySource,
            'takeoff_reference' => $row['takeoff_reference'] ?? null,
            'original_quantity' => $originalQty->toQuantity(),
            'quantity_change' => $quantityChange->toQuantity(),
            'revised_quantity' => $revised->toQuantity(),
            'original_selling_rate' => $originalSell->toRate(),
            'proposed_selling_rate' => $proposedSell->toRate(),
            'original_cost_rate' => $originalCost->toRate(),
            'proposed_cost_rate' => $proposedCost->toRate(),
            'gst_rate' => $gst->round(Decimal::PERCENT_SCALE)->toString(),
            'revenue_impact' => $revenue->toMoney(),
            'cost_impact' => $cost->toMoney(),
            'margin_impact' => $margin->toMoney(),
            'notes' => $row['notes'] ?? null,
            'measurement_payload' => is_array($measurementPayload) ? $measurementPayload : null,
            'sequence' => $sequence,
        ];
    }

    private function recomputeImpacts(BoqVariation $variation): void
    {
        $items = $variation->items()->get();
        $rev = Decimal::zero();
        $cost = Decimal::zero();
        foreach ($items as $item) {
            $rev = $rev->plus((string) $item->revenue_impact);
            $cost = $cost->plus((string) $item->cost_impact);
        }
        $variation->forceFill([
            'revenue_impact' => $rev->toMoney(),
            'cost_impact' => $cost->toMoney(),
            'margin_impact' => $rev->minus($cost)->toMoney(),
        ])->save();
    }

    private function assertEffectiveQuantitiesValid(BoqVariation $variation): void
    {
        foreach ($variation->items as $item) {
            if (! $item->original_line_uid || $item->change_type === VariationChangeType::NewItem
                || $item->change_type === VariationChangeType::RateChange) {
                continue;
            }
            $original = BoqItem::query()->whereKey($item->original_boq_item_id)->first();
            if (! $original) {
                continue;
            }
            $currentEffective = Decimal::of($this->effective->forItem($original));
            $after = $currentEffective->plus((string) $item->quantity_change);
            if ($after->isNegative()) {
                throw ValidationException::withMessages([
                    'items' => "Approving would drive \"{$item->name}\" effective quantity below zero.",
                ]);
            }
        }
    }

    private function materializeNewItem(BoqVariation $variation, BoqVariationItem $item, User $user): void
    {
        if ($item->materialized_boq_item_id) {
            return;
        }

        $boq = Boq::query()->whereKey($variation->boq_id)->firstOrFail();
        $costRate = Decimal::of((string) $item->proposed_cost_rate);
        $qty = Decimal::of((string) $item->quantity_change);
        $clientRate = Decimal::of((string) $item->proposed_selling_rate);
        // Put proposed cost into material_rate so cost_rate identity holds for Stage 3/6 consumers.
        $calc = BoqCalculator::line([
            'quantity' => $qty->toQuantity(),
            'material_rate' => $costRate->toRate(),
            'labour_rate' => '0',
            'equipment_rate' => '0',
            'subcontract_rate' => '0',
            'margin_percent' => '0',
            'client_rate' => $clientRate->toRate(),
        ]);

        $newItem = new BoqItem;
        $newItem->boq()->associate($boq);
        $newItem->forceFill([
            'boq_section_id' => $item->boq_section_id,
            'line_uid' => $item->variation_item_uid,
            'item_code' => $item->item_code ?: ('VAR-'.Str::upper(Str::random(4))),
            'name' => $item->name,
            'description' => $item->description,
            'hsn_sac' => $item->hsn_sac,
            'unit_id' => $item->unit_id,
            'quantity' => $calc['quantity'],
            'quantity_source' => $item->quantity_source,
            'takeoff_reference' => $item->takeoff_reference,
            'material_rate' => $calc['material_rate'],
            'labour_rate' => $calc['labour_rate'],
            'equipment_rate' => $calc['equipment_rate'],
            'subcontract_rate' => $calc['subcontract_rate'],
            'cost_rate' => $calc['cost_rate'],
            'cost_amount' => $calc['cost_amount'],
            'margin_percent' => $calc['margin_percent'],
            'selling_rate' => $calc['selling_rate'],
            'client_rate' => $calc['client_rate'],
            'client_amount' => $calc['client_amount'],
            'origin_variation_item_id' => $item->id,
            'is_variation_originated' => true,
            'sort_order' => 9000 + (int) $item->sequence,
        ]);
        // Bypass BOQ edit lock — variation approval is the authorized path.
        $newItem->saveQuietly();

        $item->forceFill(['materialized_boq_item_id' => $newItem->id])->save();
    }

    private function variationSectionId(int $boqId): int
    {
        $existing = BoqSection::query()->where('boq_id', $boqId)
            ->where(function ($q) {
                $q->where('code', 'VAR')->orWhere('name', 'like', 'Variation%');
            })->first();
        if ($existing) {
            return (int) $existing->id;
        }

        $section = new BoqSection;
        $section->forceFill([
            'boq_id' => $boqId,
            'code' => 'VAR',
            'name' => 'Variation / Extra Work',
            'sort_order' => 9999,
        ]);
        // Section may also guard on BOQ editability — use quiet insert.
        DB::table('boq_sections')->insert([
            'boq_id' => $boqId,
            'code' => 'VAR',
            'name' => 'Variation / Extra Work',
            'sort_order' => 9999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) BoqSection::query()->where('boq_id', $boqId)->where('code', 'VAR')->value('id');
    }

    private function notifyWorkflow(
        BoqVariation $variation,
        string $kind,
        string $title,
        string $body,
        string $permission,
        ?int $includeUserId = null,
        ?int $excludeUserId = null,
    ): void {
        $users = $this->recipients->in((int) $variation->company_id, [$permission], (int) $variation->project_id);
        if ($includeUserId) {
            $users = $users->merge($this->recipients->users((int) $variation->company_id, [$includeUserId]))->unique('id');
        }
        if ($excludeUserId) {
            $users = $users->reject(fn (User $u) => (int) $u->id === $excludeUserId)->values();
        }
        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new GeneralNotification(
            (int) $variation->company_id,
            $kind,
            $title,
            $body,
            route('projects.variations.show', [$variation->project_id, $variation->id], absolute: false),
            ['project_id' => (int) $variation->project_id, 'variation_id' => (int) $variation->id],
        ));
    }

    private function assertBoqBelongs(Project $project, Boq $boq): void
    {
        if ((int) $boq->project_id !== (int) $project->id) {
            throw ValidationException::withMessages(['boq' => 'BOQ does not belong to this project.']);
        }
    }
}
