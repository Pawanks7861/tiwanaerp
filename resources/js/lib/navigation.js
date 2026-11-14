/**
 * Sidebar definition. Items are shown only when the user holds `permission`
 * (super admins receive every permission). Optional `menu_items` allow-list
 * further trims visibility after permission checks.
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
    { slug: 'scrap-categories', label: 'Scrap Categories', icon: 'tag', permission: 'masters.scrap_categories.view' },
    { slug: 'scrap-types', label: 'Scrap Types', icon: 'cube', permission: 'masters.scrap_types.view' },
    { slug: 'expense-categories', label: 'Expense Categories', icon: 'banknotes', permission: 'masters.expense_categories.view' },
];

/** Keys that always remain visible when a menu allow-list is active. */
export const ALWAYS_VISIBLE_MENU_KEYS = ['dashboard'];

/**
 * Catalog for Admin → Users sidebar picker (keep keys in sync with SidebarCatalog.php).
 *
 * @returns {list<{group: string, items: list<{key: string, label: string}>}>}
 */
export function navigationCatalog() {
    return [
        {
            group: 'General',
            items: [
                { key: 'dashboard', label: 'Dashboard' },
                { key: 'projects', label: 'Projects' },
                { key: 'approvals', label: 'Approvals' },
                { key: 'reports', label: 'Reports' },
                { key: 'chat', label: 'Chat' },
            ],
        },
        {
            group: 'CRM',
            items: [
                { key: 'crm.leads', label: 'Leads' },
                { key: 'crm.quotations', label: 'Quotations' },
            ],
        },
        {
            group: 'Finance',
            items: [{ key: 'finance.cash-flow', label: 'Cash Flow & Outstanding' }],
        },
        {
            group: 'Operations',
            items: [
                { key: 'gate_passes', label: 'Gate Passes' },
                { key: 'scrap', label: 'Scrap Management' },
            ],
        },
        {
            group: 'Utilities',
            items: [{ key: 'utilities.electricity', label: 'Electricity' }],
        },
        {
            group: 'Masters',
            items: [
                ...masters.map((m) => ({ key: `masters.${m.slug}`, label: m.label })),
                { key: 'quality.checklists', label: 'Quality Checklists' },
                { key: 'coefficients.recipes', label: 'Material Recipes' },
                { key: 'coefficients.conversions', label: 'Unit Conversions' },
                { key: 'coefficients.steel-profiles', label: 'Steel Unit Weight Profiles' },
                { key: 'coefficients.rate-library', label: 'Rate Library' },
            ],
        },
        {
            group: 'Administration',
            items: [
                { key: 'admin.users', label: 'Users' },
                { key: 'admin.roles', label: 'Roles & Permissions' },
                { key: 'admin.workflows', label: 'Approval Workflows' },
                { key: 'admin.company', label: 'Company Settings' },
                { key: 'admin.document-terms', label: 'Document Terms' },
                { key: 'integrations.tally', label: 'TallyPrime' },
                { key: 'admin.audit-logs', label: 'Audit Logs' },
                { key: 'imports.history', label: 'Import History' },
            ],
        },
        {
            group: 'Platform',
            items: [{ key: 'platform.companies', label: 'Companies' }],
        },
    ];
}

function menuAllowed(key, menuItems) {
    if (menuItems == null) {
        return true;
    }
    if (ALWAYS_VISIBLE_MENU_KEYS.includes(key)) {
        return true;
    }

    return menuItems.includes(key);
}

