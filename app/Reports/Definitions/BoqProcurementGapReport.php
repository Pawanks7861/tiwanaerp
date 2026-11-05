<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Boq\BoqIntelligenceService;

final class BoqProcurementGapReport extends ReportDefinition
{
    public function __construct(private readonly BoqIntelligenceService $intel) {}

    public function key(): string
    {
        return 'boq-procurement-gap';
    }

    public function title(): string
    {
        return 'BOQ Procurement Gap';
    }

    public function category(): string
    {
        return 'procurement';
    }

    public function description(): string
    {
        return 'Material requirement vs MR / PO / GRN / central stock using Stage 4 formulas. No new requirement authority.';
    }

    public function permissions(): array
    {
        return ['boq.view_material_requirements'];
    }

    public function scopes(): array
    {
        return ['project'];
    }

    public function periodMode(): string
    {
        return 'current';
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        abort_unless($ctx->project !== null, 404);
        $data = $this->intel->procurementGap($ctx->project);

        $columns = [
            self::col('material', 'Material', 'text', ['link' => true]),
            self::col('unit', 'Unit', 'text', ['mobile' => false]),
            self::col('source', 'Source', 'text', ['mobile' => false]),
            self::col('required', 'Required', 'qty'),
            self::col('mr', 'MR', 'qty', ['mobile' => false]),
            self::col('po', 'PO', 'qty', ['mobile' => false]),
            self::col('grn', 'GRN', 'qty', ['mobile' => false]),
            self::col('central_stock', 'Central stock', 'qty', ['mobile' => false]),
            self::col('remaining', 'Remaining', 'qty'),
            self::col('status', 'Status', 'status'),
        ];

        $rows = [];
        foreach ($data as $r) {
            $rows[] = [
                ...$r,
                'url' => $r['material_id']
                    ? route('projects.material-requirement.show', [$ctx->project->id, $r['material_id']], absolute: false)
                    : null,
            ];
        }

        return new ReportResult(columns: $columns, rows: $rows);
    }
}
