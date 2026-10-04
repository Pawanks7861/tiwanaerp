<?php

namespace App\Reports;

use App\Exports\ReportSheetExport;
use App\Services\Branding\CompanyBranding;
use App\Support\Format\IndianNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Builds PDF / XLSX files from the same definition, context and permission stripping as the
 * screen, so an export can never contain more than the user could see.
 */
final class ReportExporter
{
    public function __construct(
        private readonly ReportRunner $runner,
        private readonly CompanyBranding $branding,
    ) {}

    /**
     * @return array{title: string, company: string, project: string, period: string, generated_at: string, generated_by: string, filters: list<string>, result: ReportResult, logo: ?string}
     */
    public function document(ReportDefinition $report, ReportContext $ctx): array
    {
        $result = $report->build($ctx, false)->restrict($ctx->financial, $ctx->valuation);
        $timezone = $ctx->company->timezone ?: config('app.timezone');
        [$project, $filters] = $this->describe($report, $ctx);

        return [
            'title' => $report->title(),
            'company' => $ctx->company->legal_name ?: $ctx->company->name,
            'project' => $project,
            'period' => $ctx->periodLabel($report->periodMode()),
            'generated_at' => CarbonImmutable::now($timezone)->format('d M Y, h:i A'),
            'generated_by' => $ctx->user->name,
            'filters' => $filters,
            'result' => $result,
            'logo' => $this->branding->dataUri($ctx->company),
        ];
    }

    /**
     * @return array{content: string, name: string, mime: string, rows: int}
     */
    public function render(ReportDefinition $report, ReportContext $ctx, string $format): array
    {
        $document = $this->document($report, $ctx);
        $name = Str::slug($report->title()).'-'.Str::slug($ctx->project?->code ?? 'company').'-'.CarbonImmutable::now($ctx->company->timezone ?: config('app.timezone'))->format('Ymd-His').'.'.$format;

        if ($format === 'pdf') {
            $content = Pdf::loadView('pdf.report', $document + [
                'cell' => fn (array $column, mixed $value) => self::printable($column['type'] ?? 'text', $value),
            ])->setPaper('a4', 'landscape')->setOption('isFontSubsettingEnabled', true)->output();

            return ['content' => $content, 'name' => $name, 'mime' => 'application/pdf', 'rows' => count($document['result']->rows)];
        }

        return [
            'content' => Excel::raw(new ReportSheetExport($document), ExcelWriter::XLSX),
            'name' => $name,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rows' => count($document['result']->rows),
        ];
    }

    public static function printable(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => '₹ '.IndianNumber::money((string) $value),
            'rate' => '₹ '.IndianNumber::money((string) $value),
            'qty' => IndianNumber::quantity((string) $value),
            'percent' => rtrim(rtrim((string) $value, '0'), '.').'%',
            'date' => date('d M Y', strtotime((string) $value)),
            default => (string) $value,
        };
    }

    /**
     * Project label and human-readable filter list for headers.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function describe(ReportDefinition $report, ReportContext $ctx): array
    {
        $options = $this->runner->options($report, $ctx);
        $label = function (string $optionKey, mixed $value) use ($options) {
            foreach ($options[$optionKey] ?? [] as $option) {
                if ((string) $option['value'] === (string) $value) {
                    return $option['label'];
                }
            }

            return (string) $value;
        };

        $project = $ctx->project !== null ? "{$ctx->project->code} — {$ctx->project->name}"
            : ($ctx->filter('project_id') ? $label('project', $ctx->filter('project_id')) : 'All projects you can see');

        $names = ['client' => 'Client', 'vendor' => 'Vendor', 'subcontractor' => 'Subcontractor', 'warehouse' => 'Store', 'category' => 'Category',
            'material' => 'Material', 'assignee' => 'Assignee', 'head' => 'Cost head', 'status' => 'Status', 'view' => 'View', 'search' => 'Search',
            'party' => 'Party', 'type' => 'Type', 'mode' => 'Mode', 'txn_type' => 'Movement'];
        $filters = [];
        foreach ($report->filters() as $filter) {
            $value = $ctx->filter(ReportRunner::PARAMS[$filter] ?? $filter);
            if ($value === null) {
                continue;
            }
            $filters[] = ($names[$filter] ?? ucfirst($filter)).': '.(in_array($filter, ['search', 'party'], true) ? $value : $label($filter, $value));
        }

        return [$project, $filters];
    }
}
