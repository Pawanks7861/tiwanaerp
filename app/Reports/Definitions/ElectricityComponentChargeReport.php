<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Utilities\ElectricityComparisonService;

final class ElectricityComponentChargeReport extends ReportDefinition
{
    public function __construct(private readonly ElectricityComparisonService $comparison) {}

    public function key(): string
    {
        return 'electricity-component-charges';
    }

    public function title(): string
    {
        return 'Electricity Component-wise Charge Analysis';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return 'Cross-site/month breakdown of tariff bill components that actually exist (no fake blanks).';
    }

    public function permissions(): array
    {
        return ['utilities.electricity.view'];
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function scopes(): array
    {
        return ['global'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $includeCosts = $ctx->user->can('utilities.electricity.view_costs');
        $data = $this->comparison->componentBreakdown($ctx->from, $ctx->to, $includeCosts);
        $columns = [
            self::col('site', 'Site', 'text'),
            self::col('month', 'Month', 'date'),
        ];
        foreach ($data['columns'] as $code) {
            $columns[] = self::col($code, $code, 'money', ['mobile' => false]);
        }

        return new ReportResult(
            columns: $columns,
            rows: $data['rows'],
        );
    }
}
