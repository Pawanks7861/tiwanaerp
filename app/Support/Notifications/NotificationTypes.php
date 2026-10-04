<?php

namespace App\Support\Notifications;

/**
 * Every in-app notification the product sends. The key is the preference key stored on
 * notification_preferences and in the inbox payload (`kind`). Only the database channel is
 * delivered today; mail, WhatsApp and push are listed so preferences can show them as coming later.
 */
final class NotificationTypes
{
    /** @var list<string> */
    public const FUTURE_CHANNELS = ['mail', 'whatsapp', 'push'];

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'approval.requested', 'label' => 'Approval requested', 'group' => 'Approvals'],
            ['key' => 'approval.approved', 'label' => 'Approval completed', 'group' => 'Approvals'],
            ['key' => 'approval.rejected', 'label' => 'Approval rejected', 'group' => 'Approvals'],
            ['key' => 'approval.sent_back', 'label' => 'Approval sent back', 'group' => 'Approvals'],
            ['key' => 'planning.task_assigned', 'label' => 'Task assigned', 'group' => 'Planning'],
            ['key' => 'planning.task_overdue', 'label' => 'Task overdue', 'group' => 'Planning'],
            ['key' => 'procurement.mr_submitted', 'label' => 'Material request submitted', 'group' => 'Procurement'],
            ['key' => 'procurement.po_approved', 'label' => 'Purchase order approved', 'group' => 'Procurement'],
            ['key' => 'procurement.grn_approved', 'label' => 'Goods received', 'group' => 'Procurement'],
            ['key' => 'inventory.low_stock', 'label' => 'Low stock', 'group' => 'Inventory'],
            ['key' => 'finance.invoice_certified', 'label' => 'Client invoice certified', 'group' => 'Finance'],
            ['key' => 'finance.payment_received', 'label' => 'Payment received', 'group' => 'Finance'],
            ['key' => 'quality.ncr_raised', 'label' => 'NCR raised', 'group' => 'Quality'],
            ['key' => 'reports.export_ready', 'label' => 'Report export ready', 'group' => 'Reports'],
            ['key' => 'chat.message_received', 'label' => 'Chat message', 'group' => 'Chat'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function label(?string $key): string
    {
        foreach (self::all() as $type) {
            if ($type['key'] === $key) {
                return $type['label'];
            }
        }

        return 'Notification';
    }
}
