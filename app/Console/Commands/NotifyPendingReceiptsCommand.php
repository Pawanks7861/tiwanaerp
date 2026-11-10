<?php

namespace App\Console\Commands;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Procurement\PurchaseOrder;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Support\Math\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class NotifyPendingReceiptsCommand extends Command
{
    protected $signature = 'procurement:notify-pending-receipts';

    protected $description = 'Notify purchase/store users about overdue partially received POs';

    public function handle(): int
    {
        $today = now()->toDateString();
        $sent = 0;

        PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->whereNotNull('delivery_date')
            ->whereDate('delivery_date', '<', $today)
            ->with(['project:id,company_id'])
            ->orderBy('id')
            ->chunkById(50, function ($orders) use ($today, &$sent) {
                foreach ($orders as $order) {
                    $ordered = Decimal::of($order->items()->sum('quantity'));
                    $received = Decimal::of($order->items()->sum('received_qty'));
                    if (! $ordered->minus($received)->isPositive()) {
                        continue;
                    }

                    if (! $this->dedupe('po_pending_receipt', 'purchase_order', (int) $order->id, $today)) {
                        continue;
                    }

                    $recipients = User::query()
                        ->where('is_active', true)
                        ->whereHas('memberships', fn ($m) => $m->where('company_id', $order->company_id)->where('is_active', true))
                        ->permission(['purchase.view', 'grn.create'])
                        ->limit(30)
                        ->get();

                    if ($recipients->isEmpty()) {
                        continue;
                    }

                    Notification::send($recipients, new GeneralNotification(
                        (int) $order->company_id,
                        'procurement.pending_receipt',
                        'PO receipt overdue',
                        "{$order->po_number} delivery was due {$order->delivery_date?->toDateString()} with quantity still open.",
                        route('projects.purchase-orders.show', [$order->project_id, $order->id], absolute: false),
                        [
                            'project_id' => (int) $order->project_id,
                            'target_type' => 'purchase_order',
                            'target_id' => (int) $order->id,
                        ],
                    ));
                    $sent++;
                }
            });

        $this->info("Pending receipt alerts: {$sent}");

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
