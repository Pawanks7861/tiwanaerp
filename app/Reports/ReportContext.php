<?php

namespace App\Reports;

use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\User;

/**
 * Everything a report needs to run, resolved server-side: the tenant, the projects the user may
 * see (narrowed by the project route or filter), validated filters and the period.
 */
final class ReportContext
{
    /**
     * @param  list<int>  $projectIds  effective project scope (never from the client unvalidated)
     * @param  array<string, mixed>  $filters  validated, whitelisted filters only
     * @param  array{value: string, label: string, start: string, end: string}  $fy
     */
    public function __construct(
        public readonly User $user,
        public readonly Company $company,
        public readonly ?Project $project,
        public readonly array $projectIds,
        public readonly array $filters,
        public readonly array $fy,
        public readonly ?string $from,
        public readonly string $to,
        public readonly string $today,
        public readonly bool $financial,
        public readonly bool $valuation,
        public readonly int $perPage = 50,
        public readonly int $page = 1,
    ) {}

    public function filter(string $key, mixed $default = null): mixed
    {
        $value = $this->filters[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** As-of date for balance reports: the period end, never later than today. */
    public function asOf(): string
    {
        return min($this->to, $this->today);
    }

    public function companyId(): int
    {
        return (int) $this->company->id;
    }

    /** "FY 2026-27" or "01 Apr 2026 – 30 Jun 2026" for headers. */
    public function periodLabel(string $mode): string
    {
        $format = fn (string $d) => date('d M Y', strtotime($d));
        if ($mode === 'current') {
            return 'As of '.$format($this->today);
        }
        if ($mode === 'asof') {
            return 'As of '.$format($this->asOf());
        }
        if ($this->from === $this->fy['start'] && $this->to === $this->fy['end']) {
            return $this->fy['label'];
        }

        return $format((string) $this->from).' – '.$format($this->to);
    }
}
