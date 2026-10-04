<?php

namespace App\Reports\Definitions;

use App\Enums\Planning\TaskStatus;
use App\Queries\Reports\ProgressQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Task progress from the progress ledger as of a date (net of reversals). Overall progress is
 * the average of leaf tasks, as on the progress board. Rows flag tasks whose cached completed
 * quantity disagrees with the ledger.
 */
class ProgressReport extends ReportDefinition
{
    public function __construct(protected readonly ProgressQuery $progress) {}

    public function key(): string
    {
        return 'project-progress';
    }

    public function title(): string
    {
        return 'Project Progress';
    }

    public function category(): string
    {
        return 'progress';
    }

    public function description(): string
    {
        return 'Planned vs completed quantity per task from the progress ledger, with overall progress and status counts.';
    }

    public function permissions(): array
    {
        return ['planning.view'];
    }

    public function periodMode(): string
    {
        return 'asof';
    }

    public function filters(): array
    {
        return ['status', 'assignee', 'search'];
    }

    public function statusOptions(): array
    {
        return array_column(TaskStatus::options(), 'label', 'value');
    }

    public function sorts(): array
    {
        return ['wbs' => 'wbs_code', 'finish' => 'planned_finish', 'name' => 'name'];
    }

    public function sortLabels(): array
    {
        return ['wbs' => 'WBS', 'finish' => 'Planned finish', 'name' => 'Task'];
    }

    public function defaultSort(): ?string
    {
        return 'wbs';
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return $this->query($ctx)->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $asOf = $ctx->asOf();
        $live = $asOf === $ctx->today;
        $query = $this->applySort(DB::query()->fromSub($this->query($ctx), 't'), $ctx, 'id');
        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => $this->row($r, $live));
        $summary = $this->progress->summary($ctx->companyId(), $ctx->projectIds, $asOf);

        $chart = [];
        if ($ctx->project === null && count($summary['per_project']) > 0) {
            $codes = self::projectsInScope($ctx)->pluck('code', 'id');
            $chart[] = [
                'type' => 'bar', 'horizontal' => true, 'title' => 'Progress by project', 'percent' => true,
                'categories' => array_map(fn ($id) => $codes[$id] ?? (string) $id, array_keys($summary['per_project'])),
                'series' => [['name' => 'Progress %', 'data' => array_values($summary['per_project'])]],
            ];
        }

        return new ReportResult(
            columns: $this->columns(),
            rows: $rows,
            cards: [
                ['key' => 'overall', 'label' => 'Overall progress', 'value' => $summary['overall'], 'type' => 'percent', 'hint' => 'Average of leaf tasks'],
                ['key' => 'tasks', 'label' => 'Tasks', 'value' => $summary['tasks'], 'type' => 'number'],
                ['key' => 'completed', 'label' => 'Completed', 'value' => $summary['completed'], 'type' => 'number', 'tone' => 'success'],
                ['key' => 'delayed', 'label' => 'Delayed', 'value' => $summary['delayed'], 'type' => 'number', 'tone' => $summary['delayed'] > 0 ? 'danger' : 'success'],
            ],
            charts: $chart,
            notes: ['Completed quantity = net of the progress ledger up to the as-of date (reversals included). Status and counts are current.'],
            pagination: $pagination,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function columns(): array
    {
        return [
            self::col('task', 'Task', 'text', ['link' => true]),
            self::col('project', 'Project', 'code'),
            self::col('assignee', 'Assignee', 'text', ['mobile' => false]),
            self::col('status', 'Status', 'status'),
            self::col('planned_start', 'Planned start', 'date', ['mobile' => false]),
            self::col('planned_finish', 'Planned finish', 'date'),
            self::col('planned_qty', 'Planned qty', 'qty', ['mobile' => false]),
            self::col('done', 'Completed qty', 'qty', ['mobile' => false]),
            self::col('balance', 'Balance', 'qty', ['mobile' => false]),
            self::col('unit', 'Unit', 'text', ['mobile' => false]),
            self::col('percent', 'Progress', 'percent'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(object $r, bool $live): array
    {
        $planned = Num::dec($r->planned_qty);
        $done = Num::dec($r->done);
        $balance = $planned->minus($done);

        return [
            'task' => trim("{$r->wbs_code} {$r->name}"),
            'url' => route('projects.planning.tasks.index', [$r->project_id]),
            'project' => $r->project_code,
            'assignee' => $r->assignee,
            'status' => self::enumLabel(TaskStatus::class, $r->status),
            'planned_start' => self::day($r->planned_start),
            'planned_finish' => self::day($r->planned_finish),
            'planned_qty' => $planned->isZero() ? null : $planned->toQuantity(),
            'done' => $done->toQuantity(),
            'balance' => $planned->isZero() ? null : ($balance->isNegative() ? '0.0000' : $balance->toQuantity()),
            'unit' => $r->unit,
            'percent' => ProgressQuery::percent($r),
            'delay' => max(0, (int) $r->overdue_days),
            'flag' => $live && ! Num::dec($r->cached_qty)->equals($done->isNegative() ? '0' : $done) ? 'Cached quantity differs from the ledger' : null,
        ];
    }

    protected function query(ReportContext $ctx): Builder
    {
        return $this->progress->tasks($ctx->companyId(), $ctx->projectIds, $ctx->asOf(), [
            'status' => $ctx->filter('status'), 'assignee_id' => $ctx->filter('assignee_id'), 'search' => $ctx->filter('search'),
        ]);
    }
}
