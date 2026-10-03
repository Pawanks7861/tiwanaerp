<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    rfqs: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'rfq_number', label: 'RFQ' },
    { key: 'status', label: 'Status' },
    { key: 'due_date', label: 'Quotes due' },
    { key: 'items_count', label: 'Items', align: 'right', mobile: false },
    { key: 'vendors_count', label: 'Quotes / vendors', align: 'right' },
    { key: 'comparison_status', label: 'Comparison', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="procurement" title="RFQs">
        <ProcurementNav :project-id="project.id" active="rfqs" />
        <AppCard title="Requests for quotation" subtitle="Approved material request lines sent to vendors for pricing." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.rfqs.create', project.id)">New RFQ</AppButton>
            </template>

            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search RFQ number or title…"
            />

            <DataTable
                :columns="columns"
                :rows="rfqs.data"
                :row-href="(row) => route('projects.rfqs.show', [project.id, row.id])"
                empty-icon="document"
                empty-title="No RFQs yet"
                empty-description="Create an RFQ from approved material request lines and invite vendors to quote."
            >
                <template #cell-rfq_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.rfq_number }}</div>
                    <div class="text-xs text-slate-500">{{ row.title || formatDate(row.rfq_date) }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-due_date="{ value }">{{ formatDate(value) }}</template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #cell-vendors_count="{ row }"><span class="tabular">{{ row.quotations_count }} / {{ row.vendors_count }}</span></template>
                <template #cell-comparison_status="{ value }">
                    <StatusBadge v-if="value" :status="value" />
                    <span v-else class="text-xs text-slate-400">—</span>
                </template>
            </DataTable>
            <Pagination :paginator="rfqs" />
        </AppCard>
    </ProjectLayout>
</template>
