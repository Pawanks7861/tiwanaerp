<?php

namespace App\Support\Permissions;

/**
 * Default company roles (architecture M.3). Seeded per company as system roles; company admins
 * can clone and edit them. Platform "Super Admin" is a user flag, not a role.
 */
final class DefaultRoles
{
    public const COMPANY_ADMIN = 'Company Admin';

    public const DIRECTOR = 'Director';

    public const PROJECT_MANAGER = 'Project Manager';

    public const SITE_ENGINEER = 'Site Engineer';

    public const PURCHASE_MANAGER = 'Purchase Manager';

    public const STORE_MANAGER = 'Store Manager';

    public const ACCOUNTANT = 'Accountant';

    public const BILLING_ENGINEER = 'Billing Engineer';

    public const QUALITY_ENGINEER = 'Quality Engineer';

    /**
     * @return array<string, array{description: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        $viewMasters = ['masters.*.view', 'crm.clients.view'];

        return [
            self::COMPANY_ADMIN => [
                'description' => 'Full access to all modules and company administration.',
                'permissions' => ['*'],
            ],
            self::DIRECTOR => [
                'description' => 'Views everything and gives final approvals.',
                'permissions' => [
                    '*.view', 'projects.view_all', 'dashboard.view_financials', 'reports.view_financial', 'reports.export',
                    'boq.approve', 'boq.view_costs', 'boq.view_material_requirements', 'boq.view_bbs', 'boq.approve_bbs', 'boq.view_reconciliation',
                    'boq.view_variations', 'boq.manage_variations', 'boq.approve_variations',
                    'budget.approve', 'purchase.approve', 'bid_comparison.approve',
                    'subcontract.approve_wo', 'subcontract.certify_bill', 'billing.certify', 'vendor_bills.approve',
                    'payments.approve', 'expenses.approve', 'crm.quotations.approve', 'inventory.view_valuation',
                    'inventory.approve_adjustment', 'gate_pass.approve', 'ai.use',
                ],
            ],
            self::PROJECT_MANAGER => [
                'description' => 'Runs assigned projects: planning, site, approvals.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'projects.update', 'projects.manage_team', 'projects.import', 'sites.*',
                    'boq.view', 'boq.create', 'boq.update', 'boq.submit', 'boq.import', 'boq.view_costs', 'boq.manage_cost',
                    'boq.view_material_requirements', 'boq.manage_material_norms', 'boq.manage_recipes', 'boq.manage_unit_conversions', 'boq.manage_steel_profiles', 'boq.manage_rate_library',
                    'boq.view_bbs', 'boq.manage_bbs', 'boq.manage_bbs_adjustments', 'boq.approve_bbs', 'boq.view_reconciliation',
                    'boq.view_variations', 'boq.manage_variations', 'boq.approve_variations', 'rate_analysis.view',
                    'budget.view', 'planning.*', 'site_diary.*', 'dpr.*', 'material_requests.*', 'purchase.view',
                    'grn.view', 'grn.approve', 'inventory.view', 'labour.*', 'subcontract.view', 'subcontract.create',
                    'subcontract.update', 'subcontract.delete', 'subcontract.certify_bill', 'equipment.view',
                    'equipment.update', 'equipment.assign', 'equipment.log_usage', 'equipment.import',
                    'gate_pass.view', 'gate_pass.create', 'gate_pass.approve', 'expenses.view', 'expenses.approve', 'billing.view',
                    'billing.submit', 'quality.*', 'drawings.*', 'documents.*', 'reports.view', 'ai.use', ...$viewMasters,
                ],
            ],
            self::SITE_ENGINEER => [
                'description' => 'Daily site work on assigned projects.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'sites.view', 'boq.view', 'boq.view_material_requirements', 'boq.view_bbs', 'boq.view_reconciliation',
                    'boq.view_variations', 'boq.manage_variations', 'planning.view',
                    'planning.update_progress', 'site_diary.view', 'site_diary.create', 'site_diary.update',
                    'site_diary.submit', 'dpr.view', 'dpr.create', 'dpr.update', 'dpr.submit',
                    'material_requests.view', 'material_requests.create', 'material_requests.update', 'material_requests.import',
                    'material_requests.submit', 'inventory.view', 'labour.view', 'labour.create', 'labour.update', 'labour.import', 'labour.mark_attendance',
                    'equipment.view', 'equipment.log_usage', 'gate_pass.view', 'gate_pass.create', 'gate_pass.verify',
                    'expenses.view', 'expenses.create', 'expenses.import', 'expenses.submit',
                    'quality.view', 'quality.create_inspection', 'quality.raise_ncr', 'drawings.view', 'documents.view',
                    'ai.use', ...$viewMasters,
                ],
            ],
            self::PURCHASE_MANAGER => [
                'description' => 'Procurement from material request to purchase order.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'ai.use', 'boq.view_material_requirements', 'boq.view_bbs', 'boq.view_reconciliation', 'boq.view_variations',
                    'material_requests.view', 'material_requests.approve',
                    'rfq.*', 'vendor_quotations.*', 'bid_comparison.view', 'bid_comparison.create', 'purchase.view',
                    'purchase.create', 'purchase.update', 'purchase.submit', 'purchase.export', 'purchase.import', 'grn.view',
                    'inventory.view', 'reports.view', 'masters.items.*', 'masters.vendors.*', 'masters.units.view',
                    'masters.categories.*', 'masters.tax_rates.view', 'masters.warehouses.view',
                ],
            ],
            self::STORE_MANAGER => [
                'description' => 'Receipts, stock movements and issues.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'boq.view_material_requirements', 'boq.view_reconciliation', 'boq.view_variations',
                    'purchase.view', 'grn.view', 'grn.create',
                    'grn.update', 'grn.submit', 'grn.import', 'gate_pass.view', 'gate_pass.create', 'gate_pass.verify',
                    'inventory.view', 'inventory.issue', 'inventory.transfer',
                    'inventory.return', 'inventory.adjust', 'inventory.import', 'material_requests.view', 'reports.view',
                    'masters.items.view', 'masters.items.import', 'masters.units.view', 'masters.warehouses.*',
                ],
            ],
            self::ACCOUNTANT => [
                'description' => 'Expenses, bills, payments and finance reports.',
                'permissions' => [
                    'dashboard.view', 'dashboard.view_financials', 'approvals.view', 'projects.view', 'projects.view_all',
                    'expenses.*', 'petty_cash.*', 'billing.view', 'vendor_bills.*', 'payments.view', 'payments.record',
                    'subcontract.view', 'labour.view', 'labour.manage_payments', 'equipment.view', 'purchase.view', 'grn.view',
                    'reports.*', 'inventory.view', 'inventory.view_valuation',
                    'tally.view', 'tally.sync', 'tally.retry', 'tally.mapping',
                    'masters.vendors.view', 'masters.subcontractors.view', 'masters.expense_categories.*',
                    'masters.tax_rates.*', 'crm.clients.*',
                ],
            ],
            self::BILLING_ENGINEER => [
                'description' => 'BOQ preparation and client / subcontractor billing.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'boq.view', 'boq.create', 'boq.update',
                    'boq.submit', 'boq.import', 'boq.export', 'boq.view_costs', 'boq.manage_cost',
                    'boq.view_material_requirements', 'boq.manage_material_norms', 'boq.manage_recipes', 'boq.manage_unit_conversions', 'boq.manage_steel_profiles', 'boq.manage_rate_library',
                    'boq.view_bbs', 'boq.manage_bbs', 'boq.manage_bbs_adjustments', 'boq.view_reconciliation',
                    'boq.view_variations', 'boq.manage_variations', 'rate_analysis.*', 'budget.view',
                    'billing.view', 'billing.create', 'billing.update', 'billing.submit', 'billing.export',
                    'subcontract.view', 'subcontract.create', 'subcontract.update', 'dpr.view', 'planning.view',
                    'reports.view', ...$viewMasters,
                ],
            ],
            self::QUALITY_ENGINEER => [
                'description' => 'Inspections, checklists and NCRs.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'quality.*', 'drawings.view', 'documents.view',
                    'planning.view', 'boq.view', 'reports.view', ...$viewMasters,
                ],
            ],
        ];
    }
}
