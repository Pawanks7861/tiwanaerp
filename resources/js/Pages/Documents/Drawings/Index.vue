<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';

defineProps({
    project: { type: Object, required: true },
    drawings: { type: Object, required: true },
    filters: { type: Object, required: true },
    disciplines: { type: Array, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'drawing_number', label: 'Drawing' },
    { key: 'discipline_label', label: 'Discipline', mobile: false },
    { key: 'current_revision', label: 'Current rev.' },
    { key: 'open_revision_status', label: 'In workflow' },
    { key: 'revisions_count', label: 'Revisions', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="drawings" title="Drawings">
        <AppCard title="Drawing register" subtitle="Each revision is kept with its file. Approving a revision supersedes the previous one; nothing is overwritten." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.drawings.create', project.id)">Register drawing</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', discipline: filters.discipline ?? 'all', status: filters.status ?? 'all' }"
                :selects="[
                    { key: 'discipline', options: [{ value: 'all', label: 'All disciplines' }, ...disciplines] },
                    { key: 'status', options: [{ value: 'all', label: 'Any status' }, ...statuses] },
                ]"
                placeholder="Search number or title…"
            />
            <DataTable
                :columns="columns"
                :rows="drawings.data"
                :row-href="(row) => route('projects.drawings.show', [project.id, row.id])"
                empty-icon="grid"
                empty-title="No drawings"
                empty-description="Register a drawing with its first revision."
            >
                <template #cell-drawing_number="{ row }">
                    <div class="font-mono text-xs font-medium break-all text-slate-900">{{ row.drawing_number }}</div>
                    <div class="text-sm break-words text-slate-800">{{ row.title }}</div>
                </template>
                <template #cell-current_revision="{ row }">
                    <span v-if="row.current_revision" class="font-mono text-sm font-semibold text-green-700">{{ row.current_revision }}</span>
                    <span v-else class="text-xs text-slate-400">None approved</span>
                </template>
                <template #cell-open_revision_status="{ row }">
                    <StatusBadge v-if="row.open_revision_status" :status="row.open_revision_status" :label="row.open_revision_label" />
                    <span v-else class="text-slate-400">—</span>
                </template>
            </DataTable>
            <Pagination :paginator="drawings" />
        </AppCard>
    </ProjectLayout>
</template>
