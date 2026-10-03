<?php

namespace App\Models\Concerns;

use App\Models\Core\Company;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant-owned model: queries are filtered to the current company and company_id is set on create.
 * company_id is never mass-assignable and is never taken from request input.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            $tenancy = app(CurrentCompany::class);

            if (empty($model->company_id)) {
                $model->company_id = $tenancy->require()->id;

                return;
            }

            if ($tenancy->has() && (int) $model->company_id !== $tenancy->id()) {
                throw new LogicException('Cannot create a record for a different company than the current one.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('company_id')) {
                throw new LogicException('company_id is immutable.');
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
