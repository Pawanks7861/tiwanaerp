<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Jobs\GenerateReportExport;
use App\Models\Projects\Project;
use App\Models\Reports\ReportExport;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportExporter;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Reports: authorize, validate filters (ReportRunner), run the definition's query class and
 * return the Inertia page or an export. Financial / valuation fields are stripped server-side.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ReportExporter $exporter,
        private readonly CurrentCompany $company,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('reports.view'), 403);

        return Inertia::render('Reports/Index', [
            'project' => null,
            'groups' => $this->groups($request, 'global', null),
        ]);
    }

    public function projectIndex(Request $request, Project $project): Response
    {
        abort_unless($request->user()->can('reports.view'), 403);

        return Inertia::render('Reports/Index', [
            'project' => ProjectHeader::for($project),
            'groups' => $this->groups($request, 'project', $project),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        return $this->render($request, $this->resolve($request, $report, 'global'), null);
    }

    public function project(Request $request, Project $project, string $report): Response
    {
        return $this->render($request, $this->resolve($request, $report, 'project'), $project);
    }

    public function export(Request $request, string $report): HttpResponse|RedirectResponse
    {
        return $this->download($request, $this->resolve($request, $report, 'global'), null);
    }

    public function projectExport(Request $request, Project $project, string $report): HttpResponse|RedirectResponse
    {
        return $this->download($request, $this->resolve($request, $report, 'project'), $project);
    }

    private function render(Request $request, ReportDefinition $report, ?Project $project): Response
    {
        $ctx = $this->runner->context($report, $request->user(), $this->company->require(), $project, $request->query());
        $result = $report->build($ctx)->restrict($ctx->financial, $ctx->valuation);

        return Inertia::render('Reports/Show', [
            'project' => $project ? ProjectHeader::for($project) : null,
            'report' => $report->summary() + ['filters' => $report->filters()],
            'result' => $result->toArray(),
            'filters' => $this->filterState($report, $ctx),
            'options' => $this->runner->options($report, $ctx),
            'period' => [
                'label' => $ctx->periodLabel($report->periodMode()),
                'fy' => $ctx->fy,
                'from' => $ctx->from,
                'to' => $ctx->to,
                'as_of' => $ctx->asOf(),
                'today' => $ctx->today,
            ],
            'can' => [
                'export' => $request->user()->can('reports.export'),
                'financial' => $ctx->financial,
                'valuation' => $ctx->valuation,
            ],
            'exports' => $this->recentExports($request, $report, $project),
            'urls' => [
                'self' => $project ? route('reports.project', [$project->id, $report->key()]) : route('reports.show', $report->key()),
                'export' => $project ? route('reports.project-export', [$project->id, $report->key()]) : route('reports.export', $report->key()),
                'index' => $project ? route('reports.project-index', $project->id) : route('reports.index'),
            ],
        ]);
    }

    private function download(Request $request, ReportDefinition $report, ?Project $project): HttpResponse|RedirectResponse
    {
        abort_unless($request->user()->can('reports.export'), 403);
        $request->validate(['format' => ['required', 'in:pdf,xlsx']]);
        $format = (string) $request->query('format');
        $company = $this->company->require();
        $ctx = $this->runner->context($report, $request->user(), $company, $project, Arr::except($request->query(), ['format', 'page']));

        if ($report->estimateRows($ctx) > (int) config('reports.sync_export_max_rows')) {
            $export = new ReportExport;
            $export->forceFill([
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
                'project_id' => $project?->id,
                'report_key' => $report->key(),
                'format' => $format,
                'filters' => ['input' => Arr::except($request->query(), ['format', 'page'])],
                'status' => ReportExport::QUEUED,
            ])->save();
            GenerateReportExport::dispatch($export->id);

            return back()->with('success', 'This export is large, so it is being prepared in the background. You will be notified when it is ready to download.');
        }

        $file = $this->exporter->render($report, $ctx, $format);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function resolve(Request $request, string $key, string $scope): ReportDefinition
    {
        $report = $this->registry->find($key);
        abort_if($report === null || ! in_array($scope, $report->scopes(), true), 404);
        abort_unless($report->allows($request->user()), 403);

        return $report;
    }

    /**
     * @return list<array{key: string, label: string, reports: list<array<string, mixed>>}>
     */
    private function groups(Request $request, string $scope, ?Project $project): array
    {
        $groups = [];
        foreach ($this->registry->available($request->user(), $scope) as $report) {
            $summary = $report->summary();
            $summary['url'] = $project ? route('reports.project', [$project->id, $report->key()]) : route('reports.show', $report->key());
            $groups[$summary['category']] ??= ['key' => $summary['category'], 'label' => $summary['category_label'], 'reports' => []];
            $groups[$summary['category']]['reports'][] = $summary;
        }

        $order = array_keys(ReportDefinition::CATEGORIES);
        uksort($groups, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        return array_values($groups);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterState(ReportDefinition $report, ReportContext $ctx): array
    {
        return $ctx->filters + [
            'fy' => $ctx->fy['value'],
            'from' => $report->periodMode() === 'range' ? $ctx->from : null,
            'to' => $report->periodMode() === 'current' ? null : $ctx->to,
            'per_page' => $ctx->perPage,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentExports(Request $request, ReportDefinition $report, ?Project $project): array
    {
        return ReportExport::query()->where('user_id', $request->user()->id)->where('report_key', $report->key())
            ->where('project_id', $project?->id)->latest('id')->limit(5)->get()
            ->map(fn (ReportExport $e) => [
                'id' => $e->id,
                'format' => $e->format,
                'status' => $e->status,
                'file_name' => $e->file_name,
                'rows' => $e->row_count,
                'error' => $e->error,
                'created_at' => $e->created_at?->toIso8601String(),
                'download_url' => $e->status === ReportExport::READY ? route('reports.exports.download', $e->id) : null,
            ])->all();
    }
}
