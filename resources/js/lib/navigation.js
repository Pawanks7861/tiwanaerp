/**
 * Sidebar definition. Items are shown only when the user holds `permission`
 * (super admins receive every permission). Modules of later phases are added here as they ship.
 */
export const masters = [
    { slug: 'items', label: 'Items', icon: 'cube', permission: 'masters.items.view' },
    { slug: 'units', label: 'Units', icon: 'scale', permission: 'masters.units.view' },
    { slug: 'categories', label: 'Categories', icon: 'tag', permission: 'masters.categories.view' },
    { slug: 'tax-rates', label: 'Tax Rates', icon: 'receipt', permission: 'masters.tax_rates.view' },
    { slug: 'vendors', label: 'Vendors', icon: 'truck', permission: 'masters.vendors.view' },
    { slug: 'subcontractors', label: 'Subcontractors', icon: 'wrench', permission: 'masters.subcontractors.view' },
    { slug: 'clients', label: 'Clients', icon: 'briefcase', permission: 'crm.clients.view' },
    { slug: 'labour', label: 'Labour Register', icon: 'users', permission: 'labour.view' },
    { slug: 'labour-trades', label: 'Labour Trades', icon: 'id', permission: 'masters.labour_trades.view' },
    { slug: 'equipment', label: 'Equipment Register', icon: 'wrench', permission: 'equipment.view' },
    { slug: 'equipment-types', label: 'Equipment Types', icon: 'cog', permission: 'masters.equipment_types.view' },
    { slug: 'warehouses', label: 'Warehouses', icon: 'archive', permission: 'masters.warehouses.view' },
    { slug: 'expense-categories', label: 'Expense Categories', icon: 'banknotes', permission: 'masters.expense_categories.view' },
];

export function buildNavigation({ can, isSuperAdmin }) {
    const sections = [
        {
            items: [
                { label: 'Dashboard', icon: 'home', href: route('dashboard'), active: route().current('dashboard') },
                can('projects.view') && {
                    label: 'Projects',
                    icon: 'building',
                    href: route('projects.index'),
                    active: route().current('projects.*'),
                },
                can('approvals.view') && {
                    label: 'Approvals',
                    icon: 'check-circle',
                    href: route('approvals.index'),
                    active: route().current('approvals.*'),
                },
                can('reports.view') && {
                    label: 'Reports',
                    icon: 'document',
                    href: route('reports.index'),
                    active: route().current('reports.*'),
                },
                {
                    label: 'Chat',
                    icon: 'chat',
                    href: route('chat.index'),
                    active: route().current('chat.*'),
                },
            ],
        },
        {
            title: 'CRM',
            items: [
                can('crm.leads.view') && {
                    label: 'Leads',
                    icon: 'users',
                    href: route('crm.leads.index'),
                    active: route().current('crm.leads.*'),
                },
                can('crm.quotations.view') && {
                    label: 'Quotations',
                    icon: 'document',
                    href: route('crm.quotations.index'),
                    active: route().current('crm.quotations.*'),
                },
            ],
        },
        {
            title: 'Finance',
            items: [
                can('payments.view') && {
                    label: 'Cash Flow & Outstanding',
                    icon: 'banknotes',
                    href: route('finance.cash-flow'),
                    active: route().current('finance.*'),
                },
            ],
        },
        {
            title: 'Masters',
            items: [
                ...masters
                    .filter((m) => can(m.permission))
                    .map((m) => ({
                        label: m.label,
                        icon: m.icon,
                        href: route('masters.index', m.slug),
                        active: route().current('masters.*', { master: m.slug }),
                    })),
                can('quality.view') && {
                    label: 'Quality Checklists',
                    icon: 'check-circle',
                    href: route('quality.checklists.index'),
                    active: route().current('quality.checklists.*'),
                },
            ],
        },
        {
            title: 'Administration',
            items: [
                can('admin.users.view') && {
                    label: 'Users',
                    icon: 'users',
                    href: route('admin.users.index'),
                    active: route().current('admin.users.*'),
                },
                can('admin.roles.view') && {
                    label: 'Roles & Permissions',
                    icon: 'shield',
                    href: route('admin.roles.index'),
                    active: route().current('admin.roles.*'),
                },
                can('admin.settings.view') && {
                    label: 'Company Settings',
                    icon: 'cog',
                    href: route('admin.company.edit'),
                    active: route().current('admin.company.*'),
                },
                can('admin.audit_logs.view') && {
                    label: 'Audit Logs',
                    icon: 'clipboard',
                    href: route('admin.audit-logs.index'),
                    active: route().current('admin.audit-logs.*'),
                },
            ],
        },
        isSuperAdmin && {
            title: 'Platform',
            items: [
                {
                    label: 'Companies',
                    icon: 'globe',
                    href: route('platform.companies.index'),
                    active: route().current('platform.companies.*'),
                },
            ],
        },
    ];

    return sections
        .filter(Boolean)
        .map((s) => ({ ...s, items: s.items.filter(Boolean) }))
        .filter((s) => s.items.length > 0);
}
