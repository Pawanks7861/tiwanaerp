<?php

namespace App\Support\Cache;

use App\Support\Reports\DashboardCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Explicit domain invalidation map. Prefer this over scattered Cache::forget() calls.
 * All bumps are after-commit safe via {@see ErpCache::bump()}.
 */
final class ErpCacheInvalidationService
{
    public function __construct(
        private readonly ErpCache $cache,
        private readonly DashboardCache $dashboards,
    ) {}

    public function dashboards(?int $companyId): void
    {
        $this->dashboards->bump($companyId);
    }

    public function projectCostControl(?int $companyId): void
    {
        $this->cache->bump('project_cost_control', $companyId);
    }

    public function materialControl(?int $companyId): void
    {
        $this->cache->bump('material_control', $companyId);
    }

    public function approvalCounts(?int $companyId): void
    {
        $this->cache->bump('approval_count', $companyId);
    }

    public function companySettings(?int $companyId): void
    {
        $this->cache->bump('company_settings', $companyId);
    }

    public function masters(?int $companyId): void
    {
        $this->cache->bump('masters', $companyId);
    }

    public function reports(?int $companyId): void
    {
        $this->cache->bump('reports', $companyId);
    }

    /**
     * Project permanently purged / major structural change: clear all ERP domains for the company.
     */
    public function companyWide(?int $companyId): void
    {
        if (! $companyId) {
            return;
        }

        $this->dashboards($companyId);
        $this->cache->bumpMany([
            'project_cost_control',
            'material_control',
            'approval_count',
            'company_settings',
            'masters',
            'reports',
        ], $companyId);
    }

    /**
     * Model write that feeds dashboards / cost / material / reports.
     */
    public function onOperationalWrite(Model $model): void
    {
        $companyId = (int) ($model->getAttributes()['company_id'] ?? 0);
        if ($companyId <= 0) {
            return;
        }

        $this->dashboards($companyId);
        $this->projectCostControl($companyId);
        $this->materialControl($companyId);
        $this->reports($companyId);
    }

    public function onApprovalWrite(Model $model): void
    {
        $companyId = (int) ($model->getAttributes()['company_id'] ?? 0);
        if ($companyId <= 0) {
            return;
        }

        $this->approvalCounts($companyId);
        $this->dashboards($companyId);
    }

    public function onSettingsWrite(Model $model): void
    {
        $companyId = (int) ($model->getAttributes()['company_id'] ?? 0);
        if ($companyId <= 0) {
            return;
        }

        $this->companySettings($companyId);
    }

    public function onMasterWrite(Model $model): void
    {
        $companyId = (int) ($model->getAttributes()['company_id'] ?? 0);
        if ($companyId <= 0) {
            return;
        }

        $this->masters($companyId);
        $this->projectCostControl($companyId);
        $this->dashboards($companyId);
    }
}
