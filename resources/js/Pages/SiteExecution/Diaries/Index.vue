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
    diaries: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'diary_date', label: 'Date' },
    { key: 'status', label: 'Status' },
    { key: 'site', label: 'Site / location', mobile: false },
    { key: 'created_by', label: 'Engineer', mobile: false },
    { key: 'work_items_count', label: 'Work lines', align: 'right', mobile: false },
    { key: 'photos_count', label: 'Photos', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="site-diaries" title="Site diaries">
        <AppCard title="Site diaries" subtitle="Daily site records by the engineers. Approved diaries feed the DPR of that date; a diary never posts progress itself." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.site-diaries.create', project.id)">New diary</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all', mine: filters.mine ? '1' : 'all' }"
                :selects="[
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                    { key: 'mine', options: [{ value: 'all', label: 'Everyone' }, { value: '1', label: 'My diaries' }] },
                ]"
                placeholder="Search work performed or location…"
            />
            <DataTable
                :columns="columns"
                :rows="diaries.data"
                :row-href="(row) => route('projects.site-diaries.show', [project.id, row.id])"
                empty-icon="camera"
                empty-title="No site diaries"
                empty-description="Record the day's work, labour, equipment, material and photos from site."
            >
                <template #cell-diary_date="{ row }">
                    <div class="font-medium text-slate-900">{{ formatDate(row.diary_date) }}</div>
                    <div class="text-xs text-slate-500 md:hidden">{{ row.site ?? row.work_location ?? '—' }} · {{ row.created_by }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-site="{ row }">{{ row.site ?? row.work_location ?? '—' }}</template>
                <template #cell-work_items_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #cell-photos_count="{ value }"><span class="tabular">{{ value }}</span></template>
            </DataTable>
            <Pagination :paginator="diaries" />
        </AppCard>
    </ProjectLayout>
</template>
