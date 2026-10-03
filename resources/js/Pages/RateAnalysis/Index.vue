<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatQty, formatRate } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    analyses: { type: Array, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'code', label: 'Analysis' },
    { key: 'output', label: 'Output' },
    { key: 'total_cost', label: 'Total cost', type: 'money', mobile: false },
    { key: 'unit_rate', label: 'Unit rate', type: 'rate' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <ProjectLayout :project="project" active="rate-analysis" title="Rate analysis">
        <AppCard title="Rate analyses" subtitle="Build up unit rates from materials, labour, equipment and subcontract. Approved analyses can be applied to BOQ lines." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.rate-analyses.create', project.id)">New analysis</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search code or name…"
            />
            <DataTable
                :columns="columns"
                :rows="analyses"
                :row-href="(row) => route('projects.rate-analyses.show', [project.id, row.id])"
                empty-icon="scale"
                empty-title="No rate analyses yet"
                empty-description="Create an analysis to derive a unit rate from its resources."
            >
                <template #cell-code="{ row }">
                    <div class="font-medium text-slate-900">{{ row.name }}</div>
                    <div class="font-mono text-xs text-slate-500">{{ row.code }}</div>
                </template>
                <template #cell-output="{ row }"><span class="tabular">{{ formatQty(row.output_quantity) }} {{ row.unit }}</span></template>
                <template #cell-total_cost="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-unit_rate="{ row }"><span class="font-medium tabular">{{ formatRate(row.unit_rate) }}</span><span class="text-xs text-slate-500"> / {{ row.unit }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
            </DataTable>
        </AppCard>
    </ProjectLayout>
</template>
