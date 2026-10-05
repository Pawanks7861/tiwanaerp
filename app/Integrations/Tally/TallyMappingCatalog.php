<?php

namespace App\Integrations\Tally;

/**
 * System ledgers an accountant maps once per company. Party ledgers are separate rows.
 */
final class TallyMappingCatalog
{
    /**
     * @return list<array{key: string, type: string, label: string, parent: string}>
     */
    public static function systems(): array
    {
        return [
            ['key' => 'sales', 'type' => 'sales', 'label' => 'Work income / sales', 'parent' => 'Sales Accounts'],
            ['key' => 'material', 'type' => 'material', 'label' => 'Material expense', 'parent' => 'Purchase Accounts'],
            ['key' => 'labour', 'type' => 'labour', 'label' => 'Labour charges', 'parent' => 'Indirect Expenses'],
            ['key' => 'labour_payable', 'type' => 'labour', 'label' => 'Labour payable', 'parent' => 'Current Liabilities'],
            ['key' => 'labour_deductions', 'type' => 'labour', 'label' => 'Labour deductions', 'parent' => 'Indirect Expenses'],
            ['key' => 'subcontract', 'type' => 'subcontract', 'label' => 'Subcontract expenses', 'parent' => 'Direct Expenses'],
            ['key' => 'equipment', 'type' => 'equipment', 'label' => 'Equipment charges', 'parent' => 'Direct Expenses'],
            ['key' => 'expense', 'type' => 'expense', 'label' => 'Other expense', 'parent' => 'Indirect Expenses'],
            ['key' => 'expense_payable', 'type' => 'expense', 'label' => 'Expense payable', 'parent' => 'Current Liabilities'],
            ['key' => 'gst_output_cgst', 'type' => 'gst_output', 'label' => 'Output CGST', 'parent' => 'Duties & Taxes'],
            ['key' => 'gst_output_sgst', 'type' => 'gst_output', 'label' => 'Output SGST', 'parent' => 'Duties & Taxes'],
            ['key' => 'gst_output_igst', 'type' => 'gst_output', 'label' => 'Output IGST', 'parent' => 'Duties & Taxes'],
            ['key' => 'gst_input_cgst', 'type' => 'gst_input', 'label' => 'Input CGST', 'parent' => 'Duties & Taxes'],
            ['key' => 'gst_input_sgst', 'type' => 'gst_input', 'label' => 'Input SGST', 'parent' => 'Duties & Taxes'],
            ['key' => 'gst_input_igst', 'type' => 'gst_input', 'label' => 'Input IGST', 'parent' => 'Duties & Taxes'],
            ['key' => 'gst_input', 'type' => 'gst_input', 'label' => 'Input GST (single amount)', 'parent' => 'Duties & Taxes'],
            ['key' => 'retention_receivable', 'type' => 'retention', 'label' => 'Retention receivable', 'parent' => 'Current Assets'],
            ['key' => 'retention_payable', 'type' => 'retention', 'label' => 'Retention payable', 'parent' => 'Current Liabilities'],
            ['key' => 'tds_receivable', 'type' => 'tds', 'label' => 'TDS receivable', 'parent' => 'Current Assets'],
            ['key' => 'tds_payable', 'type' => 'tds', 'label' => 'TDS payable', 'parent' => 'Duties & Taxes'],
            ['key' => 'advance_client', 'type' => 'advance', 'label' => 'Client advance', 'parent' => 'Current Liabilities'],
            ['key' => 'advance_subcontract', 'type' => 'advance', 'label' => 'Subcontract advance', 'parent' => 'Current Assets'],
            ['key' => 'other_deduction', 'type' => 'deduction', 'label' => 'Other deductions', 'parent' => 'Indirect Expenses'],
            ['key' => 'petty_cash', 'type' => 'petty_cash', 'label' => 'Petty cash', 'parent' => 'Cash-in-Hand'],
            ['key' => 'cash', 'type' => 'cash', 'label' => 'Cash', 'parent' => 'Cash-in-Hand'],
            ['key' => 'bank', 'type' => 'bank', 'label' => 'Bank', 'parent' => 'Bank Accounts'],
        ];
    }

    public static function mapKey(string $key): string
    {
        return 'system:'.$key;
    }
}
