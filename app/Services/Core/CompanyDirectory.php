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
     * @return list<array{value: int, label: string}>
     */
    public function memberOptions(): array
    {
        return $this->membersQuery()->get(['id', 'name'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])
            ->all();
    }
}
