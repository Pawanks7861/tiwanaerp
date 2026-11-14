<?php

namespace App\Support\Navigation;

/**
 * Stable sidebar keys for per-user menu allow-lists. Keep in sync with
 * resources/js/lib/navigation.js (navigationCatalog / item.key).
 */
final class SidebarCatalog
{
    /**
     * @return list<array{group: string, items: list<array{key: string, label: string}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'group' => 'General',
                'items' => [
                    ['key' => 'dashboard', 'label' => 'Dashboard'],
                    ['key' => 'projects', 'label' => 'Projects'],
                    ['key' => 'approvals', 'label' => 'Approvals'],
                    ['key' => 'reports', 'label' => 'Reports'],
                    ['key' => 'chat', 'label' => 'Chat'],
                ],
            ],
            [
                'group' => 'CRM',
                'items' => [
                    ['key' => 'crm.leads', 'label' => 'Leads'],
                    ['key' => 'crm.quotations', 'label' => 'Quotations'],
                ],
            ],
            [
                'group' => 'Finance',
                'items' => [
                    ['key' => 'finance.cash-flow', 'label' => 'Cash Flow & Outstanding'],
                ],
            ],
            [
                'group' => 'Operations',
                'items' => [
                    ['key' => 'gate_passes', 'label' => 'Gate Passes'],
                    ['key' => 'scrap', 'label' => 'Scrap Management'],
                ],
            ],
            [
                'group' => 'Utilities',
                'items' => [
                    ['key' => 'utilities.electricity', 'label' => 'Electricity'],
                ],
            ],
            [
                'group' => 'Masters',
                'items' => [
                    ['key' => 'masters.items', 'label' => 'Items'],
                    ['key' => 'masters.units', 'label' => 'Units'],
                    ['key' => 'masters.categories', 'label' => 'Categories'],
                    ['key' => 'masters.tax-rates', 'label' => 'Tax Rates'],
                    ['key' => 'masters.vendors', 'label' => 'Vendors'],
                    ['key' => 'masters.subcontractors', 'label' => 'Subcontractors'],
                    ['key' => 'masters.clients', 'label' => 'Clients'],
                    ['key' => 'masters.labour', 'label' => 'Labour Register'],
                    ['key' => 'masters.labour-trades', 'label' => 'Labour Trades'],
                    ['key' => 'masters.equipment', 'label' => 'Equipment Register'],
                    ['key' => 'masters.equipment-types', 'label' => 'Equipment Types'],
                    ['key' => 'masters.warehouses', 'label' => 'Warehouses'],
                    ['key' => 'masters.scrap-categories', 'label' => 'Scrap Categories'],
                    ['key' => 'masters.scrap-types', 'label' => 'Scrap Types'],
                    ['key' => 'masters.expense-categories', 'label' => 'Expense Categories'],
                    ['key' => 'quality.checklists', 'label' => 'Quality Checklists'],
                    ['key' => 'coefficients.recipes', 'label' => 'Material Recipes'],
                    ['key' => 'coefficients.conversions', 'label' => 'Unit Conversions'],
                    ['key' => 'coefficients.steel-profiles', 'label' => 'Steel Unit Weight Profiles'],
                    ['key' => 'coefficients.rate-library', 'label' => 'Rate Library'],
                ],
            ],
            [
                'group' => 'Administration',
                'items' => [
                    ['key' => 'admin.users', 'label' => 'Users'],
                    ['key' => 'admin.roles', 'label' => 'Roles & Permissions'],
                    ['key' => 'admin.workflows', 'label' => 'Approval Workflows'],
                    ['key' => 'admin.company', 'label' => 'Company Settings'],
                    ['key' => 'admin.document-terms', 'label' => 'Document Terms'],
                    ['key' => 'integrations.tally', 'label' => 'TallyPrime'],
                    ['key' => 'admin.audit-logs', 'label' => 'Audit Logs'],
                    ['key' => 'imports.history', 'label' => 'Import History'],
                ],
            ],
            [
                'group' => 'Platform',
                'items' => [
                    ['key' => 'platform.companies', 'label' => 'Companies'],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /**
     * Keys that always appear even when a menu allow-list is active.
     *
     * @return list<string>
     */
    public static function alwaysVisible(): array
    {
        return ['dashboard'];
    }
}
