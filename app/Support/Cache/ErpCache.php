<?php

namespace App\Support\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cache-aside helper: versioned keys, optional stampede lock, optional flexible TTL,
 * and after-commit version bumps. MySQL remains authoritative — Redis failures fall
 * through to the callback so business reads still succeed.
 *
 * Registered as a scoped singleton so pending bumps live for one request / job only.
 */
final class ErpCache
{
    /**
     * @param  array<string, mixed>  $dimensions
     */
    public function remember(
        string $domain,
        int $companyId,
        string $name,
        array $dimensions,
        Closure $callback,
        ?int $ttl = null,
        ?bool $lock = null,
        ?bool $flexible = null,
    ): mixed {
        $ttl ??= (int) config("erp_cache.ttls.{$domain}", 60);
        $key = ErpCacheKey::make($domain, $companyId, $this->version($domain, $companyId), $name, $dimensions);
        $driver = (string) config('cache.default');
        // Array/driver test stores: skip flexible + blocking locks (can hang the suite).
        $supportsCoordination = ! in_array($driver, ['array', 'null'], true);
        $lock ??= $supportsCoordination
            && (bool) config('erp_cache.stampede.enabled', true)
            && in_array($domain, ['dashboard', 'project_cost_control', 'material_control', 'reports'], true);
        $flexible ??= $supportsCoordination
            && in_array($domain, config('erp_cache.flexible_domains', []), true);

        try {
            // Cache::flexible already coordinates concurrent rebuilds — do not nest another lock.
            if ($flexible) {
                $fresh = $ttl;
                $stale = $ttl + (int) config('erp_cache.stale_grace', 30);

                return Cache::flexible($key, [$fresh, $stale], $callback);
            }

            if ($lock) {
                return $this->withLock($key, fn () => Cache::remember($key, $ttl, $callback));
            }

            return Cache::remember($key, $ttl, $callback);
        } catch (Throwable $e) {
            if ($this->isInfrastructureFailure($e)) {
                Log::warning('erp.cache.unavailable', [
                    'domain' => $domain,
                    'company_id' => $companyId,
                    'name' => $name,
                    'message' => $e->getMessage(),
                ]);

                return $callback();
            }

            throw $e;
        }
    }

    public function version(string $domain, int $companyId): string
    {
        try {
            return (string) Cache::get(ErpCacheKey::version($domain, $companyId), '0');
        } catch (Throwable $e) {
            if ($this->isInfrastructureFailure($e)) {
                return '0';
            }

            throw $e;
        }
    }

    /**
     * Invalidate a domain for a company after the surrounding DB transaction commits.
     * When no transaction is open, Laravel runs afterCommit callbacks immediately.
     * Rolled-back transactions discard callbacks, so cache is not invalidated on failure.
     */
    public function bump(string $domain, ?int $companyId): void
    {
        if (! $companyId) {
            return;
        }

        DB::afterCommit(function () use ($domain, $companyId) {
            $this->writeVersion($domain, $companyId);
        });
    }

    /**
     * @param  list<string>  $domains
     */
    public function bumpMany(array $domains, ?int $companyId): void
    {
        foreach ($domains as $domain) {
            $this->bump($domain, $companyId);
        }
    }

    /** Kept for TransactionRolledBack listeners; afterCommit callbacks are already discarded on rollback. */
    public function forgetPending(): void
    {
        //
    }

    private function writeVersion(string $domain, int $companyId): void
    {
        try {
            Cache::forever(
                ErpCacheKey::version($domain, $companyId),
                str_replace('.', '', (string) microtime(true)).random_int(100, 999)
            );
        } catch (Throwable $e) {
            if ($this->isInfrastructureFailure($e)) {
                Log::warning('erp.cache.bump_failed', [
                    'domain' => $domain,
                    'company_id' => $companyId,
                    'message' => $e->getMessage(),
                ]);

                return;
            }

            throw $e;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withLock(string $key, Closure $callback): mixed
    {
        $seconds = (int) config('erp_cache.stampede.lock_seconds', 10);
        // Hash lock name so database cache_locks.key stays within column limits.
        $lock = Cache::lock('lock:'.hash('sha256', $key), $seconds);

        // Non-blocking acquire — never hang the request if another worker holds the lock.
        if ($lock->get()) {
            try {
                return $callback();
            } finally {
                $lock->release();
            }
        }

        $existing = Cache::get($key);
        if ($existing !== null) {
            return $existing;
        }

        return $callback();
    }

    private function isInfrastructureFailure(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return $e instanceof \RedisException
            || str_contains($message, 'redis')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'read error on connection')
            || str_contains($class = $e::class, 'Redis')
            || str_contains($class, 'Predis');
    }
}
