<?php

namespace App\Integrations\Tally;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Integrations\TallySyncStatus;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Payment;
use App\Models\Finance\VendorBill;
use App\Models\Integrations\TallySyncRecord;
use App\Support\Tenancy\CurrentCompany;

/**
 * Version 1 compares ERP sync records with the acknowledgement Tally returned.
 * It does not pull a general ledger.
 */
class TallyReconciliationService
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array{counts: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function summary(): array
    {
        $counts = [
            'matched' => 0,
            'erp_only' => 0,
            'tally_conflict' => 0,
            'failed' => 0,
            'needs_review' => 0,
        ];
        $rows = [];

        TallySyncRecord::query()->orderByDesc('id')->limit(200)->get()->each(function (TallySyncRecord $record) use (&$counts, &$rows) {
            $bucket = match ($record->status) {
                TallySyncStatus::Synced, TallySyncStatus::ReversalSynced => 'matched',
                TallySyncStatus::Conflict => 'tally_conflict',
                TallySyncStatus::Failed => 'failed',
                TallySyncStatus::NeedsMapping => 'needs_review',
                default => 'erp_only',
            };
            $counts[$bucket]++;
            $rows[] = [
                'id' => $record->id,
                'reference' => $record->erp_reference,
                'type' => $record->source_type,
                'status' => $bucket,
                'status_label' => $this->label($bucket),
                'tally_reference' => $record->tally_guid ?: $record->tally_alter_id,
                'message' => $record->error_message,
            ];
        });

        $companyId = $this->tenancy->id();
        $counts['erp_only'] += $this->missing(ClientInvoice::class, 'client_invoices', 'client_invoice', ClientInvoiceStatus::certifiedStates(), $companyId);
        $counts['erp_only'] += $this->missing(VendorBill::class, 'vendor_bills', 'vendor_bill', VendorBillStatus::approvedStates(), $companyId);
        $counts['erp_only'] += Payment::query()->where('status', PaymentStatus::Approved->value)->whereNotExists(function ($query) use ($companyId) {
            $query->selectRaw('1')->from('tally_sync_records')
                ->whereColumn('tally_sync_records.source_id', 'payments.id')
                ->where('tally_sync_records.company_id', $companyId)
                ->where('tally_sync_records.source_type', 'payment')
                ->where('tally_sync_records.action', 'export')
                ->where('tally_sync_records.status', TallySyncStatus::Synced->value);
        })->count();

        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * @param  list<\BackedEnum>  $states
     */
    private function missing(string $model, string $table, string $type, array $states, ?int $companyId): int
    {
        return $model::query()
            ->whereIn('status', array_map(fn ($state) => $state->value, $states))
            ->whereNotExists(function ($query) use ($table, $type, $companyId) {
                $query->selectRaw('1')->from('tally_sync_records')
                    ->whereColumn('tally_sync_records.source_id', $table.'.id')
                    ->where('tally_sync_records.company_id', $companyId)
                    ->where('tally_sync_records.source_type', $type)
                    ->where('tally_sync_records.action', 'export')
                    ->where('tally_sync_records.status', TallySyncStatus::Synced->value);
            })
            ->count();
    }
}
