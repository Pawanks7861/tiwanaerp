<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Open tasks past their planned finish (or flagged delayed) as of today, worst delay first.
 */
final class DelayedTasksReport extends ProgressReport
{
    public function key(): string
    {
        return 'delayed-tasks';
    }

    public function title(): string
    {
        return 'Delayed Tasks';
    }

    public function description(): string
    {
        return 'Open tasks past their planned finish date or marked delayed, with days late and remaining quantity.';
    }

    public function periodMode(): string
    {
        return 'current';
    }

    public function filters(): array
    {
        return ['assignee', 'search'];
    }

    public function statusOptions(): array
    {
        return [];
    }

    public function sorts(): array
    {
        return ['delay' => 'overdue_days', 'finish' => 'planned_finish', 'wbs' => 'wbs_code'];
    }

    public function sortLabels(): array
    {
        return ['delay' => 'Days late', 'finish' => 'Planned finish', 'wbs' => 'WBS'];
    }

    public function defaultSort(): ?string
    {
        return 'delay';
    }

    public function defaultDirection(): string
    {
        return 'desc';
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $base = $this->query($ctx);
        $count = (clone $base)->count();
        $query = $this->applySort(DB::query()->fromSub($base, 't'), $ctx, 'id');
        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => $this->row($r, false));

        $columns = $this->columns();
        array_splice($columns, 6, 0, [self::col('delay', 'Days late', 'number')]);

        return new ReportResult(
            columns: $columns,
            rows: array_map(fn ($r) => ['flag' => null] + $r, $rows),
            cards: [['key' => 'delayed', 'label' => 'Delayed tasks', 'value' => $count, 'type' => 'number', 'tone' => $count > 0 ? 'danger' : 'success']],
            notes: ['Tasks not completed or on hold whose planned finish is before today, or whose status is delayed.'],
            pagination: $pagination,
        );
    }

    protected function query(ReportContext $ctx): Builder
    {
        return $this->progress->tasks($ctx->companyId(), $ctx->projectIds, $ctx->today, [
            'assignee_id' => $ctx->filter('assignee_id'), 'search' => $ctx->filter('search'), 'delayed' => true,
        ]);
    }
}
