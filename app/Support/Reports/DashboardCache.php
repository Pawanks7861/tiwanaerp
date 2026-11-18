<?php

namespace App\Support\Reports;

use App\Support\Cache\ErpCache;
use Closure;

/**
 * Dashboard KPI cache. Keys carry company, per-company version, and a hash of every
 * dimension that changes what may be shown. Writes bump the version after commit.
 *
 * Delegates to {@see ErpCache} so TTL, stampede locks, and Redis fallback stay central.
 */
final class DashboardCache
{
    public function __construct(private readonly ErpCache $cache) {}

    /**
     * @param  array<string, mixed>  $dimensions
     */
    public function remember(int $companyId, string $kind, array $dimensions, Closure $callback): mixed
    {
        return $this->cache->remember(
            'dashboard',
            $companyId,
            $kind,
            $dimensions,
            $callback,
            (int) config('erp_cache.ttls.dashboard', 60),
            lock: true,
            flexible: true,
        );
    }

    public function version(int $companyId): string
    {
        return $this->cache->version('dashboard', $companyId);
    }

    public function bump(?int $companyId): void
    {
        $this->cache->bump('dashboard', $companyId);
    }

    public function forgetPending(): void
    {
        $this->cache->forgetPending();
    }
}
