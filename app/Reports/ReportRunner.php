<?php

namespace App\Reports;

use App\Enums\CostHead;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\User;
use App\Queries\Reports\StockQuery;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Turns request input into a ReportContext: validates every filter against the report's
 * whitelist (ids must belong to the company and, for projects, be visible to the user),
 * resolves the financial year / period in the company's time zone and the project scope.
 */
final class ReportRunner
{
    /** Filter key => request parameter. */
    public const PARAMS = [
        'client' => 'client_id', 'vendor' => 'vendor_id', 'subcontractor' => 'subcontractor_id', 'warehouse' => 'warehouse_id',
        'category' => 'category_id', 'material' => 'material_id', 'assignee' => 'assignee_id',
    ];

    public function __construct(private readonly StockQuery $stock) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function context(ReportDefinition $report, User $user, Company $company, ?Project $project, array $input): ReportContext
    {
        $visible = $project !== null ? [(int) $project->id] : $this->visibleProjectIds($user);
        $data = Validator::make($input, $this->rules($report, $user, $company, $visible, $project, $input))->validate();

        $fy = filled($data['fy'] ?? null) ? ReportPeriod::find($company, $data['fy']) : ReportPeriod::current($company);
        $today = ReportPeriod::today($company);
        [$from, $to] = match ($report->periodMode()) {
            'current' => [null, $today],
            'asof' => [null, $data['to'] ?? $fy['end']],
            default => [$data['from'] ?? $fy['start'], $data['to'] ?? $fy['end']],
        };
        if ($from !== null && $from > $to) {
            $to = $from;
        }

        $projectIds = $project !== null ? [(int) $project->id]
            : (filled($data['project_id'] ?? null) ? [(int) $data['project_id']] : $visible);

        $filters = array_filter(
            array_intersect_key($data, array_flip($this->allowedKeys($report, $project))),
            fn ($v) => $v !== null && $v !== '',
        );

        return new ReportContext(
            user: $user,
            company: $company,
            project: $project,
            projectIds: $projectIds,
            filters: $filters,
            fy: $fy,
            from: $from,
            to: $to,
            today: $today,
            financial: $user->can('reports.view_financial'),
            valuation: $user->can('inventory.view_valuation'),
            perPage: (int) ($data['per_page'] ?? 50),
            page: (int) ($data['page'] ?? 1),
        );
    }

    /**
     * @return list<int>
     */
    public function visibleProjectIds(User $user): array
    {
        return Project::query()->visibleTo($user)->orderBy('code')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Select options for the report's filters (only values the user may see).
     *
     * @return array<string, mixed>
     */
    public function options(ReportDefinition $report, ReportContext $ctx): array
    {
        $cid = $ctx->companyId();
        $visible = $ctx->project !== null ? [(int) $ctx->project->id] : $this->visibleProjectIds($ctx->user);
        $pairs = fn ($rows) => collect($rows)->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->label])->values()->all();
        $kv = fn (array $map) => collect($map)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all();

        $options = [
            'fy' => array_map(fn ($o) => ['value' => $o['value'], 'label' => $o['label']], ReportPeriod::options($ctx->company)),
            'sorts' => $kv(array_diff_key($report->sortLabels(), array_filter($report->sortSensitivity(),
                fn ($s) => ($s === 'financial' && ! $ctx->financial) || ($s === 'valuation' && ! $ctx->valuation)))),
            'per_page' => config('reports.per_page_options'),
        ];
        if ($ctx->project === null) {
            $options['project'] = $pairs(DB::table('projects')->where('company_id', $cid)->whereIn('id', $visible ?: [0])->whereNull('deleted_at')
                ->orderBy('code')->selectRaw('id, '.Sql::concat('code', "' — '", 'name').' as label')->get());
        }

