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
    adjustments: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    projects: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const rowHref = (row) => (props.scope === 'company' ? row.show_url : route('projects.scrap.adjustments.show', [props.project.id, row.id]));
</script>

<template>
    <component :is="scope === 'project' ? ProjectLayout : AppLayout" v-bind="scope === 'project' ? { project, active: 'scrap', title: 'Scrap adjustments' } : { title: 'Scrap adjustments' }">
        <ScrapNav active="adjustments" :scope="scope" :project-id="project?.id" />
        <AppCard title="Scrap adjustments" subtitle="Correct on-hand scrap with approval." :padded="false">
            <template v-if="scope === 'project'" #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.scrap.adjustments.create', project.id)">New adjustment</AppButton>
            </template>
            <FilterBar :filters="{ search: filters.search ?? '', status: filters.status ?? 'all', project: filters.project ?? 'all' }" :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }, scope === 'company' && projects.length ? { key: 'project', options: [{ value: 'all', label: 'All projects' }, ...projects] } : null].filter(Boolean)" placeholder="Search adjustment number…" />
            <DataTable
                :columns="[
                    { key: 'adjustment_number', label: 'Document' },
                    { key: 'project', label: 'Project', mobile: scope === 'company' },
                    { key: 'direction', label: 'Dir' },
                    { key: 'status', label: 'Status' },
                ]"
                :rows="adjustments.data"
                :row-href="rowHref"
                empty-icon="scale"
                empty-title="No adjustments"
            >
                <template #cell-adjustment_number="{ row }">
                    <div class="font-mono text-xs font-medium">{{ row.adjustment_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.adjustment_date) }}</div>
                </template>
                <template #cell-project="{ row }">{{ row.project?.name ?? '—' }}</template>
                <template #cell-direction="{ row }">{{ row.direction?.toUpperCase() }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
            </DataTable>
            <Pagination :paginator="adjustments" />
        </AppCard>
    </component>
</template>
