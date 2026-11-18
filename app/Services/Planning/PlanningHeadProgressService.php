<?php

namespace App\Services\Planning;

use App\Enums\Planning\TaskStatus;
use App\Models\Planning\ProjectTask;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;

/**
 * Derives Planning Head progress % and status from child activities.
 * Same-unit children: Σ executed / Σ planned. Mixed units: equal-weight average of child %.
 * Only approved Progress Ledger quantities (via task caches) are used.
 */
final class PlanningHeadProgressService
{
    /**
     * @return array{
     *   progress_percent: string,
     *   status: string,
     *   status_label: string,
     *   aggregation: 'quantity'|'percent',
     *   planned_qty: ?string,
     *   completed_qty: ?string,
     *   balance_qty: ?string,
     *   unit_id: ?int,
     *   children: list<array{id:int,name:string,progress_percent:string,status:string,unit_id:?int}>
     * }
     */
    public function summarize(ProjectTask $head): array
    {
        if (! $head->isPlanningHead()) {
            throw new \InvalidArgumentException('Planning head progress requires a root task.');
        }

        $children = $head->relationLoaded('children')
            ? $head->children
            : $head->children()->orderBy('sort_order')->orderBy('id')->get();

        if ($children->isEmpty()) {
            return $this->payload(
                percent: Decimal::of($head->progress_percent ?? '0'),
                status: $head->status instanceof TaskStatus ? $head->status : TaskStatus::NotStarted,
                aggregation: 'percent',
                planned: null,
                completed: null,
                unitId: $head->unit_id ? (int) $head->unit_id : null,
                children: [],
            );
        }

        $compatible = $this->unitsCompatible($children);
        if ($compatible) {
            $planned = Decimal::zero();
            $completed = Decimal::zero();
            foreach ($children as $child) {
                $planned = $planned->plus(Decimal::of($child->planned_qty ?? '0'));
                $completed = $completed->plus(Decimal::of($child->completed_qty ?? '0'));
            }
            $percent = $planned->isPositive()
                ? $completed->times(100)->dividedBy($planned)
                : Decimal::of($this->averagePercent($children));
            if ($percent->greaterThan(100)) {
                $percent = Decimal::of('100');
            }

            return $this->payload(
                percent: $percent,
                status: $this->deriveStatus($children),
                aggregation: 'quantity',
                planned: $planned->toQuantity(),
                completed: $completed->toQuantity(),
                unitId: (int) $children->first()->unit_id,
                children: $this->childRows($children),
            );
        }

        $avg = Decimal::of($this->averagePercent($children));

        return $this->payload(
            percent: $avg,
            status: $this->deriveStatus($children),
            aggregation: 'percent',
            planned: null,
            completed: null,
            unitId: null,
            children: $this->childRows($children),
        );
    }

    /**
     * Persist derived caches onto the head row (status + progress_percent). Does not invent qty totals when mixed.
     */
    public function recompute(ProjectTask $head): ProjectTask
    {
        if (! $head->isPlanningHead()) {
            return $head;
        }

        $summary = $this->summarize($head);
        $head->forceFill([
            'progress_percent' => Decimal::of($summary['progress_percent'])->round(Decimal::PERCENT_SCALE)->toString(),
            'status' => TaskStatus::from($summary['status']),
        ])->save();

        return $head->fresh();
    }

    /**
     * @param  Collection<int, ProjectTask>  $children
     */
    private function unitsCompatible(Collection $children): bool
    {
        $unitIds = $children->map(fn (ProjectTask $t) => $t->unit_id)->filter()->unique()->values();

        return $unitIds->count() === 1 && $children->every(fn (ProjectTask $t) => $t->unit_id !== null);
    }

    /**
     * @param  Collection<int, ProjectTask>  $children
     */
    private function averagePercent(Collection $children): string
    {
        if ($children->isEmpty()) {
            return '0.0000';
        }

        $sum = Decimal::zero();
        foreach ($children as $child) {
            $sum = $sum->plus($this->childPercent($child));
        }

        return $sum->dividedBy($children->count())->round(Decimal::PERCENT_SCALE)->toString();
    }

    private function childPercent(ProjectTask $child): Decimal
    {
        if ($child->status === TaskStatus::Completed) {
            return Decimal::of('100');
        }

        $planned = Decimal::of($child->planned_qty ?? '0');
        if ($planned->isPositive()) {
            $pct = Decimal::of($child->completed_qty ?? '0')->times(100)->dividedBy($planned);
            if ($pct->greaterThan(100)) {
                return Decimal::of('100');
            }

            return $pct;
        }

        return Decimal::of($child->progress_percent ?? '0');
    }

    /**
     * @param  Collection<int, ProjectTask>  $children
     */
    private function deriveStatus(Collection $children): TaskStatus
    {
        if ($children->every(fn (ProjectTask $t) => Decimal::of($t->completed_qty ?? '0')->isZero()
            && $t->status === TaskStatus::NotStarted)) {
            return TaskStatus::NotStarted;
        }

        if ($children->every(fn (ProjectTask $t) => $t->status === TaskStatus::Completed || $this->childPercent($t)->greaterThanOrEqual(100))) {
            return TaskStatus::Completed;
        }

        if ($children->every(fn (ProjectTask $t) => $t->status === TaskStatus::OnHold)) {
            return TaskStatus::OnHold;
        }

        return TaskStatus::InProgress;
    }

    /**
     * @param  Collection<int, ProjectTask>  $children
     * @return list<array{id:int,name:string,progress_percent:string,status:string,unit_id:?int}>
     */
    private function childRows(Collection $children): array
    {
        return $children->map(fn (ProjectTask $t) => [
            'id' => (int) $t->id,
            'name' => $t->name,
            'progress_percent' => $this->childPercent($t)->round(Decimal::PERCENT_SCALE)->toString(),
            'status' => ($t->status instanceof TaskStatus ? $t->status : TaskStatus::NotStarted)->value,
            'unit_id' => $t->unit_id ? (int) $t->unit_id : null,
        ])->values()->all();
    }

    /**
     * @param  list<array{id:int,name:string,progress_percent:string,status:string,unit_id:?int}>  $children
     * @return array<string, mixed>
     */
    private function payload(
        Decimal $percent,
        TaskStatus $status,
        string $aggregation,
        ?string $planned,
        ?string $completed,
        ?int $unitId,
        array $children,
    ): array {
        $balance = ($planned !== null && $completed !== null)
            ? Decimal::of($planned)->minus($completed)->toQuantity()
            : null;

        return [
            'progress_percent' => $percent->round(Decimal::PERCENT_SCALE)->toString(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'aggregation' => $aggregation,
            'planned_qty' => $planned,
            'completed_qty' => $completed,
            'balance_qty' => $balance,
            'unit_id' => $unitId,
            'children' => $children,
        ];
    }
}
