<?php

namespace App\Support\Reports;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard KPI cache (architecture O.5: ~5 minutes per filter set). Keys carry the company, a
 * per-company version, and a hash of every dimension that changes what may be shown (visible
 * project ids, financial / valuation permission, filters), so restricted figures are never served
 * from another user's entry. Writes to costs, stock, progress, billing and payments bump the
 * version after commit, so dashboards do not wait for the TTL to see new figures.
 *
 * Registered as a scoped singleton: the "pending" set lives for one request / job only.
 */
final class DashboardCache
{
    /** @var array<int, true> */
    private array $pending = [];

    /**
     * @param  array<string, mixed>  $dimensions
     */
    public function remember(int $companyId, string $kind, array $dimensions, Closure $callback): mixed
    {
        ksort($dimensions);
        $key = "dashboard:{$companyId}:{$kind}:".$this->version($companyId).':'.md5(json_encode($dimensions));

        return Cache::remember($key, (int) config('reports.dashboard_cache_ttl', 300), $callback);
    }

    public function version(int $companyId): string
    {
        return (string) Cache::get($this->versionKey($companyId), '0');
    }

    public function bump(?int $companyId): void
    {
        if (! $companyId) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            $this->write($companyId);

            return;
        }
        if (isset($this->pending[$companyId])) {
            return;
        }
        $this->pending[$companyId] = true;
        DB::afterCommit(function () use ($companyId) {
            unset($this->pending[$companyId]);
            $this->write($companyId);
        });
    }

    /** A rolled-back transaction drops its after-commit callbacks; let later writes bump again. */
    public function forgetPending(): void
    {
        $this->pending = [];
    }

    private function write(int $companyId): void
    {
        Cache::forever($this->versionKey($companyId), str_replace('.', '', (string) microtime(true)).random_int(100, 999));
    }

    private function versionKey(int $companyId): string
    {
        return "dashboard:version:{$companyId}";
    }
}
