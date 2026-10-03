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
    issues: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'issue_number', label: 'Issue' },
    { key: 'issued_to', label: 'Issued to' },
    { key: 'status', label: 'Status' },
    { key: 'warehouse', label: 'Store', mobile: false },
    { key: 'items_count', label: 'Lines', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="inventory" title="Material issues">
        <InventoryNav :project-id="project.id" active="issues" />
        <AppCard title="Material issues" subtitle="Material handed out from a store for use at site. Approval posts the issue and charges the project cost." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.material-issues.create', project.id)">New issue</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search issue number or recipient…"
            />
            <DataTable
                :columns="columns"
                :rows="issues.data"
                :row-href="(row) => route('projects.material-issues.show', [project.id, row.id])"
                empty-icon="archive"
                empty-title="No material issues"
                empty-description="Issue material from a store to record what was handed over for site work."
            >
                <template #cell-issue_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.issue_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.issue_date) }}</div>
                </template>
                <template #cell-issued_to="{ value }">{{ value ?? '—' }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
            </DataTable>
            <Pagination :paginator="issues" />
        </AppCard>
    </ProjectLayout>
</template>
