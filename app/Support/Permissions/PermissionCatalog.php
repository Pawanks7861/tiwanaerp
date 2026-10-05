<?php

namespace App\Support\Permissions;

/**
 * Single source of truth for permission names (architecture M.2). Names are "{module}.{action}".
 * Permissions are global; roles that hold them are per company.
 */
final class PermissionCatalog
{
    private const STANDARD = ['view', 'create', 'update', 'delete'];

    /**
     * @return array<string, array{label: string, group: string, actions: list<string>}>
     */
    public static function modules(): array
    {
        $std = self::STANDARD;

        return [
            'dashboard' => ['label' => 'Dashboard', 'group' => 'General', 'actions' => ['view', 'view_financials']],
            'approvals' => ['label' => 'Approvals Inbox', 'group' => 'General', 'actions' => ['view']],

            'projects' => ['label' => 'Projects', 'group' => 'Projects', 'actions' => [...$std, 'view_all', 'manage_team', 'close']],
            'sites' => ['label' => 'Sites', 'group' => 'Projects', 'actions' => $std],
            'boq' => ['label' => 'BOQ', 'group' => 'Projects', 'actions' => [...$std, 'submit', 'approve', 'revise', 'import', 'export', 'view_costs']],
            'rate_analysis' => ['label' => 'Rate Analysis', 'group' => 'Projects', 'actions' => [...$std, 'approve']],
            'budget' => ['label' => 'Budget', 'group' => 'Projects', 'actions' => ['view', 'update', 'approve']],
            'planning' => ['label' => 'Planning', 'group' => 'Projects', 'actions' => [...$std, 'update_progress']],
            'site_diary' => ['label' => 'Site Diary', 'group' => 'Site', 'actions' => [...$std, 'submit', 'review', 'approve']],
            'dpr' => ['label' => 'DPR', 'group' => 'Site', 'actions' => ['view', 'create', 'update', 'submit', 'approve', 'export']],

            'crm.leads' => ['label' => 'Leads', 'group' => 'CRM', 'actions' => $std],
            'crm.clients' => ['label' => 'Clients', 'group' => 'CRM', 'actions' => $std],
            'crm.quotations' => ['label' => 'Quotations', 'group' => 'CRM', 'actions' => [...$std, 'approve', 'convert']],

            'material_requests' => ['label' => 'Material Requests', 'group' => 'Procurement', 'actions' => [...$std, 'submit', 'approve']],
            'rfq' => ['label' => 'RFQ', 'group' => 'Procurement', 'actions' => [...$std, 'send']],
            'vendor_quotations' => ['label' => 'Vendor Quotations', 'group' => 'Procurement', 'actions' => $std],
            'bid_comparison' => ['label' => 'Bid Comparison', 'group' => 'Procurement', 'actions' => ['view', 'create', 'approve']],
            'purchase' => ['label' => 'Purchase Orders', 'group' => 'Procurement', 'actions' => [...$std, 'submit', 'approve', 'amend', 'cancel', 'export']],
            'grn' => ['label' => 'GRN', 'group' => 'Procurement', 'actions' => [...$std, 'submit', 'approve']],

            'inventory' => ['label' => 'Inventory', 'group' => 'Inventory', 'actions' => ['view', 'issue', 'transfer', 'return', 'adjust', 'approve_adjustment', 'view_valuation']],

            'labour' => ['label' => 'Labour', 'group' => 'Resources', 'actions' => [...$std, 'mark_attendance', 'approve_attendance', 'manage_payments']],
            'subcontract' => ['label' => 'Subcontract', 'group' => 'Resources', 'actions' => [...$std, 'approve_wo', 'certify_bill']],
            'equipment' => ['label' => 'Equipment', 'group' => 'Resources', 'actions' => [...$std, 'assign', 'log_usage']],

            'expenses' => ['label' => 'Expenses', 'group' => 'Finance', 'actions' => [...$std, 'submit', 'approve']],
            'petty_cash' => ['label' => 'Petty Cash', 'group' => 'Finance', 'actions' => ['view', 'fund', 'spend']],
            'billing' => ['label' => 'Client Billing', 'group' => 'Finance', 'actions' => [...$std, 'submit', 'certify', 'override_qty', 'export']],
            'vendor_bills' => ['label' => 'Vendor Bills', 'group' => 'Finance', 'actions' => [...$std, 'approve']],
            'payments' => ['label' => 'Payments', 'group' => 'Finance', 'actions' => ['view', 'record', 'approve']],

            'quality' => ['label' => 'Quality', 'group' => 'Quality & Documents', 'actions' => ['view', 'create_inspection', 'perform_inspection', 'raise_ncr', 'close_ncr']],
            'drawings' => ['label' => 'Drawings', 'group' => 'Quality & Documents', 'actions' => ['view', 'upload', 'review', 'approve']],
            'documents' => ['label' => 'Documents', 'group' => 'Quality & Documents', 'actions' => ['view', 'upload', 'delete', 'manage_folders']],

            'reports' => ['label' => 'Reports', 'group' => 'Reports', 'actions' => ['view', 'view_financial', 'export']],

            'masters.items' => ['label' => 'Items', 'group' => 'Masters', 'actions' => $std],
            'masters.units' => ['label' => 'Units', 'group' => 'Masters', 'actions' => $std],
            'masters.categories' => ['label' => 'Categories', 'group' => 'Masters', 'actions' => $std],
            'masters.tax_rates' => ['label' => 'Tax Rates', 'group' => 'Masters', 'actions' => $std],
            'masters.vendors' => ['label' => 'Vendors', 'group' => 'Masters', 'actions' => $std],
            'masters.subcontractors' => ['label' => 'Subcontractors', 'group' => 'Masters', 'actions' => $std],
            'masters.labour_trades' => ['label' => 'Labour Trades', 'group' => 'Masters', 'actions' => $std],
            'masters.equipment_types' => ['label' => 'Equipment Types', 'group' => 'Masters', 'actions' => $std],
            'masters.warehouses' => ['label' => 'Warehouses', 'group' => 'Masters', 'actions' => $std],
            'masters.expense_categories' => ['label' => 'Expense Categories', 'group' => 'Masters', 'actions' => $std],

            'admin.users' => ['label' => 'Users', 'group' => 'Administration', 'actions' => ['view', 'manage']],
            'admin.roles' => ['label' => 'Roles & Permissions', 'group' => 'Administration', 'actions' => ['view', 'manage']],
            'admin.workflows' => ['label' => 'Approval Workflows', 'group' => 'Administration', 'actions' => ['view', 'manage']],
            'admin.settings' => ['label' => 'Company Settings', 'group' => 'Administration', 'actions' => ['view', 'manage']],
            'admin.audit_logs' => ['label' => 'Audit Logs', 'group' => 'Administration', 'actions' => ['view']],
            'tally' => ['label' => 'TallyPrime', 'group' => 'Administration', 'actions' => ['view', 'manage', 'sync', 'retry', 'mapping']],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];
        foreach (self::modules() as $module => $definition) {
            foreach ($definition['actions'] as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        return $names;
    }

    /**
     * Expand patterns such as "masters.*.view" or "boq.*" into concrete permission names.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $all = self::all();
        $result = [];

        foreach ($patterns as $pattern) {
            if (! str_contains($pattern, '*')) {
                $result[] = $pattern;

                continue;
            }
            $regex = '/^'.str_replace('\*', '[^.]+(\.[^.]+)*', preg_quote($pattern, '/')).'$/';
            foreach ($all as $name) {
                if (preg_match($regex, $name)) {
                    $result[] = $name;
                }
            }
        }

        return array_values(array_unique(array_intersect($result, $all)));
    }
}
