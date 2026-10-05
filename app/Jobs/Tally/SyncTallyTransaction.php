<?php

namespace App\Jobs\Tally;

use App\Integrations\Tally\TallySyncService;
use App\Models\Core\Company;
use App\Models\Integrations\TallySyncRecord;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one queued voucher. A closed Tally does not fail the ERP document that queued this job.
 */
class SyncTallyTransaction implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $recordId) {}

    public function handle(TallySyncService $sync, CurrentCompany $current): void
    {
        $record = TallySyncRecord::query()->withoutGlobalScope(CompanyScope::class)->find($this->recordId);
        if ($record === null) {
            return;
        }
        $company = Company::query()->find($record->company_id);
        if ($company === null) {
            return;
        }

        $result = $current->runAs($company, fn () => $sync->deliver($this->recordId));
        if ($result === 'retry' && config('queue.default') !== 'sync') {
            $this->release($this->attempts() === 1 ? 30 : 120);
        }
    }
}
