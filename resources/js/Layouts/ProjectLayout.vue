<script setup>
import AppTabs from '@/Components/UI/AppTabs.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/lib/permissions';
import { buildProjectTabs } from '@/lib/projectNavigation';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Layout for every page inside a project (/projects/{id}/...). Tab order is the
 * construction workflow: drawings before BOQ, then planning, procurement, inventory, site execution.
 */
const props = defineProps({
    project: { type: Object, required: true },
    title: { type: String, default: null },
    active: { type: String, required: true },
});

const { can } = usePermissions();
const page = usePage();
const advancedBoq = computed(() => page.props.features?.advanced_boq_calculations === true);
const rfqEnabled = computed(() => page.props.features?.procurement_rfq === true);

const procurementHome = computed(() => {
    const first = [
        ['material_requests.view', 'projects.material-requests.index'],
        rfqEnabled.value ? ['rfq.view', 'projects.rfqs.index'] : null,
        ['purchase.view', 'projects.purchase-orders.index'],
        ['grn.view', 'projects.grns.index'],
    ].filter(Boolean).find(([permission]) => can(permission));

    return first ? route(first[1], props.project.id) : null;
});

const financeHome = computed(() => {
    // Soft-retired finance modules; retention is the remaining project finance landing.
    if (can('billing.view') || can('subcontract.view')) {
        return route('projects.retention.index', props.project.id);
    }

    return null;
});

const tabs = computed(() =>
    buildProjectTabs({
        projectId: props.project.id,
        can,
        procurementHome: procurementHome.value,
        financeHome: financeHome.value,
        advancedBoq: advancedBoq.value,
    }),
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
                    <div class="mt-3" data-tour="project-tabs">
                        <AppTabs :items="tabs" :active="active" />
                    </div>
                </div>
            </div>
        </template>

        <slot />
    </AppLayout>
</template>
