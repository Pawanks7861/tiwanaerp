/**
 * Project workspace tabs. Permission checks stay on each item. Keys are unchanged
 * so existing `active` props keep working. Site Diary is intentionally omitted.
 *
 * Modules with navigable children expose a `children` array for the top-nav dropdown.
 * Status/filter tabs (All / Pending / Approved) stay on their pages — not listed here.
 */

/**
 * @param {{ projectId: number, can: (permission: string) => boolean, rfqEnabled?: boolean }} opts
 */
export function buildProcurementChildren({ projectId, can, rfqEnabled = false }) {
    return [
        can('material_requests.view') && {
            key: 'material-requests',
            label: 'Material Requests',
            icon: 'clipboard',
            href: route('projects.material-requests.index', projectId),
            match: 'projects.material-requests.*',
        },
        rfqEnabled &&
            can('rfq.view') && {
                key: 'rfqs',
                label: 'RFQs',
                icon: 'document',
                href: route('projects.rfqs.index', projectId),
                match: 'projects.rfqs.*',
            },
        can('purchase.view') && {
            key: 'purchase-orders',
            label: 'Purchase Orders',
            icon: 'document',
            href: route('projects.purchase-orders.index', projectId),
            match: 'projects.purchase-orders.*',
        },
        can('grn.view') && {
            key: 'grns',
            label: 'Goods Receipts',
            icon: 'truck',
            href: route('projects.grns.index', projectId),
            match: 'projects.grns.*',
        },
    ].filter(Boolean);
}

/**
 * @param {{ projectId: number, can: (permission: string) => boolean }} opts
 */
export function buildPlanningChildren({ projectId, can }) {
    if (!can('planning.view')) {
        return [];
    }

    return [
        {
            key: 'tasks',
            label: 'Tasks',
            icon: 'clipboard',
            href: route('projects.planning.tasks.index', projectId),
            match: 'projects.planning.tasks.*',
        },
        {
            key: 'gantt',
            label: 'Gantt',
            icon: 'calendar',
            href: route('projects.planning.gantt', projectId),
            match: 'projects.planning.gantt',
        },
        {
            key: 'milestones',
            label: 'Milestones',
            icon: 'check-circle',
            href: route('projects.planning.milestones.index', projectId),
            match: 'projects.planning.milestones.*',
        },
    ];
}

/**
 * @param {{ projectId: number, can: (permission: string) => boolean }} opts
 */
export function buildInventoryChildren({ projectId, can }) {
    if (!can('inventory.view')) {
        return [];
    }

    return [
        { key: 'stock', label: 'Stock', icon: 'archive', href: route('projects.inventory.index', projectId), match: 'projects.inventory.index' },
        { key: 'ledger', label: 'Ledger', icon: 'document', href: route('projects.inventory.ledger', projectId), match: 'projects.inventory.ledger' },
        { key: 'issues', label: 'Issues', icon: 'truck', href: route('projects.material-issues.index', projectId), match: 'projects.material-issues.*' },
        { key: 'transfers', label: 'Transfers', icon: 'truck', href: route('projects.stock-transfers.index', projectId), match: 'projects.stock-transfers.*' },
        { key: 'returns', label: 'Returns', icon: 'archive', href: route('projects.material-returns.index', projectId), match: 'projects.material-returns.*' },
        { key: 'adjustments', label: 'Adjustments', icon: 'scale', href: route('projects.stock-adjustments.index', projectId), match: 'projects.stock-adjustments.*' },
    ];
}

/**
 * @param {{ projectId: number, can: (permission: string) => boolean }} opts
 */
export function buildSubcontractChildren({ projectId, can }) {
    if (!can('subcontract.view')) {
        return [];
    }

    return [
        {
            key: 'work-orders',
            label: 'Work orders',
            icon: 'briefcase',
            href: route('projects.work-orders.index', projectId),
            match: 'projects.work-orders.*',
        },
        {
            key: 'bills',
            label: 'Subcontractor bills',
            icon: 'banknotes',
            href: route('projects.subcontractor-bills.index', projectId),
            match: 'projects.subcontractor-bills.*',
        },
    ];
}

/**
 * @param {{ projectId: number, can: (permission: string) => boolean }} opts
 */
