<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import QualityNav from '@/Components/Quality/QualityNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    inspections: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    results: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'inspection_number', label: 'Inspection' },
    { key: 'checklist', label: 'Checklist', mobile: false },
    { key: 'where', label: 'Where' },
    { key: 'status', label: 'Status' },
    { key: 'result', label: 'Result' },
];
</script>

<template>
    <ProjectLayout :project="project" active="quality" title="Inspections">
        <QualityNav :project-id="project.id" active="inspections" />
        <AppCard title="Inspections" subtitle="Requested, scheduled and completed quality inspections against checklist templates." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.inspections.create', project.id)">Request inspection</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all', result: filters.result ?? 'all' }"
                :selects="[
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                    { key: 'result', options: [{ value: 'all', label: 'Any result' }, ...results] },
                ]"
                placeholder="Search number or location…"
            />
            <DataTable
                :columns="columns"
                :rows="inspections.data"
                :row-href="(row) => route('projects.inspections.show', [project.id, row.id])"
                empty-icon="check-circle"
                empty-title="No inspections"
                empty-description="Request an inspection against a checklist."
            >
                <template #cell-inspection_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.inspection_number }}</div>
                    <div class="text-xs text-slate-500">{{ row.inspection_date ? formatDate(row.inspection_date) : 'Not scheduled' }}<template v-if="row.engineer"> · {{ row.engineer }}</template></div>
                </template>
                <template #cell-where="{ row }">{{ [row.site, row.location].filter(Boolean).join(' · ') || '—' }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-result="{ row }">
                    <StatusBadge v-if="row.result" :status="row.result" :label="row.result_label" />
                    <span v-else class="text-slate-400">—</span>
                </template>
            </DataTable>
            <Pagination :paginator="inspections" />
        </AppCard>
    </ProjectLayout>
</template>
