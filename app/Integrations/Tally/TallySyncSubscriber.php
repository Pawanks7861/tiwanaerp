<?php

namespace App\Integrations\Tally;

use App\Enums\Finance\PaymentStatus;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Expense;
use App\Models\Finance\Payment;
use App\Models\Finance\PettyCashTransaction;
use App\Models\Finance\VendorBill;
use App\Models\Integrations\TallyConnection;
use App\Models\Labour\LabourPayment;
use App\Models\Subcontract\SubcontractorBill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * After a document is finalized, queue Tally. The listener does nothing when integration is off,
 * so approval still commits when Tally is not configured.
 */
class TallySyncSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        foreach ([ClientInvoice::class, Payment::class, VendorBill::class, Expense::class, SubcontractorBill::class, LabourPayment::class, PettyCashTransaction::class] as $class) {
            $events->listen('eloquent.created: '.$class, [self::class, 'created']);
            $events->listen('eloquent.updated: '.$class, [self::class, 'updated']);
        }
    }

    public function created(Model $model): void
    {
        $this->queue($model);
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Payment && $model->wasChanged('status') && $model->status === PaymentStatus::Cancelled) {
            $this->queue($model, 'cancel');

            return;
        }
        if ($model instanceof Expense && $model->wasChanged('reversed_at') && $model->reversed_at !== null) {
            $this->queue($model, 'cancel');

            return;
        }
        if (! $model->wasChanged('status') && ! $model->wasRecentlyCreated) {
            return;
        }
        $this->queue($model);
    }

    private function queue(Model $model, string $action = 'export'): void
    {
        $connection = TallyConnection::query()->first();
        if (! $connection?->enabled || ! $connection->auto_sync || ! $connection->sync_approved_transactions) {
            return;
        }
        $documents = app(TallyDocumentRegistry::class);
        $allowed = $action === 'cancel' ? $documents->canCancel($model) : $documents->canExport($model);
        if (! $allowed) {
            return;
        }

        DB::afterCommit(function () use ($model, $action) {
            app(TallySyncService::class)->enqueue($model->fresh() ?? $model, $action);
        });
    }
}
