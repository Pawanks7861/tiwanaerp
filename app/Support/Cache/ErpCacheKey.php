<?php

namespace App\Support\Cache;

/**
 * Central cache-key builder. Laravel's CACHE_PREFIX is applied by the store;
 * these segments stay application-specific and tenancy-safe.
 */
final class ErpCacheKey
{
    /**
     * Compact, tenancy-safe cache key that fits database VARCHAR limits.
     * Long human names / filter bags are hashed — never embedded verbatim.
     */
    public static function make(string $domain, int $companyId, string $version, string $name, array $dimensions = []): string
    {
        // Single digest of name + dimensions keeps keys short even with large project ID lists.
        $digest = self::filterHash([
            'name' => $name,
            'dimensions' => $dimensions,
        ]);

        // Version can be long (microtime); keep a short fingerprint only.
        $ver = strlen($version) > 16 ? substr(hash('sha256', $version), 0, 12) : $version;

        return "erp:{$domain}:c{$companyId}:v{$ver}:{$digest}";
    }

    public static function version(string $domain, int $companyId): string
    {
        return "erp:{$domain}:c{$companyId}:version";
    }

    public static function dashboardMd(int $companyId, string $fy, ?int $projectId = null): string
    {
        $project = $projectId ? ":project:{$projectId}" : '';

        return "company:{$companyId}:dashboard:md:fy:{$fy}{$project}";
    }

    public static function dashboardAdmin(int $companyId, string $fy, ?int $projectId = null): string
    {
        $project = $projectId ? ":project:{$projectId}" : '';

        return "company:{$companyId}:dashboard:admin:fy:{$fy}{$project}";
    }

    public static function dashboardDirector(int $companyId, ?int $projectId, string $fy): string
    {
        $project = $projectId ? "project:{$projectId}" : 'portfolio';

        return "company:{$companyId}:{$project}:dashboard:director:fy:{$fy}";
    }

    public static function projectCostControl(int $companyId, int $projectId, ?string $asOf = null): string
    {
        return "company:{$companyId}:project:{$projectId}:cost-control:".($asOf ?? 'live');
    }

    public static function portfolioCostControl(int $companyId, string $asOf, string $projectHash): string
    {
        return "company:{$companyId}:portfolio:cost-control:{$asOf}:{$projectHash}";
    }

    public static function materialControl(int $companyId, int $projectId, string $filterHash): string
    {
        return "company:{$companyId}:project:{$projectId}:material-control:{$filterHash}";
    }

    public static function approvalCount(int $companyId, int $userId): string
    {
        return "company:{$companyId}:user:{$userId}:approval:pending-count";
    }

    public static function companySettings(int $companyId): string
    {
        return "company:{$companyId}:settings";
    }

    public static function report(string $reportKey, int $companyId, string $filterHash): string
    {
        return "report:{$reportKey}:{$companyId}:{$filterHash}";
    }

    /**
     * Normalize filter bags so equivalent queries share one cache entry.
     *
     * @param  array<string, mixed>  $dimensions
     */
    public static function normalize(array $dimensions): array
    {
        array_walk_recursive($dimensions, function (&$value) {
            if ($value === '') {
                $value = null;
            }
        });

        ksort($dimensions);

        foreach ($dimensions as $key => $value) {
            if (is_array($value)) {
                $dimensions[$key] = self::normalize($value);
            }
        }

        return $dimensions;
    }

    /**
     * @param  array<string, mixed>  $dimensions
     */
    public static function filterHash(array $dimensions): string
    {
        return hash('sha256', (string) json_encode(self::normalize($dimensions)));
    }
}
