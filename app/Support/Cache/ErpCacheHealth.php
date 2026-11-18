<?php

namespace App\Support\Cache;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Safe Redis / cache diagnostics for admin screens (no credentials).
 */
final class ErpCacheHealth
{
    /**
     * @return array{
     *   driver: string,
     *   redis: string,
     *   redis_client: string|null,
     *   latency_ms: float|null,
     *   message: string|null
     * }
     */
    public function status(): array
    {
        $driver = (string) config('cache.default');
        $client = config('database.redis.client');
        $probe = $this->probeRedis();

        return [
            'driver' => $driver,
            'redis' => $probe['ok'] ? 'Connected' : 'Unavailable',
            'redis_client' => is_string($client) ? $client : null,
            'latency_ms' => $probe['latency_ms'],
            'message' => $probe['message'],
        ];
    }

    /**
     * @return array{ok: bool, latency_ms: float|null, message: string|null}
     */
    private function probeRedis(): array
    {
        $started = microtime(true);

        try {
            if (config('database.redis.client') === 'phpredis' && ! extension_loaded('redis')) {
                return [
                    'ok' => false,
                    'latency_ms' => null,
                    'message' => 'phpredis extension is not loaded',
                ];
            }

            Redis::connection('cache')->ping();
            $latency = round((microtime(true) - $started) * 1000, 2);

            return [
                'ok' => true,
                'latency_ms' => $latency,
                'message' => null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'latency_ms' => null,
                'message' => $e->getMessage(),
            ];
        }
    }
}