export function buildNavigation({ can, isSuperAdmin, multiCompany = false, advancedBoqCalculations = false, menuItems = null }) {
    const sections = [
        {
            items: [
                menuAllowed('dashboard', menuItems) && {
                    key: 'dashboard',
                    label: 'Dashboard',
                    icon: 'home',
                    href: route('dashboard'),
                    active: route().current('dashboard'),
                },
                can('projects.view') &&
                    menuAllowed('projects', menuItems) && {
                        key: 'projects',
                        label: 'Projects',
                        icon: 'building',
                        href: route('projects.index'),
                        active: route().current('projects.*'),
                    },
                can('approvals.view') &&
                    menuAllowed('approvals', menuItems) && {
                        key: 'approvals',
                        label: 'Approvals',
                        icon: 'check-circle',
                        href: route('approvals.index'),
                        active: route().current('approvals.*'),
                    },
                can('reports.view') &&
                    menuAllowed('reports', menuItems) && {
                        key: 'reports',
                        label: 'Reports',
                        icon: 'document',
                        href: route('reports.index'),
                        active: route().current('reports.*'),
                    },
                menuAllowed('chat', menuItems) && {
                    key: 'chat',
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
                can('crm.leads.view') &&
                    menuAllowed('crm.leads', menuItems) && {
                        key: 'crm.leads',
                        label: 'Leads',
                        icon: 'users',
                        href: route('crm.leads.index'),
                        active: route().current('crm.leads.*'),
                    },
                can('crm.quotations.view') &&
                    menuAllowed('crm.quotations', menuItems) && {
                        key: 'crm.quotations',
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
                can('payments.view') &&
                    menuAllowed('finance.cash-flow', menuItems) && {
                        key: 'finance.cash-flow',
                        label: 'Cash Flow & Outstanding',
                        icon: 'banknotes',
                        href: route('finance.cash-flow'),
                        active: route().current('finance.*'),
                    },
            ],
        },
        {
            title: 'Operations',
            items: [
                can('gate_pass.view') &&
                    menuAllowed('gate_passes', menuItems) && {
                        key: 'gate_passes',
                        label: 'Gate Passes',
                        icon: 'truck',
                        href: route('gate-passes.index'),
                        active: route().current('gate-passes.*') || route().current('projects.gate-passes.*'),
                    },
                can('scrap.view') &&
                    menuAllowed('scrap', menuItems) && {
                        key: 'scrap',
                        label: 'Scrap Management',
                        icon: 'archive',
                        href: route('scrap.dashboard'),
                        active: route().current('scrap.*') || route().current('projects.scrap.*'),
                    },
            ],
        },
        {
            title: 'Utilities',
            items: [
                (can('utilities.view') || can('utilities.electricity.view')) &&
                    menuAllowed('utilities.electricity', menuItems) && {
                        key: 'utilities.electricity',
                        label: 'Electricity',
                        icon: 'cog',
                        href: route('utilities.electricity.hub'),
                        active: route().current('utilities.electricity.*'),
                    },
            ],
        },
        {
            title: 'Masters',
            items: [
                ...masters
                    .filter((m) => can(m.permission) || (m.slug.startsWith('scrap-') && can('scrap.manage_master')))
                    .filter((m) => menuAllowed(`masters.${m.slug}`, menuItems))
                    .map((m) => ({
                        key: `masters.${m.slug}`,
                        label: m.label,
                        icon: m.icon,
                        href: route('masters.index', m.slug),
                        active: route().current('masters.*', { master: m.slug }),
                    })),
                can('quality.view') &&
                    menuAllowed('quality.checklists', menuItems) && {
                        key: 'quality.checklists',
                        label: 'Quality Checklists',
                        icon: 'check-circle',
                        href: route('quality.checklists.index'),
                        active: route().current('quality.checklists.*'),
                    },
                advancedBoqCalculations &&
                    can('boq.manage_recipes') &&
                    menuAllowed('coefficients.recipes', menuItems) && {
                        key: 'coefficients.recipes',
                        label: 'Material Recipes',
                        icon: 'beaker',
                        href: route('coefficients.recipes.index'),
                        active: route().current('coefficients.recipes.*'),
                    },
                advancedBoqCalculations &&
                    can('boq.manage_unit_conversions') &&
                    menuAllowed('coefficients.conversions', menuItems) && {
                        key: 'coefficients.conversions',
                        label: 'Unit Conversions',
                        icon: 'scale',
                        href: route('coefficients.conversions.index'),
                        active: route().current('coefficients.conversions.*'),
                    },
                advancedBoqCalculations &&
                    can('boq.manage_steel_profiles') &&
                    menuAllowed('coefficients.steel-profiles', menuItems) && {
                        key: 'coefficients.steel-profiles',
                        label: 'Steel Unit Weight Profiles',
                        icon: 'wrench',
                        href: route('coefficients.steel-profiles.index'),
                        active: route().current('coefficients.steel-profiles.*'),
                    },
                advancedBoqCalculations &&
                    can('boq.manage_rate_library') &&
                    menuAllowed('coefficients.rate-library', menuItems) && {
                        key: 'coefficients.rate-library',
                        label: 'Rate Library',
                        icon: 'banknotes',
                        href: route('coefficients.rate-library.index'),
                        active: route().current('coefficients.rate-library.*'),
                    },
            ],
        },
        {
            title: 'Administration',
            items: [
                can('admin.users.view') &&
                    menuAllowed('admin.users', menuItems) && {
                        key: 'admin.users',
                        label: 'Users',
                        icon: 'users',
                        href: route('admin.users.index'),
                        active: route().current('admin.users.*'),
                    },
                can('admin.roles.view') &&
                    menuAllowed('admin.roles', menuItems) && {
                        key: 'admin.roles',
                        label: 'Roles & Permissions',
                        icon: 'shield',
                        href: route('admin.roles.index'),
                        active: route().current('admin.roles.*'),
                    },
                can('admin.workflows.view') &&
                    menuAllowed('admin.workflows', menuItems) && {
                        key: 'admin.workflows',
                        label: 'Approval Workflows',
                        icon: 'check-circle',
                        href: route('admin.workflows.index'),
                        active: route().current('admin.workflows.*'),
                    },
                can('admin.settings.view') &&
                    menuAllowed('admin.company', menuItems) && {
                        key: 'admin.company',
                        label: 'Company Settings',
                        icon: 'cog',
                        href: route('admin.company.edit'),
                        active: route().current('admin.company.*'),
                    },
                can('admin.settings.view') &&
                    menuAllowed('admin.document-terms', menuItems) && {
                        key: 'admin.document-terms',
                        label: 'Document Terms',
                        icon: 'document',
                        href: route('admin.document-terms.index'),
                        active: route().current('admin.document-terms.*'),
                    },
                can('tally.view') &&
                    menuAllowed('integrations.tally', menuItems) && {
                        key: 'integrations.tally',
                        label: 'TallyPrime',
                        icon: 'banknotes',
                        href: route('integrations.tally.edit'),
                        active: route().current('integrations.tally.*'),
                    },
                can('admin.audit_logs.view') &&
                    menuAllowed('admin.audit-logs', menuItems) && {
                        key: 'admin.audit-logs',
                        label: 'Audit Logs',
                        icon: 'clipboard',
                        href: route('admin.audit-logs.index'),
                        active: route().current('admin.audit-logs.*'),
                    },
                (can('projects.import') ||
                    can('masters.items.import') ||
                    can('masters.vendors.import') ||
                    can('boq.import') ||
                    can('material_requests.import') ||
                    can('inventory.import') ||
                    can('expenses.import') ||
                    can('labour.import') ||
                    can('planning.import') ||
                    can('quality.import')) &&
                    menuAllowed('imports.history', menuItems) && {
                        key: 'imports.history',
                        label: 'Import History',
                        icon: 'document',
                        href: route('imports.history'),
                        active: route().current('imports.*'),
                    },
            ],
        },
        isSuperAdmin &&
            multiCompany && {
                title: 'Platform',
                items: [
                    menuAllowed('platform.companies', menuItems) && {
                        key: 'platform.companies',
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
