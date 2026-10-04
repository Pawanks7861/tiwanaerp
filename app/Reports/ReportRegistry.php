<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Definitions\BoqProgressReport;
use App\Reports\Definitions\BudgetVsActualReport;
use App\Reports\Definitions\CashFlowReport;
use App\Reports\Definitions\CostByHeadReport;
use App\Reports\Definitions\CrmFunnelReport;
use App\Reports\Definitions\DelayedTasksReport;
use App\Reports\Definitions\EquipmentReport;
use App\Reports\Definitions\LabourCostReport;
use App\Reports\Definitions\MaterialConsumptionReport;
use App\Reports\Definitions\PayablesReport;
use App\Reports\Definitions\ProcurementReport;
use App\Reports\Definitions\ProgressReport;
use App\Reports\Definitions\ProjectCostSummaryReport;
use App\Reports\Definitions\QualityReport;
use App\Reports\Definitions\ReceivablesReport;
use App\Reports\Definitions\StockLedgerReport;
use App\Reports\Definitions\StockSummaryReport;
use App\Reports\Definitions\SubcontractReport;

/**
 * The report catalogue (architecture P, Phase 9 — the 17 standard reports plus the CRM funnel).
 */
final class ReportRegistry
{
    /** @var list<class-string<ReportDefinition>> */
    public const REPORTS = [
        ProjectCostSummaryReport::class,
        BudgetVsActualReport::class,
        CostByHeadReport::class,
        BoqProgressReport::class,
        ProgressReport::class,
        DelayedTasksReport::class,
        MaterialConsumptionReport::class,
        StockSummaryReport::class,
        StockLedgerReport::class,
        ProcurementReport::class,
        PayablesReport::class,
        ReceivablesReport::class,
        CashFlowReport::class,
        LabourCostReport::class,
        SubcontractReport::class,
        EquipmentReport::class,
        QualityReport::class,
        CrmFunnelReport::class,
    ];

    /** @var array<string, ReportDefinition>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, ReportDefinition>
     */
    public function all(): array
    {
        if ($this->definitions === null) {
            $this->definitions = [];
            foreach (self::REPORTS as $class) {
                $definition = app($class);
                $this->definitions[$definition->key()] = $definition;
            }
        }

        return $this->definitions;
    }

    public function find(string $key): ?ReportDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Reports the user may open in a scope ('global' or 'project').
     *
     * @return list<ReportDefinition>
     */
    public function available(User $user, string $scope): array
    {
        return array_values(array_filter($this->all(), fn (ReportDefinition $d) => in_array($scope, $d->scopes(), true) && $d->allows($user)));
    }
}
