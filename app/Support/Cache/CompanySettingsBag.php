<?php

namespace App\Support\Cache;

use App\Models\Core\Company;

/**
 * Cached company settings key/value bag (presentation / flags only).
 * Invalidated on CompanySetting / Company writes via ErpCacheInvalidationService.
 */
final class CompanySettingsBag
{
    public function __construct(private readonly ErpCache $cache) {}

    /**
     * @return array<string, mixed>
     */
    public function all(Company $company): array
    {
        $companyId = (int) $company->id;

        return $this->cache->remember(
            'company_settings',
            $companyId,
            ErpCacheKey::companySettings($companyId),
            [],
            fn () => $company->settings()
                ->withoutGlobalScopes()
                ->pluck('value', 'key')
                ->all(),
            lock: false,
            flexible: false,
        );
    }

    public function get(Company $company, string $key, mixed $default = null): mixed
    {
        $all = $this->all($company);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}
