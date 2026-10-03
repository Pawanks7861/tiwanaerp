<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    returns: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    types: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'return_number', label: 'Return' },
    { key: 'type', label: 'Type' },
    { key: 'status', label: 'Status' },
    { key: 'warehouse', label: 'Store', mobile: false },
    { key: 'items_count', label: 'Lines', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="inventory" title="Material returns">
        <InventoryNav :project-id="project.id" active="returns" />
        <AppCard title="Material returns" subtitle="Unused material back from site into a store, or rejected goods back to the vendor." :padded="false">
            <template #actions>
                <div v-if="can.create" class="flex gap-2">
                    <AppButton size="sm" icon="plus" :href="route('projects.material-returns.create', { project: project.id, type: 'site_to_store' })">Site return</AppButton>
                    <AppButton size="sm" variant="secondary" icon="plus" :href="route('projects.material-returns.create', { project: project.id, type: 'to_vendor' })">Vendor return</AppButton>
                </div>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', type: filters.type ?? 'all', status: filters.status ?? 'all' }"
                :selects="[
                    { key: 'type', options: [{ value: 'all', label: 'All types' }, ...types] },
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                ]"
                placeholder="Search return number…"
            />
            <DataTable
                :columns="columns"
                :rows="returns.data"
                :row-href="(row) => route('projects.material-returns.show', [project.id, row.id])"
                empty-icon="archive"
                empty-title="No returns"
                empty-description="Return unused material from site to the store, or send rejected goods back to the vendor."
            >
                <template #cell-return_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.return_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.return_date) }}</div>
                </template>
                <template #cell-type="{ row }">
                    <AppBadge :color="row.return_type === 'to_vendor' ? 'orange' : 'blue'">{{ row.type_label }}</AppBadge>
                    <div v-if="row.vendor" class="mt-0.5 text-xs text-slate-500">{{ row.vendor }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
            </DataTable>
            <Pagination :paginator="returns" />
        </AppCard>
    </ProjectLayout>
</template>
