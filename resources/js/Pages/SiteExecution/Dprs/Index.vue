<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    dprs: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'dpr_number', label: 'DPR' },
    { key: 'dpr_date', label: 'Date', mobile: false },
    { key: 'status', label: 'Status' },
    { key: 'engineer', label: 'Engineer', mobile: false },
    { key: 'items_count', label: 'Work lines', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="dprs" title="Daily progress reports">
        <AppCard title="Daily progress reports" subtitle="One DPR per day, built from the approved site diaries. Approving a DPR is the only step that posts progress." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.dprs.create', project.id)">New DPR</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search DPR number…"
            />
            <DataTable
                :columns="columns"
                :rows="dprs.data"
                :row-href="(row) => route('projects.dprs.show', [project.id, row.id])"
                empty-icon="clipboard"
                empty-title="No DPRs yet"
                empty-description="Approve the day's site diaries, then create the DPR for that date."
            >
                <template #cell-dpr_number="{ row }">
                    <div class="font-mono font-medium text-slate-900">{{ row.dpr_number }}</div>
                    <div class="text-xs text-slate-500 md:hidden">{{ formatDate(row.dpr_date) }} · {{ row.engineer ?? '—' }}</div>
                </template>
                <template #cell-dpr_date="{ row }">{{ formatDate(row.dpr_date) }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-engineer="{ value }">{{ value ?? '—' }}</template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
            </DataTable>
            <Pagination :paginator="dprs" />
        </AppCard>
    </ProjectLayout>
</template>
