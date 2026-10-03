<script setup>
import AppTabs from '@/Components/UI/AppTabs.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/lib/permissions';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Layout for every page inside a project (/projects/{id}/...). Later phases add tabs
 * (BOQ, Planning, DPR, Procurement, ...) here as their modules ship.
 */
const props = defineProps({
    project: { type: Object, required: true },
    title: { type: String, default: null },
    active: { type: String, required: true },
});

const { can } = usePermissions();

const procurementHome = computed(() => {
    const first = [
        ['material_requests.view', 'projects.material-requests.index'],
        ['rfq.view', 'projects.rfqs.index'],
        ['purchase.view', 'projects.purchase-orders.index'],
        ['grn.view', 'projects.grns.index'],
    ].find(([permission]) => can(permission));

    return first ? route(first[1], props.project.id) : null;
});

const financeHome = computed(() => {
    const first = [
        ['expenses.view', 'projects.expenses.index'],
        ['billing.view', 'projects.ra-bills.index'],
        ['vendor_bills.view', 'projects.vendor-bills.index'],
        ['payments.view', 'projects.payments.index'],
        ['petty_cash.view', 'projects.petty-cash.index'],
    ].find(([permission]) => can(permission));

    return first ? route(first[1], props.project.id) : null;
});

const tabs = computed(() =>
    [
        { key: 'overview', label: 'Overview', icon: 'home', href: route('projects.show', props.project.id) },
        can('sites.view') && { key: 'sites', label: 'Sites', icon: 'map-pin', href: route('projects.sites.index', props.project.id) },
        { key: 'team', label: 'Team', icon: 'users', href: route('projects.team.index', props.project.id) },
        can('boq.view') && { key: 'boq', label: 'BOQ', icon: 'document', href: route('projects.boqs.index', props.project.id) },
        can('rate_analysis.view') &&
            can('boq.view_costs') && { key: 'rate-analysis', label: 'Rate Analysis', icon: 'scale', href: route('projects.rate-analyses.index', props.project.id) },
        can('budget.view') && can('boq.view_costs') && { key: 'budget', label: 'Budget', icon: 'banknotes', href: route('projects.budget.index', props.project.id) },
        can('planning.view') && { key: 'planning', label: 'Planning', icon: 'calendar', href: route('projects.planning.tasks.index', props.project.id) },
        can('site_diary.view') && { key: 'site-diaries', label: 'Site Diary', icon: 'camera', href: route('projects.site-diaries.index', props.project.id) },
        can('dpr.view') && { key: 'dprs', label: 'DPR', icon: 'clipboard', href: route('projects.dprs.index', props.project.id) },
        can('planning.view') && { key: 'progress', label: 'Progress', icon: 'check-circle', href: route('projects.progress', props.project.id) },
        procurementHome.value && { key: 'procurement', label: 'Procurement', icon: 'truck', href: procurementHome.value },
        can('inventory.view') && { key: 'inventory', label: 'Inventory', icon: 'archive', href: route('projects.inventory.index', props.project.id) },
        can('labour.view') && { key: 'labour', label: 'Labour', icon: 'users', href: route('projects.attendance.index', props.project.id) },
        can('subcontract.view') && { key: 'subcontract', label: 'Subcontract', icon: 'briefcase', href: route('projects.work-orders.index', props.project.id) },
        can('equipment.view') && { key: 'equipment', label: 'Equipment', icon: 'wrench', href: route('projects.equipment-assignments.index', props.project.id) },
        financeHome.value && { key: 'finance', label: 'Finance', icon: 'banknotes', href: financeHome.value },
    ].filter(Boolean),
);
</script>

<template>
    <AppLayout :title="title ? `${title} · ${project.code}` : project.name">
        <template #header>
            <div class="border-b border-line bg-white">
                <div class="mx-auto max-w-7xl px-3 pt-4 sm:px-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <Link :href="route('projects.index')" class="text-xs font-medium text-slate-500 hover:text-slate-700">Projects</Link>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2">
                                <h1 class="truncate text-lg font-semibold text-slate-900 sm:text-xl">{{ project.name }}</h1>
                                <StatusBadge :status="project.status" :label="project.status_label" />
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                <span class="font-mono">{{ project.code }}</span>
                                <span class="mx-1.5">·</span>{{ project.project_number }}
                                <template v-if="project.city"><span class="mx-1.5">·</span>{{ project.city }}</template>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <slot name="actions" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <AppTabs :items="tabs" :active="active" />
                    </div>
                </div>
            </div>
        </template>

        <slot />
    </AppLayout>
</template>
