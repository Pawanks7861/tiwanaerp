<?php

namespace App\Services\SiteExecution;

use App\Enums\Boq\BoqStatus;
use App\Enums\Labour\AttendanceApproval;
use App\Enums\Labour\AttendanceStatus;
use App\Models\Boq\BoqItem;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Inventory\StockTransaction;
use App\Models\Labour\LabourAttendance;
use App\Models\Planning\ProgressEntry;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Services\Planning\TaskProgressService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\Schema;

/**
 * Direct DPR prefill from Planning/WBS + optional Labour Attendance / Equipment Usage / Inventory.
 * Does NOT require Site Diary. Does NOT create attendance, usage, or stock transactions.
 */
class DprPrefillService
{
    public function __construct(private readonly DprAggregationService $aggregation) {}

    /**
     * @return array{
     *   diary_ids: list<int>,
     *   source: string,
     *   weather: ?string,
     *   site_issues: ?string,
     *   items: list<array<string, mixed>>,
     *   labours: list<array<string, mixed>>,
     *   equipment: list<array<string, mixed>>,
     *   materials: list<array<string, mixed>>,
     *   constraints: list<array<string, mixed>>
     * }
     */
    public function forDate(Project $project, string $date, bool $includeCompleted = false): array
    {
        return [
            'diary_ids' => [],
            'source' => 'direct',
            'weather' => null,
            'site_issues' => null,
            'items' => $this->activityLines($project, $includeCompleted),
            'labours' => $this->manpowerFromAttendance($project, $date),
            'equipment' => $this->equipmentFromUsage($project, $date),
            'materials' => $this->materialsFromInventory($project, $date),
            'constraints' => [],
        ];
    }

    /**
     * Direct planning prefill, optionally merging legacy approved Site Diary quantities for the date.
     *
     * @return array<string, mixed>
     */
    public function forDateMergingLegacy(Project $project, string $date, bool $includeCompleted = false): array
    {
        $content = $this->forDate($project, $date, $includeCompleted);
        $legacy = $this->aggregation->aggregate($project, $date);
        if ($legacy['diary_ids'] === []) {
            return $content;
        }

        return $this->mergeLegacy($content, $legacy);
    }

