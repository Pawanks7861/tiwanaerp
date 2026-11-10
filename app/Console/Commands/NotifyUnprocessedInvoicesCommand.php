<?php

namespace App\Console\Commands;

use App\Enums\Finance\VendorBillStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Finance\VendorBill;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class NotifyUnprocessedInvoicesCommand extends Command
{
    protected $signature = 'finance:notify-unprocessed-invoices {--days=3}';

    protected $description = 'Notify accounts of approved invoices not yet processed';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $today = now()->toDateString();
        $sent = 0;

        VendorBill::query()
            ->whereIn('status', [VendorBillStatus::Approved->value, VendorBillStatus::PartiallyPaid->value])
            ->whereNull('processed_at')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($bills) use ($today, &$sent) {
                foreach ($bills as $bill) {
                    if (! $this->dedupe('invoice_unprocessed', 'vendor_bill', (int) $bill->id, $today)) {
                        continue;
                    }
                    $this->notify($bill->company_id, $bill->project_id, 'vendor_bill', (int) $bill->id, $bill->bill_number, 'projects.vendor-bills.show');
                    $sent++;
                }
            });

        SubcontractorBill::query()
            ->where('status', SubcontractorBillStatus::Certified)
            ->whereNull('processed_at')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($bills) use ($today, &$sent) {
                foreach ($bills as $bill) {
                    if (! $this->dedupe('invoice_unprocessed', 'subcontractor_bill', (int) $bill->id, $today)) {
                        continue;
                    }
                    $this->notify($bill->company_id, $bill->project_id, 'subcontractor_bill', (int) $bill->id, $bill->bill_number, 'projects.subcontractor-bills.show');
                    $sent++;
                }
            });

        $this->info("Unprocessed invoice alerts: {$sent}");

        return self::SUCCESS;
    }

    private function notify(int $companyId, int $projectId, string $type, int $id, string $number, string $routeName): void
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('memberships', fn ($m) => $m->where('company_id', $companyId)->where('is_active', true))
            ->permission(['vendor_bills.process', 'subcontract.process', 'vendor_bills.view', 'payments.view'])
            ->limit(30)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new GeneralNotification(
            $companyId,
            'finance.invoice_unprocessed',
            'Invoice pending processing',
            "{$number} is approved but not yet processed by accounts.",
            route($routeName, [$projectId, $id], absolute: false),
            ['project_id' => $projectId, 'target_type' => $type, 'target_id' => $id],
        ));
    }

    private function dedupe(string $event, string $type, int $id, string $date): bool
    {
        return (bool) DB::table('scheduled_notification_dedupe')->insertOrIgnore([
            'event_key' => $event,
            'document_type' => $type,
            'document_id' => $id,
            'alert_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
