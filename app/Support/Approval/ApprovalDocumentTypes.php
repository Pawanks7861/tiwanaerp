<?php

namespace App\Support\Approval;

/**
 * Processes that use the approval engine. Company-wide workflows are edited from Administration.
 */
final class ApprovalDocumentTypes
{
    /**
     * @return array<string, array{label: string, group: string}>
     */
    public static function all(): array
    {
        return [
            'material_request' => ['label' => 'Material Request', 'group' => 'Procurement'],
            'purchase_order' => ['label' => 'Purchase Order', 'group' => 'Procurement'],
            'grn' => ['label' => 'GRN', 'group' => 'Procurement'],
            'material_issue' => ['label' => 'Material Issue', 'group' => 'Inventory'],
            'material_return' => ['label' => 'Material Return', 'group' => 'Inventory'],
            'dpr' => ['label' => 'DPR', 'group' => 'Site'],
            'gate_pass' => ['label' => 'Gate Pass', 'group' => 'Site'],
            'scrap_generation' => ['label' => 'Scrap Generation', 'group' => 'Site'],
            'scrap_adjustment' => ['label' => 'Scrap Adjustment', 'group' => 'Site'],
            'boq' => ['label' => 'BOQ', 'group' => 'Projects'],
            'work_order' => ['label' => 'Work Order', 'group' => 'Subcontract'],
            'subcontractor_bill' => ['label' => 'Subcontractor Bill', 'group' => 'Subcontract'],
            'client_invoice' => ['label' => 'Client Bill', 'group' => 'Finance'],
            'vendor_bill' => ['label' => 'Vendor Bill', 'group' => 'Finance'],
            'expense' => ['label' => 'Expense', 'group' => 'Finance'],
            'retention_release' => ['label' => 'Retention Release', 'group' => 'Finance'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    public static function label(string $type): string
    {
        return self::all()[$type]['label'] ?? $type;
    }

    public static function group(string $type): string
    {
        return self::all()[$type]['group'] ?? 'Other';
    }
}
