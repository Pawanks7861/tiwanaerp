<?php

namespace App\Services\Projects;

use App\Models\Core\Company;
use App\Models\Projects\ProjectCostHead;
use App\Support\Cache\ErpCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds and resolves company Project Cost Heads (WBS) for Cost Control.
 */
class ProjectCostHeadService
{
    /** @var list<array{code: string, name: string, sort_order: int}> */
    public const DEFAULTS = [
        ['code' => 'WBS-01', 'name' => 'Land', 'sort_order' => 10],
        ['code' => 'WBS-02', 'name' => 'Building', 'sort_order' => 20],
        ['code' => 'WBS-03', 'name' => 'Plant & Machinery', 'sort_order' => 30],
        ['code' => 'WBS-04', 'name' => 'Electrical', 'sort_order' => 40],
        ['code' => 'WBS-05', 'name' => 'IT', 'sort_order' => 50],
        ['code' => 'WBS-06', 'name' => 'Others', 'sort_order' => 60],
        ['code' => 'WBS-99', 'name' => 'Contingency Reserve', 'sort_order' => 99],
    ];

    /**
     * @return Collection<int, ProjectCostHead>
     */
    public function ensureDefaults(Company $company): Collection
    {
        return DB::transaction(function () use ($company) {
            foreach (self::DEFAULTS as $row) {
                $existing = ProjectCostHead::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('code', $row['code'])
                    ->first();

                if ($existing !== null) {
                    // Keep system display names current (e.g. Contingency → Contingency Reserve).
                    if ($existing->is_system && $existing->name !== $row['name']) {
                        $existing->forceFill(['name' => $row['name']])->save();
                        app(ErpCache::class)->bump('masters', (int) $company->id);
                    }

                    continue;
                }

                $head = new ProjectCostHead;
                $head->forceFill([
                    'company_id' => $company->id,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'sort_order' => $row['sort_order'],
                    'is_system' => true,
                    'is_active' => true,
                ])->save();
            }

            return ProjectCostHead::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('code', array_column(self::DEFAULTS, 'code'))
                ->ordered()
                ->get();
        });
    }

    /**
     * @return Collection<int, ProjectCostHead>
     */
    public function activeForCompany(Company $company): Collection
    {
        $this->ensureDefaults($company);

        return ProjectCostHead::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * @return list<array{id: int, code: string, name: string, sort_order: int, label: string}>
     */
    public function options(Company $company): array
    {
        $companyId = (int) $company->id;

        return app(ErpCache::class)->remember(
            'masters',
            $companyId,
            'cost-heads:options',
            [],
            fn () => $this->activeForCompany($company)
                ->map(fn (ProjectCostHead $h) => [
                    'id' => $h->id,
                    'code' => $h->code,
                    'name' => $h->name,
                    'sort_order' => $h->sort_order,
                    'label' => $h->code.' — '.$h->name,
                ])
                ->values()
                ->all(),
            lock: false,
            flexible: false,
        );
    }
}