    /**
     * Prefer planning task lines; sum same-day legacy diary executed qty onto matching keys.
     *
     * @param  array<string, mixed>  $direct
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    public function mergeLegacy(array $direct, array $legacy): array
    {
        $index = [];
        foreach ($direct['items'] as $i => $row) {
            $index[$this->itemKey($row['task_id'] ?? null, $row['boq_item_id'] ?? null, $row['unit_id'] ?? null, $row['description'] ?? null)] = $i;
        }

        foreach ($legacy['items'] as $row) {
            $key = $this->itemKey($row['task_id'] ?? null, $row['boq_item_id'] ?? null, $row['unit_id'] ?? null, $row['description'] ?? null);
            if (isset($index[$key])) {
                $i = $index[$key];
                $direct['items'][$i]['executed_qty'] = Decimal::of($direct['items'][$i]['executed_qty'] ?? '0')
                    ->plus($row['executed_qty'] ?? '0')
                    ->toQuantity();
            } else {
                $direct['items'][] = $row;
            }
        }

        if (($direct['labours'] ?? []) === [] && ($legacy['labours'] ?? []) !== []) {
            $direct['labours'] = $legacy['labours'];
        }
        if (($direct['equipment'] ?? []) === [] && ($legacy['equipment'] ?? []) !== []) {
            $direct['equipment'] = $legacy['equipment'];
        }
        if (($direct['materials'] ?? []) === [] && ($legacy['materials'] ?? []) !== []) {
            $direct['materials'] = $legacy['materials'];
        }

        $direct['weather'] = $direct['weather'] ?? $legacy['weather'];
        $direct['site_issues'] = $direct['site_issues'] ?? $legacy['site_issues'];
        $direct['diary_ids'] = $legacy['diary_ids'];
        $direct['source'] = 'legacy_site_diary';

        return $direct;
    }

    private function itemKey(?int $taskId, ?int $boqItemId, ?int $unitId, ?string $description): string
    {
        return $taskId || $boqItemId
            ? "t{$taskId}|b{$boqItemId}|u{$unitId}"
            : 'd'.mb_strtolower(trim((string) $description)).'|u'.$unitId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activityLines(Project $project, bool $includeCompleted = false): array
    {
        $tasks = ProjectTask::query()
            ->where('project_id', $project->id)
            ->with(['unit:id,symbol,name', 'parent:id,name,wbs_code', 'boqItem:id,line_uid,item_code,name,unit_id,quantity'])
            ->orderBy('sort_order')
            ->orderBy('wbs_code')
            ->orderBy('id')
            ->get();

        $currentBoqItems = BoqItem::query()
            ->whereHas('boq', fn ($q) => $q->where('project_id', $project->id)->where('is_current', true)->where('status', BoqStatus::Approved))
            ->get()
            ->keyBy('line_uid');

        $lines = [];
        foreach ($tasks as $task) {
            if ($task->unit_id === null && $task->boq_item_id === null) {
                continue;
            }

            $planned = Decimal::of($task->planned_qty ?? '0');
            $previous = TaskProgressService::sum(ProgressEntry::query()->where('task_id', $task->id));
            $isComplete = $planned->isPositive() && $previous->greaterThanOrEqual($planned);

            if (! $includeCompleted && $isComplete) {
                continue;
            }

            $boq = null;
            if ($task->boqItem?->line_uid) {
                $boq = $currentBoqItems->get($task->boqItem->line_uid) ?? $task->boqItem;
            }

            $unitId = $task->unit_id ?? $boq?->unit_id;
            if ($unitId === null) {
                continue;
            }

            $total = $planned->isPositive()
                ? $planned
                : ($boq ? Decimal::of($boq->quantity ?? '0') : Decimal::zero());

            $hold = Decimal::of($task->hold_quantity ?? '0');
            $lines[] = [
                'task_id' => $task->id,
                'boq_item_id' => $boq?->id, // optional / historical only
                'unit_id' => $unitId,
                'description' => $task->name,
                'activity_name_snapshot' => $task->name,
                'work_area' => $task->parent?->name ?? $task->wbs_code,
                'unit_snapshot' => $task->unit?->symbol ?? $task->unit?->name,
                'planned_start_date' => $task->planned_start?->toDateString(),
                'planned_end_date' => $task->planned_finish?->toDateString(),
                'planned_qty' => $total->isPositive() ? $total->toQuantity() : null,
                'previous_executed_qty' => $previous->toQuantity(),
                'today_target_qty' => null,
                'executed_qty' => '0',
                'hold_quantity' => $hold->toQuantity(),
                'on_hold' => $hold->isPositive(),
            ];
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function manpowerFromAttendance(Project $project, string $date): array
    {
        $rows = LabourAttendance::query()
            ->where('project_id', $project->id)
            ->whereDate('attendance_date', $date)
            ->where('status', '!=', AttendanceStatus::Absent)
            ->where('approval_status', AttendanceApproval::Approved)
            ->with(['labour:id,labour_trade_id,subcontractor_id'])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $att) {
            $tradeId = $att->labour?->labour_trade_id;
            if (! $tradeId) {
                continue;
            }
            $subId = $att->labour?->subcontractor_id;
            $key = $tradeId.'|'.$subId;
            $grouped[$key] ??= [
                'labour_trade_id' => $tradeId,
                'subcontractor_id' => $subId,
                'headcount' => 0,
                'hours' => '0',
                'skilled' => 0,
                'unskilled' => 0,
                'operator_count' => 0,
                'helper_count' => 0,
                'is_informational' => true,
                'source' => 'attendance',
                'remarks' => null,
            ];
            $grouped[$key]['headcount']++;
            $hours = Decimal::of($att->working_hours ?? '0')->plus($att->ot_hours ?? '0');
            $grouped[$key]['hours'] = Decimal::of($grouped[$key]['hours'])->plus($hours)->round(2)->toString();
        }

        return array_values($grouped);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function equipmentFromUsage(Project $project, string $date): array
    {
        $logs = EquipmentUsageLog::query()
            ->where('project_id', $project->id)
            ->whereDate('log_date', $date)
            ->with(['assignment.equipment.equipmentType', 'assignment.operator:id,name'])
            ->get();

        return $logs->map(function (EquipmentUsageLog $log) {
            $eq = $log->assignment?->equipment;
            $working = (string) ($log->working_hours ?? '0');
            $idle = (string) ($log->idle_hours ?? '0');

            return [
                'equipment_id' => $eq?->id,
                'equipment_type_id' => $eq?->equipment_type_id,
                'description' => $eq?->name ?? $eq?->code ?? $log->remarks,
                'operator_name' => $log->assignment?->operator?->name,
                'start_time' => $log->start_time,
                'end_time' => $log->end_time,
                'working_hours' => $working,
                'idle_hours' => $idle,
                'duration_hours' => $log->total_duration_hours !== null
                    ? (string) $log->total_duration_hours
                    : Decimal::of($working)->plus($idle)->round(2)->toString(),
                'status' => $log->isPosted() ? 'posted' : 'draft',
                'remarks' => $log->remarks ?? null,
                'is_informational' => true,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function materialsFromInventory(Project $project, string $date): array
    {
        if (! Schema::hasTable('stock_transactions')) {
            return [];
        }

        $txns = StockTransaction::query()
            ->where('project_id', $project->id)
            ->whereDate('txn_date', '<=', $date)
            ->with('material:id,unit_id')
            ->get();

        if ($txns->isEmpty()) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];
        foreach ($txns as $txn) {
            $unitId = $txn->material?->unit_id;
            if ($unitId === null) {
                continue;
            }
            $key = $txn->material_id.'|'.$unitId;
            $byKey[$key] ??= [
                'material_id' => $txn->material_id,
                'unit_id' => $unitId,
                'opening_qty' => '0',
                'received_qty' => '0',
                'issued_qty' => '0',
                'closing_qty' => '0',
                'quantity' => '0.0001',
                'is_informational' => true,
                'source' => 'inventory',
                'remarks' => null,
            ];

            $in = Decimal::of($txn->qty_in ?? '0');
            $out = Decimal::of($txn->qty_out ?? '0');
            $onDate = $txn->txn_date?->toDateString() === $date;

            if ($onDate) {
                $byKey[$key]['received_qty'] = Decimal::of($byKey[$key]['received_qty'])->plus($in)->toQuantity();
                $byKey[$key]['issued_qty'] = Decimal::of($byKey[$key]['issued_qty'])->plus($out)->toQuantity();
            } else {
                $byKey[$key]['opening_qty'] = Decimal::of($byKey[$key]['opening_qty'])->plus($in)->minus($out)->toQuantity();
            }
        }

        foreach ($byKey as &$row) {
            $closing = Decimal::of($row['opening_qty'])->plus($row['received_qty'])->minus($row['issued_qty']);
            $row['closing_qty'] = $closing->toQuantity();
            $issued = Decimal::of($row['issued_qty']);
            $row['quantity'] = $issued->isPositive() ? $issued->toQuantity() : '0.0001';
        }
        unset($row);

        return array_values($byKey);
    }
}