export function buildFinanceChildren({ projectId, can }) {
    return [
        (can('billing.view') || can('subcontract.view')) && {
            key: 'retention',
            label: 'Retention',
            icon: 'scale',
            href: route('projects.retention.index', projectId),
            match: 'projects.retention.*',
        },
    ].filter(Boolean);
}

/**
 * @param {{ projectId: number, can: (permission: string) => boolean }} opts
 */
export function buildLabourChildren({ projectId, can }) {
    if (!can('labour.view')) {
        return [];
    }

    return [
        { key: 'crew', label: 'Labour', icon: 'users', href: route('projects.labour.index', projectId), match: 'projects.labour.*' },
        { key: 'attendance', label: 'Attendance', icon: 'clipboard', href: route('projects.attendance.index', projectId), match: 'projects.attendance.*' },
        {
            key: 'payments',
            label: 'Labour payments',
            icon: 'banknotes',
            href: route('projects.labour-payments.index', projectId),
            match: 'projects.labour-payments.*',
        },
    ];
}

/**
 * @param {{ projectId: number, can: (permission: string) => boolean }} opts
 */
export function buildEquipmentChildren({ projectId, can }) {
    if (!can('equipment.view')) {
        return [];
    }

    return [
        {
            key: 'assignments',
            label: 'Assignments',
            icon: 'wrench',
            href: route('projects.equipment-assignments.index', projectId),
            match: 'projects.equipment-assignments.*',
        },
        { key: 'usage', label: 'Usage', icon: 'calendar', href: route('projects.equipment-usage.index', projectId), match: 'projects.equipment-usage.*' },
        { key: 'fuel', label: 'Fuel', icon: 'truck', href: route('projects.equipment-fuel.index', projectId), match: 'projects.equipment-fuel.*' },
        { key: 'repairs', label: 'Repairs', icon: 'wrench', href: route('projects.equipment-repairs.index', projectId), match: 'projects.equipment-repairs.*' },
    ];
}

/**
 * @param {{ projectId: number, can: (permission: string) => boolean }} opts
 */
export function buildQualityChildren({ projectId, can }) {
    if (!can('quality.view')) {
        return [];
    }

    return [
        {
            key: 'inspections',
            label: 'Inspections',
            icon: 'check-circle',
            href: route('projects.inspections.index', projectId),
            match: 'projects.inspections.*',
        },
        { key: 'ncrs', label: 'NCRs', icon: 'document', href: route('projects.ncrs.index', projectId), match: 'projects.ncrs.*' },
    ];
}

/**
 * @param {{ projectId: number, can: (permission: string) => boolean }} opts
 */
export function buildScrapChildren({ projectId, can }) {
    if (!can('scrap.view')) {
        return [];
    }

    return [
        {
            key: 'generations',
            label: 'Generations',
            icon: 'archive',
            href: route('projects.scrap.generations.index', projectId),
            match: 'projects.scrap.generations.*',
        },
        {
            key: 'balances',
            label: 'Balances',
            icon: 'scale',
            href: route('projects.scrap.balances.index', projectId),
            match: 'projects.scrap.balances.*',
        },
        {
            key: 'ledger',
            label: 'Ledger',
            icon: 'document',
            href: route('projects.scrap.ledger.index', projectId),
            match: 'projects.scrap.ledger.*',
        },
        {
            key: 'adjustments',
            label: 'Adjustments',
            icon: 'scale',
            href: route('projects.scrap.adjustments.index', projectId),
            match: 'projects.scrap.adjustments.*',
        },
    ];
}

/**
 * Mark children active from the current Ziggy route.
 *
 * @param {Array<{ match?: string, active?: boolean }>} children
 */
export function withChildActiveState(children) {
    return (children || []).map((child) => ({
        ...child,
        active: child.active ?? (child.match ? !!route().current(child.match) : false),
    }));
}

