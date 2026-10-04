<?php

namespace App\Reports;

use App\Models\User;
use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One report: identity, who may run it, which filters / sorts it accepts (whitelists) and how
 * it builds its result from a query class. Definitions never read the request directly.
 */
abstract class ReportDefinition
{
    public const CATEGORIES = [
        'executive' => 'Executive', 'project' => 'Project', 'cost' => 'Cost', 'procurement' => 'Procurement',
        'inventory' => 'Inventory', 'progress' => 'Progress', 'finance' => 'Finance', 'labour' => 'Labour',
        'subcontract' => 'Subcontract', 'equipment' => 'Equipment', 'quality' => 'Quality', 'crm' => 'CRM',
    ];

    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function category(): string;

    abstract public function description(): string;

    abstract public function build(ReportContext $ctx, bool $paginate = true): ReportResult;

    /** Module permissions required on top of reports.view. */
    public function permissions(): array
    {
        return [];
    }

    /** Reports made only of money (costs, receivables, payables, cash) need reports.view_financial. */
    public function financialOnly(): bool
    {
        return false;
    }

    /** 'global' (company, filtered to visible projects) and/or 'project' (one project). */
    public function scopes(): array
    {
        return ['global', 'project'];
    }

    /** 'range' (from – to) or 'asof' (balances at the period end). */
    public function periodMode(): string
    {
        return 'range';
    }

    /** Filter keys this report accepts besides fy / from / to / project_id. */
    public function filters(): array
    {
        return [];
    }

    /** @return array<string, string> value => label for the 'status' filter */
    public function statusOptions(): array
    {
        return [];
    }

    /** @return array<string, string> value => label for the 'view' switch */
    public function viewOptions(): array
    {
        return [];
    }

    /** @return array<string, array<string, string>> extra select filters (e.g. type, mode, txn_type, group) => value => label */
    public function choiceOptions(): array
    {
        return [];
    }

    /** @return array<string, string> sort key => SQL column (whitelist; never user SQL) */
    public function sorts(): array
    {
        return [];
    }

    /** @return array<string, string> sort key => 'financial' | 'valuation' (not offered without the permission) */
    public function sortSensitivity(): array
    {
        return [];
    }

    public function defaultSort(): ?string
    {
        return null;
    }

    public function defaultDirection(): string
    {
        return 'asc';
    }

    /** @return array<string, string> sort key => label */
    public function sortLabels(): array
    {
        return [];
    }

    public function allows(User $user): bool
    {
        if (! $user->can('reports.view') || ($this->financialOnly() && ! $user->can('reports.view_financial'))) {
            return false;
        }
        foreach ($this->permissions() as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /** Rows the export would produce; above config('reports.sync_export_max_rows') it is queued. */
    public function estimateRows(ReportContext $ctx): int
    {
        return 0;
    }

    /**
     * @return array{key: string, title: string, category: string, category_label: string, description: string, financial: bool, scopes: list<string>, period: string}
     */
    public function summary(): array
    {
        return [
            'key' => $this->key(),
            'title' => $this->title(),
            'category' => $this->category(),
            'category_label' => self::CATEGORIES[$this->category()] ?? $this->category(),
            'description' => $this->description(),
            'financial' => $this->financialOnly(),
            'scopes' => $this->scopes(),
            'period' => $this->periodMode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function col(string $key, string $label, string $type = 'text', array $extra = []): array
    {
        $align = in_array($type, ['money', 'qty', 'number', 'percent', 'rate'], true) ? ['align' => 'right'] : [];

        return ['key' => $key, 'label' => $label, 'type' => $type] + $align + $extra;
    }

    /** A money column, hidden without reports.view_financial. */
    protected static function money(string $key, string $label, array $extra = []): array
    {
        return self::col($key, $label, 'money', ['sensitive' => 'financial'] + $extra);
    }

    protected static function day(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : substr((string) $value, 0, 10);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    protected static function enumLabel(string $enum, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $case = $enum::tryFrom($value);

        return $case !== null && method_exists($case, 'label') ? $case->label() : ucwords(str_replace('_', ' ', $value));
    }

    /**
     * Projects in scope, keyed by id.
     *
     * @return Collection<int, object>
     */
    protected static function projectsInScope(ReportContext $ctx): Collection
    {
        return DB::table('projects')->where('company_id', $ctx->companyId())
            ->whereIn('id', $ctx->projectIds ?: [0])->whereNull('deleted_at')->orderBy('code')
            ->get(['id', 'code', 'name', 'status', 'contract_value', 'client_id', 'project_manager_id'])
            ->keyBy('id');
    }

    protected function applySort(Builder $query, ReportContext $ctx, string $tiebreak): Builder
    {
        $sorts = $this->sorts();
        $key = $ctx->filter('sort', $this->defaultSort());
        if ($key !== null && isset($sorts[$key])) {
            $query->orderBy($sorts[$key], $ctx->filter('dir', $this->defaultDirection()) === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy($tiebreak);
    }

    /**
     * Rows for the screen (one page, with paginator links) or the export (all rows).
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null}
     */
    protected function rows(Builder $query, ReportContext $ctx, bool $paginate, callable $map): array
    {
        if (! $paginate) {
            return [$query->get()->map($map)->values()->all(), null];
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($ctx->perPage, ['*'], 'page', $ctx->page)->withQueryString();
        $meta = $page->toArray();
        unset($meta['data']);

        return [collect($page->items())->map($map)->values()->all(), $meta];
    }
}
