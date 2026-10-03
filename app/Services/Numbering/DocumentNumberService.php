<?php

namespace App\Services\Numbering;

use App\Enums\Numbering\ResetFrequency;
use App\Models\Core\Company;
use App\Models\Core\DocumentNumberFormat;
use App\Models\Projects\Project;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place document numbers are generated (architecture J.2).
 */
class DocumentNumberService
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * Reserve the next number for a document type.
     *
     * Concurrency: the sequence row is locked FOR UPDATE inside a transaction (a savepoint when the
     * caller already has one), so concurrent requests serialise on that row. If the caller's
     * transaction rolls back, the increment rolls back with it, so failed saves do not burn numbers.
     */
    public function next(string $type, ?Project $project = null, ?CarbonInterface $date = null): string
    {
        $company = $this->tenancy->require();
        $date = CarbonImmutable::instance($date ?? now());
        [$pattern, $reset] = $this->formatFor($company, $type);

        $needsProject = str_contains($pattern, '{PROJECT_CODE}');
        if ($needsProject && $project === null) {
            throw new InvalidArgumentException("Document type [{$type}] requires a project.");
        }

        if (! preg_match('/\{SEQ:(\d+)\}/', $pattern)) {
            // Date-keyed numbers (e.g. DPR-PRJ001-20260923) have no counter; the DB unique index guards duplicates.
            return $this->render($pattern, $company, $project, $date, null);
        }

        $scopeKey = $needsProject ? 'project:'.$project->id : 'global';
        $periodKey = $this->periodKey($reset, $company, $date);

        $number = DB::transaction(function () use ($company, $type, $scopeKey, $periodKey) {
            $keys = [
                'company_id' => $company->id,
                'document_type' => $type,
                'scope_key' => $scopeKey,
                'period_key' => $periodKey,
            ];

            DB::table('document_sequences')->insertOrIgnore($keys + [
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('document_sequences')->where($keys)->lockForUpdate()->first();
            $next = $row->last_number + 1;

            DB::table('document_sequences')->where('id', $row->id)->update([
                'last_number' => $next,
                'updated_at' => now(),
            ]);

            return $next;
        });

        return $this->render($pattern, $company, $project, $date, $number);
    }

    /**
     * @return array{0: string, 1: ResetFrequency}
     */
    public function formatFor(Company $company, string $type): array
    {
        $override = DocumentNumberFormat::query()
            ->where('company_id', $company->id)
            ->where('document_type', $type)
            ->first();

        if ($override) {
            return [$override->pattern, $override->reset_frequency];
        }

        $default = config("numbering.formats.{$type}")
            ?? throw new InvalidArgumentException("No number format configured for [{$type}].");

        return [$default['pattern'], ResetFrequency::from($default['reset'])];
    }

    public function financialYearLabel(Company $company, CarbonInterface $date): string
    {
        $startMonth = $company->fy_start_month ?: 4;
        $startYear = $date->month >= $startMonth ? $date->year : $date->year - 1;

        if ($startMonth === 1) {
            return (string) $startYear;
        }

        return sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);
    }

    private function periodKey(ResetFrequency $reset, Company $company, CarbonInterface $date): string
    {
        return match ($reset) {
            ResetFrequency::Never => '',
            ResetFrequency::Yearly => $date->format('Y'),
            ResetFrequency::FinancialYear => $this->financialYearLabel($company, $date),
            ResetFrequency::Monthly => $date->format('Y-m'),
            ResetFrequency::Daily => $date->format('Ymd'),
        };
    }

    private function render(string $pattern, Company $company, ?Project $project, CarbonInterface $date, ?int $sequence): string
    {
        $result = strtr($pattern, [
            '{PROJECT_CODE}' => (string) $project?->code,
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{FY}' => $this->financialYearLabel($company, $date),
        ]);

        $result = preg_replace_callback('/\{DATE:([^}]+)\}/', fn ($m) => $date->format($m[1]), $result);

        return preg_replace_callback(
            '/\{SEQ:(\d+)\}/',
            fn ($m) => str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT),
            $result,
        );
    }
}
