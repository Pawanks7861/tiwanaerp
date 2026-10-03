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
    transfers: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'transfer_number', label: 'Transfer' },
    { key: 'route', label: 'From → to' },
    { key: 'status', label: 'Status' },
    { key: 'items_count', label: 'Lines', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="inventory" title="Stock transfers">
        <InventoryNav :project-id="project.id" active="transfers" />
        <AppCard title="Stock transfers" subtitle="Moves between stores. Dispatch takes stock out of the source; receipts put it into the destination." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.stock-transfers.create', project.id)">New transfer</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search transfer number…"
            />
            <DataTable
                :columns="columns"
                :rows="transfers.data"
                :row-href="(row) => route('projects.stock-transfers.show', [project.id, row.id])"
                empty-icon="truck"
                empty-title="No stock transfers"
                empty-description="Transfer material from the central store to site, or between stores."
            >
                <template #cell-transfer_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.transfer_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.transfer_date) }}</div>
                </template>
                <template #cell-route="{ row }">{{ row.from }} → {{ row.to }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
            </DataTable>
            <Pagination :paginator="transfers" />
        </AppCard>
    </ProjectLayout>
</template>
