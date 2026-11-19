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

    public const BOQ_PREP = 'BOQ Prep';

    public const CIVIL_LEAD = 'Civil Lead';

    public const MANAGEMENT = 'Management';

    public const STORE_INCHARGE = 'Store Incharge';

    /**
     * @return array<string, array{description: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        $viewMasters = ['masters.*.view', 'crm.clients.view'];

        $directorPermissions = [
            '*.view', 'projects.view_all', 'dashboard.view_financials', 'dashboard.director', 'reports.view_financial', 'reports.export',
            'boq.approve', 'boq.view_costs', 'boq.view_material_requirements', 'boq.view_bbs', 'boq.approve_bbs', 'boq.view_reconciliation',
            'budget.approve', 'purchase.approve', 'bid_comparison.approve',
            'subcontract.approve_wo', 'subcontract.certify_bill', 'subcontract.export', 'billing.certify', 'vendor_bills.approve',
            'payments.approve', 'expenses.approve', 'crm.quotations.approve', 'inventory.view_valuation',
            'inventory.approve_adjustment', 'gate_pass.approve', 'scrap.view', 'scrap.approve',
            'utilities.view', 'utilities.electricity.view', 'utilities.electricity.view_costs', 'utilities.electricity.review',
            'utilities.electricity.approve', 'utilities.electricity.export', 'ai.use',
        ];

        $managementPermissions = [
            '*.view', 'projects.view_all', 'dashboard.view_financials', 'dashboard.md', 'reports.view_financial', 'reports.export',
            'boq.approve', 'boq.view_costs', 'boq.view_material_requirements', 'boq.view_bbs', 'boq.approve_bbs', 'boq.view_reconciliation',
            'budget.approve', 'purchase.approve', 'bid_comparison.approve',
            'subcontract.approve_wo', 'subcontract.certify_bill', 'subcontract.export', 'billing.certify', 'vendor_bills.approve',
            'payments.approve', 'expenses.approve', 'crm.quotations.approve', 'inventory.view_valuation',
            'inventory.approve_adjustment', 'gate_pass.approve', 'scrap.view', 'scrap.approve',
            'utilities.view', 'utilities.electricity.view', 'utilities.electricity.view_costs', 'utilities.electricity.review',
            'utilities.electricity.approve', 'utilities.electricity.export', 'ai.use',
        ];

        $storeManagerPermissions = [
            'dashboard.view', 'approvals.view', 'projects.view', 'boq.view_material_requirements', 'boq.view_reconciliation',
            'purchase.view', 'grn.view', 'grn.create',
            'grn.update', 'grn.submit', 'grn.import', 'gate_pass.view', 'gate_pass.create', 'gate_pass.verify',
            'scrap.view', 'scrap.adjust',
            'inventory.view', 'inventory.issue', 'inventory.transfer',
            'inventory.return', 'inventory.adjust', 'inventory.import', 'material_requests.view', 'reports.view',
            'masters.items.view', 'masters.items.import', 'masters.units.view', 'masters.warehouses.*',
        ];

        return [
            self::COMPANY_ADMIN => [
                'description' => 'Full access to all modules and company administration.',
                'permissions' => ['*', 'dashboard.admin', 'scrap.manage_master', 'masters.scrap_categories.*', 'masters.scrap_types.*'],
            ],
            self::DIRECTOR => [
                'description' => 'Views everything and gives final approvals.',
                'permissions' => $directorPermissions,
            ],
            self::MANAGEMENT => [
                'description' => 'Executive oversight with final approval authority (MD portfolio dashboard).',
                'permissions' => $managementPermissions,
            ],
            self::PROJECT_MANAGER => [
                'description' => 'Runs assigned projects: planning, site, approvals.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'projects.update', 'projects.manage_team', 'projects.import', 'sites.*',
                    'boq.view', 'boq.create', 'boq.update', 'boq.submit', 'boq.import', 'boq.view_costs', 'boq.manage_cost',
                    'boq.view_material_requirements', 'boq.manage_material_norms', 'boq.manage_recipes', 'boq.manage_unit_conversions', 'boq.manage_steel_profiles', 'boq.manage_rate_library',
                    'boq.view_bbs', 'boq.manage_bbs', 'boq.manage_bbs_adjustments', 'boq.approve_bbs', 'boq.view_reconciliation',
                    'rate_analysis.view',
                    'budget.view', 'planning.*', 'site_diary.*', 'dpr.*', 'material_requests.*', 'purchase.view',
                    'grn.view', 'grn.approve', 'inventory.view', 'labour.*', 'subcontract.view', 'subcontract.create',
                    'subcontract.update', 'subcontract.delete', 'subcontract.certify_bill', 'subcontract.export', 'equipment.view',
                    'equipment.update', 'equipment.assign', 'equipment.log_usage', 'equipment.import',
                    'gate_pass.view', 'gate_pass.create', 'gate_pass.approve',
                    'scrap.view', 'scrap.create', 'scrap.update', 'scrap.submit',
                    'utilities.view', 'utilities.electricity.view', 'utilities.electricity.enter_readings',
                    'utilities.electricity.create_bill', 'utilities.electricity.view_costs',
                    'expenses.view', 'expenses.approve', 'billing.view',
                    'billing.submit', 'quality.*', 'drawings.*', 'documents.*', 'reports.view', 'ai.use', ...$viewMasters,
                ],
            ],
            self::SITE_ENGINEER => [
                'description' => 'Daily site work on assigned projects.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'sites.view', 'boq.view', 'boq.view_material_requirements', 'boq.view_bbs', 'boq.view_reconciliation',
                    'planning.view',
                    'planning.update_progress', 'site_diary.view', 'site_diary.create', 'site_diary.update',
                    'site_diary.submit', 'dpr.view', 'dpr.create', 'dpr.update', 'dpr.submit',
                    'material_requests.view', 'material_requests.create', 'material_requests.update', 'material_requests.import',
                    'material_requests.submit', 'inventory.view', 'labour.view', 'labour.create', 'labour.update', 'labour.import', 'labour.mark_attendance',
                    'equipment.view', 'equipment.log_usage', 'gate_pass.view', 'gate_pass.create', 'gate_pass.verify', 'scrap.view',
                    'utilities.view', 'utilities.electricity.view', 'utilities.electricity.enter_readings',
                    'expenses.view', 'expenses.create', 'expenses.import', 'expenses.submit',
                    'quality.view', 'quality.create_inspection', 'quality.raise_ncr', 'drawings.view', 'documents.view',
                    'ai.use', ...$viewMasters,
                ],
            ],
            self::PURCHASE_MANAGER => [
                'description' => 'Procurement from material request to purchase order.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'ai.use', 'boq.view_material_requirements', 'boq.view_bbs', 'boq.view_reconciliation',
                    'material_requests.view', 'material_requests.approve',
                    'rfq.*', 'vendor_quotations.*', 'bid_comparison.view', 'bid_comparison.create', 'purchase.view',
                    'purchase.create', 'purchase.update', 'purchase.submit', 'purchase.export', 'purchase.import', 'grn.view',
                    'gate_pass.view', 'scrap.view',
                    'inventory.view', 'reports.view', 'masters.items.*', 'masters.vendors.*', 'masters.units.view',
                    'masters.categories.*', 'masters.tax_rates.view', 'masters.warehouses.view',
                ],
            ],
            self::STORE_MANAGER => [
                'description' => 'Receipts, stock movements and issues.',
                'permissions' => $storeManagerPermissions,
            ],
            self::STORE_INCHARGE => [
                'description' => 'Site store receipts, issues and gate verification (mirror of Store Manager).',
                'permissions' => $storeManagerPermissions,
            ],
            self::ACCOUNTANT => [
                'description' => 'Expenses, bills, payments and finance reports.',
                'permissions' => [
                    'dashboard.view', 'dashboard.view_financials', 'approvals.view', 'projects.view', 'projects.view_all',
                    'expenses.*', 'petty_cash.*', 'billing.view', 'vendor_bills.*', 'payments.view', 'payments.record',
                    'subcontract.view', 'subcontract.process', 'labour.view', 'labour.manage_payments', 'equipment.view', 'purchase.view', 'grn.view',
                    'gate_pass.view', 'scrap.view',
                    'utilities.view', 'utilities.electricity.*',
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
                    'rate_analysis.*', 'budget.view',
                    'billing.view', 'billing.create', 'billing.update', 'billing.submit', 'billing.export',
                    'subcontract.view', 'subcontract.create', 'subcontract.update', 'subcontract.export', 'dpr.view', 'planning.view',
                    'gate_pass.view', 'scrap.view',
                    'reports.view', ...$viewMasters,
                ],
            ],
            self::QUALITY_ENGINEER => [
                'description' => 'Inspections, checklists and NCRs.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'quality.*', 'drawings.view', 'documents.view',
                    'planning.view', 'boq.view', 'gate_pass.view', 'scrap.view', 'reports.view', ...$viewMasters,
                ],
            ],
            self::BOQ_PREP => [
                'description' => 'BOQ preparation, rate build-up and drawing uploads.',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view', 'drawings.*',
                    'boq.view', 'boq.create', 'boq.update', 'boq.submit', 'boq.import', 'boq.export',
                    'boq.view_costs', 'boq.manage_cost', 'boq.view_material_requirements',
                    'boq.manage_material_norms', 'boq.manage_recipes', 'boq.manage_unit_conversions',
                    'boq.manage_steel_profiles', 'boq.manage_rate_library', 'boq.view_bbs', 'boq.manage_bbs',
                    'boq.manage_bbs_adjustments', 'boq.view_reconciliation',
                    'rate_analysis.*', 'budget.view', 'gate_pass.view', 'scrap.view', 'reports.view', ...$viewMasters,
                ],
            ],
            self::CIVIL_LEAD => [
                'description' => 'Head-office civil reviewer (BOQ commercial approval, subcontract bills, quality and drawings).',
                'permissions' => [
                    'dashboard.view', 'approvals.view', 'projects.view',
                    'boq.view', 'boq.approve', 'boq.view_costs', 'boq.view_material_requirements', 'boq.view_bbs', 'boq.view_reconciliation',
                    'subcontract.view', 'subcontract.certify_bill', 'vendor_bills.view', 'quality.view', 'drawings.view',
                    'gate_pass.view', 'scrap.view',
                    'reports.view', ...$viewMasters,
                ],
            ],
        ];
    }
}
