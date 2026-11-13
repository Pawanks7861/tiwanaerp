<?php

namespace App\Services\Scrap;

use App\Enums\Scrap\ScrapGenerationStatus;
use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Enums\Scrap\ScrapSourceType;
use App\Models\Masters\Department;
use App\Models\Masters\ScrapType;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapGeneration;
use App\Models\Scrap\ScrapGenerationItem;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scrap generation documents. Ledger `generation_in` posts only from `post()` when status is
 * Submitted → Approved (idempotent per generation item).
 *
 * Dual path (R2): active `scrap_generation` workflow → ApprovalService; otherwise legacy
 * submit sets Submitted and `approve()` calls `post()`. Reject/send-back/withdraw via the
 * approval engine return the document to **Draft** (no Rejected enum — R3). Cancel before
 * post is allowed; posted generations cannot be cancelled in this release.
 */
class ScrapGenerationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ScrapLedgerService $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): ScrapGeneration
    {
        return DB::transaction(function () use ($project, $data) {
            $generation = new ScrapGeneration($this->header($data));
            $generation->forceFill([
                'project_id' => $project->id,
                'generation_number' => $this->numbers->next('scrap_generation', $project),
                'status' => ScrapGenerationStatus::Draft,
            ])->save();

            $this->replaceItems($generation, $data['items'] ?? []);

            return $generation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ScrapGeneration $generation, array $data): ScrapGeneration
    {
        return DB::transaction(function () use ($generation, $data) {
            $generation = ScrapGeneration::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $generation->assertEditable();
            $generation->fill($this->header($data))->save();
            $this->replaceItems($generation, $data['items'] ?? []);

            return $generation;
        });
    }

    public function delete(ScrapGeneration $generation): void
    {
        $generation->assertEditable();
        $generation->items()->delete();
        $generation->delete();
    }

    public function submit(ScrapGeneration $generation, User $user): void
    {
        DB::transaction(function () use ($generation, $user) {
            $locked = ScrapGeneration::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            if ($locked->items()->doesntExist()) {
                throw ValidationException::withMessages(['items' => 'Add at least one line before submitting.']);
            }

            $locked->forceFill(['submitted_by' => $user->id])->save();

            if ($this->approvals->resolveWorkflow($locked) !== null) {
                $this->approvals->submit($locked, $user);
            } else {
                $locked->forceFill(['status' => ScrapGenerationStatus::Submitted])->save();
            }

            $generation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function approve(ScrapGeneration $generation, User $user, ?string $comments = null): void
    {
        $pending = $generation->pendingApprovalRequest();
        if ($pending !== null) {
            $this->approvals->approve($pending, $user, $comments ?? 'Approved.');

            return;
        }

        $this->post($generation, $user->id);
    }

    public function post(ScrapGeneration $generation, ?int $approverId): void
    {
        DB::transaction(function () use ($generation, $approverId) {
            $generation = ScrapGeneration::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();
            if ($generation->status === ScrapGenerationStatus::Approved) {
                return;
            }
            if ($generation->status !== ScrapGenerationStatus::Submitted) {
                throw ValidationException::withMessages(['scrap_generation' => 'Only a submitted generation can be approved.']);
            }

            $project = Project::query()->findOrFail($generation->project_id);
            $items = $generation->items()->reorder('scrap_type_id')->orderBy('id')->get();

            foreach ($items as $item) {
                $this->ledger->post(new ScrapMovement(
                    reference: $item,
                    type: ScrapLedgerTxnType::GenerationIn,
                    project: $project,
                    scrapTypeId: $item->scrap_type_id,
                    unitId: $item->unit_id,
                    quantity: Decimal::of($item->quantity),
                    date: $generation->generation_date,
                    referenceNumber: $generation->generation_number,
                    remarks: $generation->generation_number,
                    userId: $approverId,
                ));
            }

            $generation->forceFill([
                'status' => ScrapGenerationStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
        });
    }

    public function cancel(ScrapGeneration $generation, User $user, string $reason): void
    {
        DB::transaction(function () use ($generation, $user, $reason) {
            $locked = ScrapGeneration::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ScrapGenerationStatus::Cancelled) {
                return;
            }
            if ($locked->status === ScrapGenerationStatus::Approved) {
                throw ValidationException::withMessages(['scrap_generation' => 'A posted generation cannot be cancelled in this release.']);
            }
            if (! $locked->status->isEditable() && $locked->status !== ScrapGenerationStatus::Submitted) {
                throw ValidationException::withMessages(['scrap_generation' => 'This generation cannot be cancelled.']);
            }

            $locked->forceFill([
                'status' => ScrapGenerationStatus::Cancelled,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $generation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        $source = ScrapSourceType::tryFrom((string) ($data['source_type'] ?? ''))
            ?? throw ValidationException::withMessages(['source_type' => 'Choose where the scrap came from.']);

        $departmentId = blank($data['department_id'] ?? null) ? null : (int) $data['department_id'];
        if ($departmentId !== null && Department::query()->whereKey($departmentId)->doesntExist()) {
            throw ValidationException::withMessages(['department_id' => 'Choose a department of this company.']);
        }

        return [
            'generation_date' => $data['generation_date'],
            'location' => $data['location'] ?? null,
            'source_type' => $source,
            'source_reference_type' => $data['source_reference_type'] ?? null,
            'source_reference_id' => $data['source_reference_id'] ?? null,
            'department_id' => $departmentId,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceItems(ScrapGeneration $generation, array $rows): void
    {
        ScrapGenerationItem::query()->where('scrap_generation_id', $generation->id)->delete();

        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }

        foreach (array_values($rows) as $index => $row) {
            $scrapType = ScrapType::query()->active()->whereKey((int) ($row['scrap_type_id'] ?? 0))->first()
                ?? throw ValidationException::withMessages(["items.{$index}.scrap_type_id" => 'Choose an active scrap type.']);

            $qty = Decimal::of((string) ($row['quantity'] ?? '0'));
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Quantity must be greater than zero.']);
            }

            $unitId = blank($row['unit_id'] ?? null) ? $scrapType->unit_id : (int) $row['unit_id'];
            if ($unitId === null) {
                throw ValidationException::withMessages(["items.{$index}.unit_id" => 'This scrap type needs a default unit in masters.']);
            }
            if ($scrapType->unit_id !== null && (int) $unitId !== (int) $scrapType->unit_id) {
                throw ValidationException::withMessages(["items.{$index}.unit_id" => 'Use the unit defined for this scrap type.']);
            }

            (new ScrapGenerationItem)->forceFill([
                'scrap_generation_id' => $generation->id,
                'scrap_type_id' => $scrapType->id,
                'description' => $row['description'] ?? null,
                'quantity' => $qty->toQuantity(),
                'unit_id' => $unitId,
                'weight' => blank($row['weight'] ?? null) ? null : Decimal::of((string) $row['weight'])->toQuantity(),
                'condition' => $row['condition'] ?? null,
                'estimated_rate' => blank($row['estimated_rate'] ?? null) ? null : Decimal::of((string) $row['estimated_rate'])->toRate(),
                'estimated_value' => blank($row['estimated_value'] ?? null) ? null : Decimal::of((string) $row['estimated_value'])->toMoney(),
                'location' => $row['location'] ?? null,
                'remarks' => $row['remarks'] ?? null,
                'sort_order' => $index + 1,
            ])->save();
        }
    }
}
