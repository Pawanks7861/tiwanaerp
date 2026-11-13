/**
 * Project workspace tabs. Order follows the construction workflow:
 * Dashboard → Drawings → BOQ → Planning & Budget → Procurement → Inventory → Site Execution.
 * Permission checks stay on each item; keys are unchanged so existing `active` props keep working.
 */
export function buildProjectTabs({ projectId, can, procurementHome = null, financeHome = null, advancedBoq = false }) {
    return [
        { key: 'overview', label: 'Dashboard', icon: 'home', href: route('projects.show', projectId) },
        can('sites.view') && { key: 'sites', label: 'Sites', icon: 'map-pin', href: route('projects.sites.index', projectId) },
        { key: 'team', label: 'Team', icon: 'users', href: route('projects.team.index', projectId) },

        can('drawings.view') && { key: 'drawings', label: 'Drawing Management', icon: 'grid', href: route('projects.drawings.index', projectId) },

        can('boq.view') && { key: 'boq', label: 'BOQ', icon: 'document', href: route('projects.boqs.index', projectId) },
        advancedBoq && can('boq.view_material_requirements') && {
            key: 'material-requirement',
            label: 'Material Requirement',
            icon: 'archive',
            href: route('projects.material-requirement.index', projectId),
        },
        advancedBoq && can('boq.view_reconciliation') && {
            key: 'material-reconciliation',
            label: 'Material Reconciliation',
            icon: 'scale',
            href: route('projects.material-reconciliation.index', projectId),
        },
        advancedBoq && can('boq.view_reconciliation') && {
            key: 'steel-reconciliation',
            label: 'Steel Reconciliation',
            icon: 'scale',
            href: route('projects.steel-reconciliation.index', projectId),
        },
        advancedBoq && can('rate_analysis.view') &&
            can('boq.view_costs') && { key: 'rate-analysis', label: 'Rate Analysis', icon: 'scale', href: route('projects.rate-analyses.index', projectId) },

        can('budget.view') && can('boq.view_costs') && { key: 'budget', label: 'Budget', icon: 'banknotes', href: route('projects.budget.index', projectId) },
        can('planning.view') && { key: 'planning', label: 'Planning', icon: 'calendar', href: route('projects.planning.tasks.index', projectId) },

        procurementHome && { key: 'procurement', label: 'Procurement', icon: 'truck', href: procurementHome },
        can('inventory.view') && { key: 'inventory', label: 'Inventory', icon: 'archive', href: route('projects.inventory.index', projectId) },

        can('dpr.view') && { key: 'dprs', label: 'DPR', icon: 'clipboard', href: route('projects.dprs.index', projectId) },
        can('planning.view') && { key: 'progress', label: 'Progress', icon: 'check-circle', href: route('projects.progress', projectId) },

        can('labour.view') && { key: 'labour', label: 'Labour', icon: 'users', href: route('projects.attendance.index', projectId) },
        can('subcontract.view') && { key: 'subcontract', label: 'Subcontract', icon: 'briefcase', href: route('projects.work-orders.index', projectId) },
        can('equipment.view') && { key: 'equipment', label: 'Equipment', icon: 'wrench', href: route('projects.equipment-assignments.index', projectId) },
        can('gate_pass.view') && { key: 'gate', label: 'Gate Pass', icon: 'truck', href: route('projects.gate-passes.index', projectId) },
        can('scrap.view') && { key: 'scrap', label: 'Scrap', icon: 'archive', href: route('projects.scrap.generations.index', projectId) },
        financeHome && { key: 'finance', label: 'Finance', icon: 'banknotes', href: financeHome },
        can('quality.view') && { key: 'quality', label: 'Quality', icon: 'check-circle', href: route('projects.inspections.index', projectId) },
        can('documents.view') && { key: 'documents', label: 'Documents', icon: 'folder', href: route('projects.documents.index', projectId) },
        can('reports.view') && { key: 'reports', label: 'Reports', icon: 'document', href: route('reports.project-index', projectId) },
    ].filter(Boolean);
}
