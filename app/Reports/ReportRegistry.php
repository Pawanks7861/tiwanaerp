<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Definitions\AdvanceRegisterReport;
use App\Reports\Definitions\BoqCostBuildUpReport;
use App\Reports\Definitions\BoqHealthRiskReport;
use App\Reports\Definitions\BoqItemAnalysisReport;
use App\Reports\Definitions\BoqMeasurementSummaryReport;
use App\Reports\Definitions\BoqProcurementGapReport;
use App\Reports\Definitions\BoqProgressBillingReport;
use App\Reports\Definitions\BoqProgressReport;
use App\Reports\Definitions\BudgetVsActualReport;
use App\Reports\Definitions\CashFlowReport;
use App\Reports\Definitions\CostByHeadReport;
use App\Reports\Definitions\CrmFunnelReport;
use App\Reports\Definitions\DelayedTasksReport;
use App\Reports\Definitions\ElectricityBillVarianceReport;
use App\Reports\Definitions\ElectricityComponentChargeReport;
use App\Reports\Definitions\ElectricityConsumptionTrendReport;
use App\Reports\Definitions\ElectricityDemandUtilizationReport;
use App\Reports\Definitions\ElectricityMonthlyComparisonReport;
use App\Reports\Definitions\ElectricityPenaltyRebateReport;
use App\Reports\Definitions\ElectricityPfAnalysisReport;
use App\Reports\Definitions\ElectricityRebateSubsidyReport;
use App\Reports\Definitions\ElectricitySiteCostReport;
use App\Reports\Definitions\ElectricityTariffComparisonReport;
use App\Reports\Definitions\EquipmentReport;
use App\Reports\Definitions\EstimatedVsActualCostReport;
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
use App\Reports\Definitions\RetentionRegisterReport;
use App\Reports\Definitions\RgpOutstandingReport;
use App\Reports\Definitions\ScrapBalanceReport;
use App\Reports\Definitions\ScrapCategorySummaryReport;
use App\Reports\Definitions\ScrapDispatchRegisterReport;
use App\Reports\Definitions\ScrapGatePassMovementReport;
use App\Reports\Definitions\ScrapGeneratedVsDispatchedReport;
use App\Reports\Definitions\ScrapGenerationRegisterReport;
use App\Reports\Definitions\ScrapProjectSummaryReport;
use App\Reports\Definitions\SubcontractReport;
use App\Reports\Definitions\WorkflowAgeingReport;

/**
 * The report catalogue (architecture P, Phase 9 — the 17 standard reports plus the CRM funnel).
 */
final class ReportRegistry
{
    /** @var list<class-string<ReportDefinition>> */
    public const REPORTS = [
        ProjectCostSummaryReport::class,
        BudgetVsActualReport::class,
        EstimatedVsActualCostReport::class,
        BoqCostBuildUpReport::class,
        BoqItemAnalysisReport::class,
        BoqMeasurementSummaryReport::class,
        BoqProgressBillingReport::class,
        BoqProcurementGapReport::class,
        BoqHealthRiskReport::class,
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
        RgpOutstandingReport::class,
        AdvanceRegisterReport::class,
        RetentionRegisterReport::class,
        WorkflowAgeingReport::class,
        ScrapBalanceReport::class,
        ScrapGenerationRegisterReport::class,
        ScrapDispatchRegisterReport::class,
        ScrapCategorySummaryReport::class,
        ScrapProjectSummaryReport::class,
        ScrapGatePassMovementReport::class,
        ScrapGeneratedVsDispatchedReport::class,
        ElectricityMonthlyComparisonReport::class,
        ElectricitySiteCostReport::class,
        ElectricityTariffComparisonReport::class,
        ElectricityDemandUtilizationReport::class,
        ElectricityPfAnalysisReport::class,
        ElectricityComponentChargeReport::class,
        ElectricityPenaltyRebateReport::class,
        ElectricityRebateSubsidyReport::class,
        ElectricityBillVarianceReport::class,
        ElectricityConsumptionTrendReport::class,
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
