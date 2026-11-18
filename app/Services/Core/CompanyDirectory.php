<?php

namespace App\Services\Core;

use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lookups of people in the current company (users are global; membership makes them visible).
 */
class CompanyDirectory
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return Builder<User>
     */
    public function membersQuery(bool $activeOnly = true): Builder
    {
        $companyId = $this->tenancy->require()->id;

        return User::query()
            ->whereHas('memberships', function (Builder $m) use ($companyId, $activeOnly) {
                $m->where('company_id', $companyId)->when($activeOnly, fn ($q) => $q->where('is_active', true));
            })
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('name');
    }

    /**
     * @return list<array{value: int, label: string, description?: string|null}>
     */
    public function memberOptions(): array
    {
        $companyId = $this->tenancy->require()->id;
        $teamKey = config('permission.column_names.team_foreign_key');

        return $this->membersQuery()
            ->with(['roles' => fn ($q) => $q->where($teamKey, $companyId)->orderBy('name')])
            ->get(['id', 'name'])
            ->map(function (User $u) {
                $roleNames = $u->roles->pluck('name')->filter()->values();

                return [
                    'value' => $u->id,
                    'label' => $u->name,
                    'description' => $roleNames->isNotEmpty() ? $roleNames->implode(', ') : null,
                ];
            })
            ->all();
    }
}
