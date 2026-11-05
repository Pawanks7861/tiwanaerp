<?php

namespace App\Reports\Definitions;

use App\Enums\Boq\MeasurementSheetStatus;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Boq\BoqIntelligenceService;

final class BoqMeasurementSummaryReport extends ReportDefinition
{
    public function __construct(private readonly BoqIntelligenceService $intel) {}

    public function key(): string
    {
        return 'boq-measurement-summary';
    }

    public function title(): string
    {
        return 'BOQ Measurement Summary';
    }

    public function category(): string
    {
        return 'project';
    }

    public function description(): string
    {
        return 'Measurement sheet lines linked to BOQ items (Stage 1 calculator). No duplicated measurement storage.';
    }

    public function permissions(): array
    {
        return ['boq.view'];
    }

    public function scopes(): array
    {
        return ['project'];
    }

    public function periodMode(): string
    {
        return 'current';
    }

    public function filters(): array
    {
        return ['status'];
    }

    public function statusOptions(): array
    {
        return collect(MeasurementSheetStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        abort_unless($ctx->project !== null, 404);
        $rows = $this->intel->measurementSummary($ctx->project, [
            'status' => $ctx->filter('status'),
        ]);

        $columns = [
            self::col('item', 'BOQ Item'),
            self::col('sheet', 'Sheet', 'text', ['mobile' => false]),
            self::col('location', 'Location', 'text', ['mobile' => false]),
            self::col('formula', 'Formula', 'text', ['mobile' => false]),
            self::col('calculated_qty', 'Calculated Qty', 'qty'),
            self::col('drawing', 'Drawing', 'text', ['mobile' => false]),
            self::col('status', 'Status', 'status'),
            self::col('approved_by', 'Approved by', 'text', ['mobile' => false]),
            self::col('approved_at', 'Approved date', 'text', ['mobile' => false]),
        ];

        return new ReportResult(columns: $columns, rows: $rows, notes: ['Uses Stage 1 MeasurementCalculator totals stored on lines/sheets.']);
    }
}
