/**
 * Project workspace tabs. Permission checks stay on each item. Keys are unchanged
 * so existing `active` props keep working. Site Diary is intentionally omitted.
 */
export function buildProjectTabs({ projectId, can, procurementHome = null, financeHome = null, advancedBoq = false }) {
    return [
        { key: 'overview', label: 'Dashboard', icon: 'home', href: route('projects.show', projectId), group: null },
        can('sites.view') && { key: 'sites', label: 'Sites', icon: 'map-pin', href: route('projects.sites.index', projectId), group: null },
        { key: 'team', label: 'Team', icon: 'users', href: route('projects.team.index', projectId), group: null },

        can('drawings.view') && { key: 'drawings', label: 'Drawings', icon: 'grid', href: route('projects.drawings.index', projectId), group: null },
        can('boq.view') && { key: 'boq', label: 'BOQ', icon: 'document', href: route('projects.boqs.index', projectId), group: null },
        advancedBoq && can('boq.view_material_requirements') && {
            key: 'material-requirement',
            label: 'Material Requirement',
            icon: 'archive',
            href: route('projects.material-requirement.index', projectId),
            group: null,
        },
        advancedBoq && can('boq.view_reconciliation') && {
            key: 'material-reconciliation',
            label: 'Material Reconciliation',
            icon: 'scale',
            href: route('projects.material-reconciliation.index', projectId),
            group: null,
        },
        (advancedBoq ? can('boq.view_reconciliation') : can('reports.view')) && {
            key: 'material-control',
            label: 'Material Control',
            icon: 'scale',
            href: route('projects.material-control.index', projectId),
            group: null,
        },
        advancedBoq && can('boq.view_reconciliation') && {
            key: 'steel-reconciliation',
            label: 'Steel Reconciliation',
            icon: 'scale',
            href: route('projects.steel-reconciliation.index', projectId),
            group: null,
        },
        advancedBoq && can('rate_analysis.view') &&
            can('boq.view_costs') && {
                key: 'rate-analysis',
                label: 'Rate Analysis',
                icon: 'scale',
                href: route('projects.rate-analyses.index', projectId),
                group: null,
            },
        can('budget.view') && can('boq.view_costs') && {
            key: 'budget',
            label: 'Budget',
            icon: 'banknotes',
            href: route('projects.budget.index', projectId),
            group: null,
        },
        can('budget.view') && can('dashboard.view_financials') && {
            key: 'cost-control',
            label: 'Cost Control',
            icon: 'scale',
            href: route('projects.cost-control.index', projectId),
            group: null,
        },

        can('planning.view') && {
            key: 'planning',
            label: 'Planning',
            icon: 'calendar',
            href: route('projects.planning.tasks.index', projectId),
            group: null,
        },
        can('dpr.view') && {
            key: 'dprs',
            label: 'DPR',
            icon: 'clipboard',
            href: route('projects.dprs.index', projectId),
            group: null,
        },
        can('planning.view') && {
            key: 'progress',
            label: 'Progress',
            icon: 'check-circle',
            href: route('projects.progress', projectId),
            group: null,
        },

        procurementHome && { key: 'procurement', label: 'Procurement', icon: 'truck', href: procurementHome, group: null },
        can('inventory.view') && {
            key: 'inventory',
            label: 'Inventory',
            icon: 'archive',
            href: route('projects.inventory.index', projectId),
            group: null,
        },

        can('subcontract.view') && {
            key: 'subcontract',
            label: 'Subcontract',
            icon: 'briefcase',
            href: route('projects.work-orders.index', projectId),
            group: null,
        },
        financeHome && { key: 'finance', label: 'Finance', icon: 'banknotes', href: financeHome, group: null },

        can('labour.view') && {
            key: 'labour',
            label: 'Labour',
            icon: 'users',
            href: route('projects.attendance.index', projectId),
            group: null,
        },
        can('equipment.view') && {
            key: 'equipment',
            label: 'Equipment',
            icon: 'wrench',
            href: route('projects.equipment-assignments.index', projectId),
            group: null,
        },
        can('quality.view') && {
            key: 'quality',
            label: 'Quality',
            icon: 'check-circle',
            href: route('projects.inspections.index', projectId),
            group: null,
        },
        can('gate_pass.view') && {
            key: 'gate',
            label: 'Gate Pass',
            icon: 'truck',
            href: route('projects.gate-passes.index', projectId),
            group: null,
        },
        can('scrap.view') && {
            key: 'scrap',
            label: 'Scrap',
            icon: 'archive',
            href: route('projects.scrap.generations.index', projectId),
            group: null,
        },
        can('documents.view') && {
            key: 'documents',
            label: 'Documents',
            icon: 'folder',
            href: route('projects.documents.index', projectId),
            group: null,
        },
        can('reports.view') && {
            key: 'reports',
            label: 'Reports',
            icon: 'document',
            href: route('reports.project-index', projectId),
            group: null,
        },
    ].filter(Boolean);
}
