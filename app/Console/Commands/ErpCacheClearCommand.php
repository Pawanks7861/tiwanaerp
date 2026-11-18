<?php

namespace App\Console\Commands;

use App\Support\Cache\ErpCacheInvalidationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('erp:cache-clear {--company= : Company id to invalidate} {--domain=* : Domains (dashboard,project_cost_control,material_control,approval_count,company_settings,masters,reports)}')]
#[Description('Invalidate ERP performance-cache domains without Redis FLUSHALL')]
class ErpCacheClearCommand extends Command
{
    public function handle(ErpCacheInvalidationService $invalidation): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $domains = array_values(array_filter((array) $this->option('domain')));

        if ($companyId === null || $companyId <= 0) {
            $this->error('Pass --company=ID. Scoped invalidation only — never FLUSHALL.');

            return self::FAILURE;
        }

        if ($domains === []) {
            $invalidation->companyWide($companyId);
            $this->info("Invalidated all ERP cache domains for company {$companyId}.");

            return self::SUCCESS;
        }

        foreach ($domains as $domain) {
            match ($domain) {
                'dashboard' => $invalidation->dashboards($companyId),
                'project_cost_control' => $invalidation->projectCostControl($companyId),
                'material_control' => $invalidation->materialControl($companyId),
                'approval_count' => $invalidation->approvalCounts($companyId),
                'company_settings' => $invalidation->companySettings($companyId),
                'masters' => $invalidation->masters($companyId),
                'reports' => $invalidation->reports($companyId),
                default => $this->warn("Unknown domain: {$domain}"),
            };
        }

        $this->info('ERP cache invalidation complete for company '.$companyId.'.');

        return self::SUCCESS;
    }
}
