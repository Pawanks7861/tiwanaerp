<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(CurrentCompany::class);

        if ($tenancy->isBypassed()) {
            return;
        }

        if ($tenancy->has()) {
            $builder->where($model->qualifyColumn('company_id'), $tenancy->id());

            return;
        }

        // Fail closed: a query that runs outside any company context must never leak rows.
        $builder->whereRaw('1 = 0');
    }
}