        foreach ($report->filters() as $filter) {
            $options[$filter] = match ($filter) {
                'client' => $pairs(DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')
                    ->when(! $ctx->user->can('projects.view_all'), fn ($q) => $q->whereIn('id', DB::table('projects')->whereIn('id', $visible ?: [0])->select('client_id')))
                    ->orderBy('company_name')->get(['id', 'company_name as label'])),
                'vendor' => $pairs(DB::table('vendors')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name as label'])),
                'subcontractor' => $pairs(DB::table('subcontractors')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name as label'])),
                'warehouse' => $pairs(DB::table('warehouses')->whereIn('id', $this->stock->warehouseIds($cid, $visible, $ctx->project === null) ?: [0])
                    ->orderBy('code')->selectRaw('id, '.Sql::concat('code', "' — '", 'name').' as label')->get()),
                'category' => $pairs(DB::table('material_categories')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name as label'])),
                'material' => $pairs(DB::table('materials')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->limit(1000)
                    ->selectRaw('id, '.Sql::concat('code', "' — '", 'name').' as label')->get()),
                'assignee' => $pairs($this->assignees($cid, $ctx->project)),
                'head' => array_map(fn ($o) => ['value' => $o['value'], 'label' => $o['label']], CostHead::options()),
                'status' => $kv($report->statusOptions()),
                'view' => $kv($report->viewOptions()),
                default => isset($report->choiceOptions()[$filter]) ? $kv($report->choiceOptions()[$filter]) : null,
            };
        }

        return array_filter($options, fn ($o) => $o !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(ReportDefinition $report, User $user, Company $company, array $visible, ?Project $project, array $input): array
    {
        $cid = (int) $company->id;
        $sortable = array_keys(array_diff_key($report->sorts(), array_filter($report->sortSensitivity(),
            fn ($s) => ($s === 'financial' && ! $user->can('reports.view_financial')) || ($s === 'valuation' && ! $user->can('inventory.view_valuation')))));

        $rules = [
            'fy' => ['nullable', 'string', Rule::in(array_column(ReportPeriod::options($company), 'value'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_merge(['nullable', 'date_format:Y-m-d'], filled($input['from'] ?? null) ? ['after_or_equal:from'] : []),
            'sort' => ['nullable', Rule::in($sortable)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(config('reports.per_page_options'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['pdf', 'xlsx'])],
        ];
        if ($project === null) {
            $rules['project_id'] = ['nullable', 'integer', Rule::in($visible)];
        }

        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $cid);
        foreach ($report->filters() as $filter) {
            $param = self::PARAMS[$filter] ?? $filter;
            $rules[$param] = match ($filter) {
                'client' => ['nullable', 'integer', $exists('clients')],
                'vendor' => ['nullable', 'integer', $exists('vendors')],
                'subcontractor' => ['nullable', 'integer', $exists('subcontractors')],
                'warehouse' => ['nullable', 'integer', $exists('warehouses')],
                'category' => ['nullable', 'integer', $exists('material_categories')],
                'material' => ['nullable', 'integer', $exists('materials')],
                'assignee' => ['nullable', 'integer', Rule::exists('company_user', 'user_id')->where('company_id', $cid)],
                'head' => ['nullable', Rule::in(CostHead::values())],
                'status' => ['nullable', Rule::in(array_map('strval', array_keys($report->statusOptions())))],
                'view' => ['nullable', Rule::in(array_map('strval', array_keys($report->viewOptions())))],
                'search', 'party' => ['nullable', 'string', 'max:100'],
                default => ['nullable', Rule::in(array_map('strval', array_keys($report->choiceOptions()[$filter] ?? [])))],
            };
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function allowedKeys(ReportDefinition $report, ?Project $project): array
    {
        $keys = ['sort', 'dir'];
        if ($project === null) {
            $keys[] = 'project_id';
        }
        foreach ($report->filters() as $filter) {
            $keys[] = self::PARAMS[$filter] ?? $filter;
        }

        return $keys;
    }

    private function assignees(int $companyId, ?Project $project)
    {
        $query = DB::table('users')->join('company_user as cu', 'cu.user_id', '=', 'users.id')
            ->where('cu.company_id', $companyId)->where('cu.is_active', true)->orderBy('users.name');
        if ($project !== null) {
            $query->whereIn('users.id', DB::table('project_users')->where('project_id', $project->id)->select('user_id'));
        }

        return $query->get(['users.id', 'users.name as label']);
    }
}
