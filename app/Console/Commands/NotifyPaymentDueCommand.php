<?php

namespace App\Console\Commands;

use App\Enums\Finance\VendorBillStatus;
use App\Models\Finance\VendorBill;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Support\Math\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class NotifyPaymentDueCommand extends Command
{
    protected $signature = 'finance:notify-payment-due';

    protected $description = 'Notify accounts of approved vendor bills due today or earlier with outstanding balance';

    public function handle(): int
    {
        $today = now()->toDateString();
        $sent = 0;

        VendorBill::query()
            ->whereIn('status', [VendorBillStatus::Approved->value, VendorBillStatus::PartiallyPaid->value])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today)
            ->orderBy('id')
            ->chunkById(50, function ($bills) use ($today, &$sent) {
                foreach ($bills as $bill) {
                    $outstanding = Decimal::of($bill->net_payable ?? 0)->minus($bill->paid_amount ?? 0);
                    if (! $outstanding->isPositive()) {
                        continue;
                    }
                    if (! $this->dedupe('payment_due', 'vendor_bill', (int) $bill->id, $today)) {
                        continue;
                    }

                    $recipients = User::query()
                        ->where('is_active', true)
                        ->whereHas('memberships', fn ($m) => $m->where('company_id', $bill->company_id)->where('is_active', true))
                        ->permission(['payments.view', 'vendor_bills.view', 'dashboard.view_financials'])
                        ->limit(30)
                        ->get();

                    if ($recipients->isEmpty()) {
                        continue;
                    }

                    Notification::send($recipients, new GeneralNotification(
                        (int) $bill->company_id,
                        'finance.payment_due',
                        'Vendor payment due',
                        "{$bill->bill_number} is due {$bill->due_date?->toDateString()} with outstanding {$outstanding->toMoney()}.",
                        route('projects.vendor-bills.show', [$bill->project_id, $bill->id], absolute: false),
                        [
                            'project_id' => (int) $bill->project_id,
                            'target_type' => 'vendor_bill',
                            'target_id' => (int) $bill->id,
                        ],
                    ));
                    $sent++;
                }
            });

        $this->info("Payment due alerts: {$sent}");

        return self::SUCCESS;
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
