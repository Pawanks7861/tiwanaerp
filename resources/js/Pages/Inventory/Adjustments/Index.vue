<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    adjustments: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    reasons: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'adjustment_number', label: 'Adjustment' },
    { key: 'reason_label', label: 'Reason' },
    { key: 'status', label: 'Status' },
    { key: 'warehouse', label: 'Store', mobile: false },
    { key: 'items_count', label: 'Lines', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="inventory" title="Stock adjustments">
        <InventoryNav :project-id="project.id" active="adjustments" />
        <AppCard title="Stock adjustments" subtitle="Opening stock, physical count corrections, damage and theft. Approved by someone other than the submitter." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.stock-adjustments.create', project.id)">New adjustment</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', reason: filters.reason ?? 'all', status: filters.status ?? 'all' }"
                :selects="[
                    { key: 'reason', options: [{ value: 'all', label: 'All reasons' }, ...reasons] },
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                ]"
                placeholder="Search adjustment number…"
            />
            <DataTable
                :columns="columns"
                :rows="adjustments.data"
                :row-href="(row) => route('projects.stock-adjustments.show', [project.id, row.id])"
                empty-icon="archive"
                empty-title="No adjustments"
                empty-description="Record opening stock or correct the book stock after a physical count."
            >
                <template #cell-adjustment_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.adjustment_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.adjustment_date) }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
            </DataTable>
            <Pagination :paginator="adjustments" />
        </AppCard>
    </ProjectLayout>
</template>
