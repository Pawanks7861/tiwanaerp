<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import ScrapNav from '@/Components/Scrap/ScrapNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';

const props = defineProps({
    scope: { type: String, required: true },
    project: { type: Object, default: null },
    generations: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    projects: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'generation_number', label: 'Document' },
    { key: 'project', label: 'Project', mobile: props.scope === 'company' },
    { key: 'status', label: 'Status' },
    { key: 'items_count', label: 'Lines', align: 'right', mobile: false },
];

const rowHref = (row) =>
    props.scope === 'company'
        ? row.show_url
        : route('projects.scrap.generations.show', [props.project.id, row.id]);
</script>

<template>
    <component :is="scope === 'project' ? ProjectLayout : AppLayout" v-bind="scope === 'project' ? { project, active: 'scrap', title: 'Scrap generations' } : { title: 'Scrap generations' }">
        <ScrapNav :active="'generations'" :scope="scope" :project-id="project?.id" />
        <AppCard title="Scrap generations" subtitle="Record scrap generated at site. Approval posts quantity to project scrap stock." :padded="false">
            <template v-if="scope === 'project'" #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.scrap.generations.create', project.id)">New generation</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all', project: filters.project ?? 'all' }"
                :selects="[
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                    scope === 'company' && projects.length ? { key: 'project', options: [{ value: 'all', label: 'All projects' }, ...projects] } : null,
                ].filter(Boolean)"
                placeholder="Search generation number…"
            />
            <DataTable :columns="columns" :rows="generations.data" :row-href="rowHref" empty-icon="archive" empty-title="No generations" empty-description="Create a scrap generation from a project.">
                <template #cell-generation_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.generation_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.generation_date) }}</div>
                </template>
                <template #cell-project="{ row }">{{ row.project?.name ?? '—' }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
            </DataTable>
            <Pagination :paginator="generations" />
        </AppCard>
    </component>
</template>
