<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\RevisionChangeOrderStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqRevisionChangeOrder;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revision-pair change order workflow. Snapshots comparison only — no budget, procurement, or billing side effects.
 */
class BoqRevisionChangeOrderService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AdvancedBoqRevisionComparisonService $comparison,
    ) {}

    public function createDraft(
        Project $project,
        Boq $from,
        Boq $to,
        User $user,
        ?string $title = null,
        ?string $description = null,
    ): BoqRevisionChangeOrder {
        $this->assertRevisionPair($project, $from, $to);

        return DB::transaction(function () use ($project, $from, $to, $user, $title, $description) {
            $existing = BoqRevisionChangeOrder::query()
                ->where('from_boq_id', $from->id)
                ->where('to_boq_id', $to->id)
                ->lockForUpdate()
                ->first();

            $snapshot = $this->comparison->compare($from, $to, true);
            $summary = $snapshot['summary'] ?? [];

            if ($existing !== null) {
                if (! $existing->status->isEditable()) {
                    return $existing->fresh(['fromBoq:id,version,boq_number', 'toBoq:id,version,boq_number']);
                }

                $existing->forceFill([
                    'comparison_snapshot' => $snapshot,
                    'commercial_variation' => (string) ($summary['commercial_variance'] ?? '0'),
                    'internal_cost_variation' => (string) ($summary['internal_variance'] ?? '0'),
                    'margin_variation' => (string) ($summary['margin_variance'] ?? '0'),
                    'revision_reason' => $to->revision_reason,
                    'title' => $title !== null && trim($title) !== '' ? trim($title) : $existing->title,
                    'description' => $description ?? $existing->description,
                    'prepared_by' => $user->id,
                    'updated_by' => $user->id,
                ])->save();

                $existing->writeAudit('snapshot_refreshed', null, [
                    'from_boq_id' => $from->id,
                    'to_boq_id' => $to->id,
                ]);

                return $existing->fresh(['fromBoq:id,version,boq_number', 'toBoq:id,version,boq_number']);
            }

            $order = new BoqRevisionChangeOrder;
            $order->forceFill([
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'boq_number' => $to->boq_number,
                'from_boq_id' => $from->id,
                'to_boq_id' => $to->id,
                'change_order_number' => $this->numbers->next('boq_revision_change_order', $project),
                'title' => $title !== null && trim($title) !== ''
                    ? trim($title)
                    : "Change Order {$to->boq_number} v{$from->version} → v{$to->version}",
                'description' => $description,
                'revision_reason' => $to->revision_reason,
                'status' => RevisionChangeOrderStatus::Draft,
                'commercial_variation' => (string) ($summary['commercial_variance'] ?? '0'),
                'internal_cost_variation' => (string) ($summary['internal_variance'] ?? '0'),
                'margin_variation' => (string) ($summary['margin_variance'] ?? '0'),
                'comparison_snapshot' => $snapshot,
                'requested_by' => $user->id,
                'prepared_by' => $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $order->save();

            return $order->fresh(['fromBoq:id,version,boq_number', 'toBoq:id,version,boq_number']);
        });
    }

    public function submit(BoqRevisionChangeOrder $changeOrder, User $user): BoqRevisionChangeOrder
    {
        return DB::transaction(function () use ($changeOrder, $user) {
            $locked = BoqRevisionChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages(['change_order' => 'Only draft or rejected change orders can be submitted.']);
            }
            if (! is_array($locked->comparison_snapshot) || $locked->comparison_snapshot === []) {
                throw ValidationException::withMessages(['change_order' => 'Comparison snapshot is missing. Recreate the draft.']);
            }

            $locked->forceFill([
                'status' => RevisionChangeOrderStatus::UnderReview,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'updated_by' => $user->id,
            ])->save();

            $locked->writeAudit('submitted', ['status' => RevisionChangeOrderStatus::Draft->value], [
                'status' => RevisionChangeOrderStatus::UnderReview->value,
                'submitted_by' => $user->id,
            ]);

            return $locked->fresh(['fromBoq', 'toBoq']);
        });
    }

    public function approve(BoqRevisionChangeOrder $changeOrder, User $user): BoqRevisionChangeOrder
    {
        return DB::transaction(function () use ($changeOrder, $user) {
            $locked = BoqRevisionChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RevisionChangeOrderStatus::UnderReview) {
                throw ValidationException::withMessages(['change_order' => 'Only change orders under review can be approved.']);
            }
            if (! config('approvals.allow_self_approval') && (int) $locked->submitted_by === (int) $user->id) {
                throw ValidationException::withMessages(['change_order' => 'You cannot approve a change order you submitted.']);
            }

            $toBoq = Boq::query()->whereKey($locked->to_boq_id)->firstOrFail();
            if (! $this->toBoqReadyForChangeOrderApproval($toBoq)) {
                throw ValidationException::withMessages([
                    'to_boq' => 'The target BOQ revision must be commercially / finally approved before this change order can be approved.',
                ]);
            }

            $locked->forceFill([
                'status' => RevisionChangeOrderStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'updated_by' => $user->id,
            ])->save();

            $locked->writeAudit('approved', ['status' => RevisionChangeOrderStatus::UnderReview->value], [
                'status' => RevisionChangeOrderStatus::Approved->value,
                'approved_by' => $user->id,
                'commercial_variation' => (string) $locked->commercial_variation,
                'internal_cost_variation' => (string) $locked->internal_cost_variation,
            ]);

            return $locked->fresh(['fromBoq', 'toBoq', 'approvedBy:id,name']);
        });
    }

    public function reject(BoqRevisionChangeOrder $changeOrder, User $user, string $reason): BoqRevisionChangeOrder
    {
        return DB::transaction(function () use ($changeOrder, $user, $reason) {
            $locked = BoqRevisionChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RevisionChangeOrderStatus::UnderReview) {
                throw ValidationException::withMessages(['change_order' => 'Only change orders under review can be rejected.']);
            }
            if (! config('approvals.allow_self_approval') && (int) $locked->submitted_by === (int) $user->id) {
                throw ValidationException::withMessages(['change_order' => 'You cannot reject a change order you submitted.']);
            }
            if (mb_strlen(trim($reason)) < 3) {
                throw ValidationException::withMessages(['rejection_reason' => 'Provide a rejection reason.']);
            }

            $locked->forceFill([
                'status' => RevisionChangeOrderStatus::Rejected,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
                'updated_by' => $user->id,
            ])->save();

            $locked->writeAudit('rejected', ['status' => RevisionChangeOrderStatus::UnderReview->value], [
                'status' => RevisionChangeOrderStatus::Rejected->value,
                'rejection_reason' => trim($reason),
            ]);

            return $locked->fresh(['fromBoq', 'toBoq']);
        });
    }

    public function cancel(BoqRevisionChangeOrder $changeOrder, User $user, ?string $reason = null): BoqRevisionChangeOrder
    {
        return DB::transaction(function () use ($changeOrder, $user, $reason) {
            $locked = BoqRevisionChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === RevisionChangeOrderStatus::Approved || $locked->status === RevisionChangeOrderStatus::Cancelled) {
                throw ValidationException::withMessages(['change_order' => 'Approved or cancelled change orders cannot be cancelled again.']);
            }

            $from = $locked->status->value;
            $locked->forceFill([
                'status' => RevisionChangeOrderStatus::Cancelled,
                'updated_by' => $user->id,
            ])->save();

            $locked->writeAudit('cancelled', ['status' => $from], [
                'status' => RevisionChangeOrderStatus::Cancelled->value,
                'reason' => $reason,
            ]);

            return $locked->fresh(['fromBoq', 'toBoq']);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pdfLines(BoqRevisionChangeOrder $changeOrder, bool $withCosts): array
    {
        $snapshot = $changeOrder->comparison_snapshot;
        if (! is_array($snapshot)) {
            return [];
        }

        $rows = [];
        foreach ($snapshot['items'] ?? [] as $item) {
            $rows[] = [
                'status' => $item['status'] ?? 'modified',
                'line_uid' => $item['line_uid'] ?? null,
                'item_code' => $item['item_code'] ?? null,
                'description' => $item['description'] ?? null,
                'unit' => $item['unit'] ?? null,
                'change_types' => $item['change_types'] ?? [],
                'variation_class' => $item['variation_class'] ?? null,
                'previous_qty' => $item['previous_qty'] ?? null,
                'current_qty' => $item['current_qty'] ?? null,
                'qty_difference' => $item['qty_difference'] ?? null,
                'previous_client_rate' => $item['previous_client_rate'] ?? null,
                'current_client_rate' => $item['current_client_rate'] ?? null,
                'commercial_difference' => $item['commercial_difference'] ?? null,
                'previous_commercial_amount' => $item['previous_commercial_amount'] ?? null,
                'current_commercial_amount' => $item['current_commercial_amount'] ?? null,
                'previous_internal_cost' => $withCosts ? ($item['previous_internal_cost'] ?? null) : null,
                'current_internal_cost' => $withCosts ? ($item['current_internal_cost'] ?? null) : null,
                'internal_cost_difference' => $withCosts ? ($item['internal_cost_difference'] ?? null) : null,
                'margin_previous' => $withCosts ? $this->lineMargin(
                    (string) ($item['previous_commercial_amount'] ?? '0'),
                    (string) ($item['previous_internal_cost'] ?? '0'),
                ) : null,
                'margin_current' => $withCosts ? $this->lineMargin(
                    (string) ($item['current_commercial_amount'] ?? '0'),
                    (string) ($item['current_internal_cost'] ?? '0'),
                ) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function pdfPayload(BoqRevisionChangeOrder $changeOrder, bool $withCosts): array
    {
        $snapshot = is_array($changeOrder->comparison_snapshot) ? $changeOrder->comparison_snapshot : [];
        $summary = $snapshot['summary'] ?? [];

        return [
            'change_order' => $changeOrder,
            'header' => $snapshot['header'] ?? [],
            'summary' => [
                'added' => $summary['added'] ?? 0,
                'removed' => $summary['removed'] ?? 0,
                'modified' => $summary['modified'] ?? 0,
                'commercial_variance' => (string) $changeOrder->commercial_variation,
                'internal_variance' => $withCosts ? (string) $changeOrder->internal_cost_variation : null,
                'margin_variance' => $withCosts ? (string) $changeOrder->margin_variation : null,
                'impact' => $summary['impact'] ?? null,
            ],
            'lines' => $this->pdfLines($changeOrder, $withCosts),
            'with_costs' => $withCosts,
        ];
    }

    private function lineMargin(string $commercial, string $internal): string
    {
        return Decimal::of($commercial)->minus($internal)->toMoney();
    }

    private function assertRevisionPair(Project $project, Boq $from, Boq $to): void
    {
        if ((int) $from->project_id !== (int) $project->id || (int) $to->project_id !== (int) $project->id) {
            throw ValidationException::withMessages(['boq' => 'Both revisions must belong to this project.']);
        }
        if ((string) $from->boq_number !== (string) $to->boq_number) {
            throw ValidationException::withMessages(['boq' => 'Revisions must share the same BOQ number.']);
        }
        if ((int) $from->id === (int) $to->id) {
            throw ValidationException::withMessages(['boq' => 'Select two different revisions to compare.']);
        }
    }

    private function toBoqReadyForChangeOrderApproval(Boq $toBoq): bool
    {
        if ($toBoq->status === BoqStatus::Approved) {
            return true;
        }

        return $toBoq->approved_at !== null;
    }
}
