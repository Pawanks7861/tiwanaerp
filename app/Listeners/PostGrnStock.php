<?php

namespace App\Listeners;

use App\Events\Procurement\GrnApproved;
use App\Models\Core\Company;
use App\Models\Procurement\Grn;
use App\Services\Inventory\GrnStockPoster;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GRN approved → grn_in postings. GrnApproved is dispatched after the approval commits, so the
 * posting runs in its own transaction: a failure is reported (the approval stands) and the GRN
 * shows up in inventory:reconcile and inventory:post-existing-grns until it is posted. Retries
 * are harmless because the poster is idempotent.
 *
 * Stock posting stays outside the ApprovalService transaction on purpose (see
 * docs/adr/0001-approval-transaction-boundaries.md). Do not move it in-tx without a new ADR.
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
                $results = $this->poster->post($grn);
                $created = count(array_filter($results, fn (array $row) => $row['created']));

                Log::info('grn.stock_posting_completed', [
                    'event' => 'grn.stock_posting_completed',
                    'grn_id' => $grn->id,
                    'grn_number' => $grn->grn_number,
                    'company_id' => $grn->company_id,
                    'lines_considered' => count($results),
                    'lines_created' => $created,
                    'incomplete' => $this->poster->hasIncompletePosting($grn),
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('grn.stock_posting_failed', [
                'event' => 'grn.stock_posting_failed',
                'grn_id' => $event->grn->id,
                'grn_number' => $event->grn->grn_number,
                'company_id' => $event->grn->company_id,
                'warehouse_id' => $event->grn->warehouse_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'recovery' => 'inventory:reconcile (detect) then inventory:post-existing-grns (idempotent post)',
            ]);
            report($e);
        }
    }
}