export function buildProjectTabs({
    projectId,
    can,
    procurementHome = null,
    financeHome = null,
    advancedBoq = false,
    rfqEnabled = false,
}) {
    const planningChildren = withChildActiveState(buildPlanningChildren({ projectId, can }));
    const procurementChildren = withChildActiveState(buildProcurementChildren({ projectId, can, rfqEnabled }));
    const inventoryChildren = withChildActiveState(buildInventoryChildren({ projectId, can }));
    const subcontractChildren = withChildActiveState(buildSubcontractChildren({ projectId, can }));
    const financeChildren = withChildActiveState(buildFinanceChildren({ projectId, can }));
    const labourChildren = withChildActiveState(buildLabourChildren({ projectId, can }));
    const equipmentChildren = withChildActiveState(buildEquipmentChildren({ projectId, can }));
    const qualityChildren = withChildActiveState(buildQualityChildren({ projectId, can }));
    const scrapChildren = withChildActiveState(buildScrapChildren({ projectId, can }));

    return [
        { key: 'overview', label: 'Dashboard', icon: 'home', href: route('projects.show', projectId), group: null },
        can('sites.view') && { key: 'sites', label: 'Sites', icon: 'map-pin', href: route('projects.sites.index', projectId), group: null },
        { key: 'team', label: 'Team', icon: 'users', href: route('projects.team.index', projectId), group: null },

        can('drawings.view') && { key: 'drawings', label: 'Drawings', icon: 'grid', href: route('projects.drawings.index', projectId), group: null },
        can('boq.view') && { key: 'boq', label: 'BOQ', icon: 'document', href: route('projects.boqs.index', projectId), group: null },
        advancedBoq &&
            can('boq.view_material_requirements') && {
                key: 'material-requirement',
                label: 'Material Requirement',
                icon: 'archive',
                href: route('projects.material-requirement.index', projectId),
                group: null,
            },
        advancedBoq &&
            can('boq.view_reconciliation') && {
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
        advancedBoq &&
            can('boq.view_reconciliation') && {
                key: 'steel-reconciliation',
                label: 'Steel Reconciliation',
                icon: 'scale',
                href: route('projects.steel-reconciliation.index', projectId),
                group: null,
            },
        advancedBoq &&
            can('rate_analysis.view') &&
            can('boq.view_costs') && {
                key: 'rate-analysis',
                label: 'Rate Analysis',
                icon: 'scale',
                href: route('projects.rate-analyses.index', projectId),
                group: null,
            },
        can('budget.view') &&
            can('boq.view_costs') && {
                key: 'budget',
                label: 'Budget',
                icon: 'banknotes',
                href: route('projects.budget.index', projectId),
                group: null,
            },
        can('budget.view') &&
            can('dashboard.view_financials') && {
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
            href: planningChildren[0]?.href ?? route('projects.planning.tasks.index', projectId),
            group: null,
            children: planningChildren,
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

        procurementHome && {
            key: 'procurement',
            label: 'Procurement',
            icon: 'truck',
            href: procurementHome,
            group: null,
            children: procurementChildren,
        },
        can('inventory.view') && {
            key: 'inventory',
            label: 'Inventory',
            icon: 'archive',
            href: inventoryChildren[0]?.href ?? route('projects.inventory.index', projectId),
            group: null,
            children: inventoryChildren,
        },

        can('subcontract.view') && {
            key: 'subcontract',
            label: 'Subcontract',
            icon: 'briefcase',
            href: subcontractChildren[0]?.href ?? route('projects.work-orders.index', projectId),
            group: null,
            children: subcontractChildren,
        },
        financeHome && {
            key: 'finance',
            label: 'Finance',
            icon: 'banknotes',
            href: financeHome,
            group: null,
            children: financeChildren,
        },

        can('labour.view') && {
            key: 'labour',
            label: 'Labour',
            icon: 'users',
            href: labourChildren[0]?.href ?? route('projects.attendance.index', projectId),
            group: null,
            children: labourChildren,
        },
        can('equipment.view') && {
            key: 'equipment',
            label: 'Equipment',
            icon: 'wrench',
            href: equipmentChildren[0]?.href ?? route('projects.equipment-assignments.index', projectId),
            group: null,
            children: equipmentChildren,
        },
        can('quality.view') && {
            key: 'quality',
            label: 'Quality',
            icon: 'check-circle',
            href: qualityChildren[0]?.href ?? route('projects.inspections.index', projectId),
            group: null,
            children: qualityChildren,
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
            href: scrapChildren[0]?.href ?? route('projects.scrap.generations.index', projectId),
            group: null,
            children: scrapChildren,
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
