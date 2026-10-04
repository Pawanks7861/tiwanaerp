<?php

namespace App\Reports\Definitions;

use App\Enums\Equipment\EquipmentOwnership;
use App\Queries\Reports\ResourceCostQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EquipmentReport extends ReportDefinition
{
    public function __construct(private readonly ResourceCostQuery $resources) {}

    public function key(): string
    {
        return 'equipment-usage';
    }

    public function title(): string
    {
        return 'Equipment Cost & Usage';
    }

    public function category(): string
    {
        return 'equipment';
    }

    public function description(): string
    {
        return 'Posted usage hours, idle time and utilisation per machine, with ledger cost, fuel and completed repairs.';
    }

    public function permissions(): array
    {
        return ['equipment.view'];
    }

    public function filters(): array
    {
        return ['search'];
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return DB::query()->fromSub($this->query($ctx), 'x')->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $base = $this->query($ctx);
        $sum = DB::query()->fromSub(clone $base, 't')->selectRaw('COUNT(*) as cnt, SUM(logs) as logs, SUM(working_hours) as working_hours, SUM(idle_hours) as idle_hours,
            SUM(cost) as cost, SUM(fuel_qty) as fuel_qty, SUM(fuel_cost) as fuel_cost, SUM(repair_cost) as repair_cost')->first();

        $query = DB::query()->fromSub($base, 'e')->orderBy('project_code')->orderBy('equipment_code')->orderBy('equipment_id');
        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => [
            'equipment' => "{$r->equipment_code} — {$r->equipment}",
            'url' => route('projects.equipment-usage.index', [$r->project_id]),
            'project' => $r->project_code,
            'type' => $r->type,
            'ownership' => self::enumLabel(EquipmentOwnership::class, $r->ownership),
            'logs' => (int) $r->logs,
            'working_hours' => Num::qty($r->working_hours),
            'idle_hours' => Num::qty($r->idle_hours),
            'utilization' => Num::percent($r->working_hours, Num::dec($r->working_hours)->plus(Num::dec($r->idle_hours))),
            'cost' => Num::money($r->cost),
            'fuel_qty' => Num::qty($r->fuel_qty),
            'fuel_cost' => Num::money($r->fuel_cost),
            'repair_cost' => Num::money($r->repair_cost),
        ]);

        $working = Num::dec($sum->working_hours ?? null);

        return new ReportResult(
            columns: [
                self::col('equipment', 'Equipment', 'text', ['link' => true]),
                self::col('project', 'Project', 'code'),
                self::col('type', 'Type', 'text', ['mobile' => false]),
                self::col('ownership', 'Ownership', 'status', ['mobile' => false]),
                self::col('logs', 'Logs', 'number', ['mobile' => false]),
                self::col('working_hours', 'Working hrs', 'qty'),
                self::col('idle_hours', 'Idle hrs', 'qty', ['mobile' => false]),
                self::col('utilization', 'Utilisation', 'percent'),
                self::money('cost', 'Usage cost (ledger)'),
                self::col('fuel_qty', 'Fuel added', 'qty', ['mobile' => false]),
                self::money('fuel_cost', 'Fuel cost', ['mobile' => false]),
                self::money('repair_cost', 'Repairs', ['mobile' => false]),
            ],
            rows: $rows,
            totals: ['equipment' => 'Total ('.(int) ($sum->cnt ?? 0).')', 'logs' => (int) ($sum->logs ?? 0), 'working_hours' => $working->toQuantity(),
                'idle_hours' => Num::qty($sum->idle_hours ?? null), 'utilization' => Num::percent($working, $working->plus(Num::dec($sum->idle_hours ?? null))),
                'cost' => Num::money($sum->cost ?? null), 'fuel_qty' => Num::qty($sum->fuel_qty ?? null), 'fuel_cost' => Num::money($sum->fuel_cost ?? null),
                'repair_cost' => Num::money($sum->repair_cost ?? null)],
            cards: [
                ['key' => 'hours', 'label' => 'Working hours', 'value' => $working->toQuantity(), 'type' => 'qty'],
                ['key' => 'cost', 'label' => 'Equipment cost (usage)', 'value' => Num::money($sum->cost ?? null), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'fuel', 'label' => 'Fuel cost', 'value' => Num::money($sum->fuel_cost ?? null), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'repairs', 'label' => 'Repairs', 'value' => Num::money($sum->repair_cost ?? null), 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: ['Hours from posted usage logs; usage cost from the cost ledger (net of reversals). Fuel and repair costs are informational — they reach the ledger only through expenses.'],
            pagination: $pagination,
        );
    }

    private function query(ReportContext $ctx): Builder
    {
        return $this->resources->equipment($ctx->companyId(), $ctx->projectIds, (string) $ctx->from, $ctx->to, ['search' => $ctx->filter('search')]);
    }
}
