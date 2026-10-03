<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import SubcontractNav from '@/Components/Subcontract/SubcontractNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    orders: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'wo_number', label: 'Work order' },
    { key: 'subcontractor_name', label: 'Subcontractor' },
    { key: 'status', label: 'Status' },
    { key: 'items_count', label: 'Items', align: 'right', mobile: false },
    { key: 'total_value', label: 'Value', align: 'right' },
];
</script>

<template>
    <ProjectLayout :project="project" active="subcontract" title="Work orders">
        <SubcontractNav :project-id="project.id" active="work-orders" />
        <AppCard title="Work orders" subtitle="Scope and rates agreed with subcontractors. Approved work orders are locked and can be billed against." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.work-orders.create', project.id)">New work order</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search WO number or subcontractor…"
            />
            <DataTable
                :columns="columns"
                :rows="orders.data"
                :row-href="(row) => route('projects.work-orders.show', [project.id, row.id])"
                empty-icon="briefcase"
                empty-title="No work orders"
                empty-description="Create a work order to engage a subcontractor against BOQ items."
            >
                <template #cell-wo_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.wo_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.wo_date) }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #cell-total_value="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
            <Pagination :paginator="orders" />
        </AppCard>
    </ProjectLayout>
</template>
