<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    headId: { type: Number, default: null },
    drilldown: { type: Object, required: true },
});

const title = computed(() => (props.headId ? `Cost head #${props.headId}` : 'Unallocated'));

const sections = computed(() => [
    { key: 'budget_lines', label: 'Budget lines', routeName: null, rows: props.drilldown.budget_lines ?? [] },
    { key: 'purchase_orders', label: 'Purchase orders', routeName: 'projects.purchase-orders.show', rows: props.drilldown.purchase_orders ?? [] },
    { key: 'work_orders', label: 'Work orders', routeName: 'projects.work-orders.show', rows: props.drilldown.work_orders ?? [] },
    { key: 'grns', label: 'Goods receipts (approved)', routeName: 'projects.grns.show', rows: props.drilldown.grns ?? [] },
    { key: 'variations', label: 'BOQ variations', routeName: null, rows: props.drilldown.variations ?? [] },
]);

function rowHref(section, row) {
    if (section.key === 'budget_lines') {
        return route('projects.budget.index', props.project.id);
    }
    if (section.key === 'variations') {
        return route('projects.boqs.index', props.project.id);
    }
    if (!section.routeName || !row.document_id) {
        return null;
    }

    return route(section.routeName, [props.project.id, row.document_id]);
}

function rowLabel(section, row) {
    if (row.document) {
        return row.document;
    }
    if (section.key === 'budget_lines' && row.version) {
        return `Budget v${row.version}`;
    }

    return 'Document';
}
</script>

<template>
    <ProjectLayout :project="project" active="cost-control" :title="title">
        <div class="space-y-4">
            <div>
                <Link :href="route('projects.cost-control.index', project.id)" class="text-sm font-medium text-brand-700 hover:underline">Cost Control</Link>
                <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ title }}</h2>
                <p class="text-sm text-slate-500">Source documents for this cost head. Material actuals come from approved GRNs (accepted qty × PO rate). Forecast at completion is deferred.</p>
            </div>

            <AppCard v-for="section in sections" :key="section.key" :title="section.label" :padded="false">
                <div v-if="section.rows.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Document</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Description</th>
                                <th class="px-3 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in section.rows" :key="`${section.key}-${row.id}`">
                                <td class="px-3 py-2">
                                    <Link v-if="rowHref(section, row)" :href="rowHref(section, row)" class="font-medium text-brand-700 hover:underline">
                                        {{ rowLabel(section, row) }}
                                    </Link>
                                    <span v-else class="font-medium text-slate-900">{{ rowLabel(section, row) }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <StatusBadge v-if="row.status" :status="row.status" />
                                    <span v-else class="text-slate-500">—</span>
                                </td>
                                <td class="px-3 py-2 text-slate-700">{{ row.description || row.party_type || '—' }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(row.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="p-5 text-sm text-slate-500">No records.</p>
            </AppCard>
        </div>
    </ProjectLayout>
</template>
