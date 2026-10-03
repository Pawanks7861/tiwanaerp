<?php

namespace App\Listeners;

use App\Events\Procurement\GrnApproved;
use App\Models\Core\Company;
use App\Models\Procurement\Grn;
use App\Services\Inventory\GrnStockPoster;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Throwable;

/**
 * GRN approved → grn_in postings. GrnApproved is dispatched after the approval commits, so the
 * posting runs in its own transaction: a failure is reported (the approval stands) and the GRN
 * shows up in inventory:reconcile and inventory:post-existing-grns until it is posted. Retries
 * are harmless because the poster is idempotent.
 */
class PostGrnStock
{
    public function __construct(
        private readonly GrnStockPoster $poster,
        private readonly CurrentCompany $tenancy,
    ) {}

    public function handle(GrnApproved $event): void
    {
        $company = Company::query()->find($event->grn->company_id);
        if ($company === null) {
            return;
        }

        try {
            $this->tenancy->runAs($company, function () use ($event) {
                $grn = Grn::query()->withoutGlobalScope(CompanyScope::class)->findOrFail($event->grn->id);
                $this->poster->post($grn);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
