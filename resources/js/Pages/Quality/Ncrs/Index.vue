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
    ncrs: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    severities: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'ncr_number', label: 'NCR' },
    { key: 'severity', label: 'Severity' },
    { key: 'responsible', label: 'Responsible', mobile: false },
    { key: 'target_date', label: 'Target' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <ProjectLayout :project="project" active="quality" title="NCRs">
        <QualityNav :project-id="project.id" active="ncrs" />
        <AppCard title="Non-conformance reports" subtitle="Raised from failed or conditional inspections, or manually. Verified by someone other than the resolver, then closed." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.ncrs.create', project.id)">Raise NCR</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all', severity: filters.severity ?? 'all' }"
                :selects="[
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                    { key: 'severity', options: [{ value: 'all', label: 'Any severity' }, ...severities] },
                ]"
                placeholder="Search number, issue or location…"
            />
            <DataTable
                :columns="columns"
                :rows="ncrs.data"
                :row-href="(row) => route('projects.ncrs.show', [project.id, row.id])"
                empty-icon="warning"
                empty-title="No NCRs"
                empty-description="Non-conformances raised on this project appear here."
            >
                <template #cell-ncr_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.ncr_number }}</div>
                    <div class="max-w-md text-xs break-words text-slate-600">{{ row.issue_excerpt }}</div>
                    <div v-if="row.inspection" class="text-xs text-slate-400">From {{ row.inspection }}</div>
                </template>
                <template #cell-severity="{ row }"><StatusBadge :status="row.severity" :label="row.severity_label" /></template>
                <template #cell-responsible="{ value }">{{ value ?? '—' }}</template>
                <template #cell-target_date="{ row }">
                    <span :class="row.overdue ? 'font-medium text-red-700' : ''">{{ row.target_date ? formatDate(row.target_date) : '—' }}</span>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
            </DataTable>
            <Pagination :paginator="ncrs" />
        </AppCard>
    </ProjectLayout>
</template>
