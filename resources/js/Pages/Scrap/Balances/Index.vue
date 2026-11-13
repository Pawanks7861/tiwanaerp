<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import ScrapNav from '@/Components/Scrap/ScrapNav.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';

const props = defineProps({
    scope: { type: String, required: true },
    project: { type: Object, default: null },
    balances: { type: Object, required: true },
    filters: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    scrapTypes: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
});

const columns = [
    { key: 'project', label: 'Project', mobile: props.scope === 'company' },
    { key: 'scrap_type', label: 'Scrap type' },
    { key: 'category', label: 'Category', mobile: false },
    { key: 'quantity', label: 'Available', align: 'right' },
    { key: 'unit', label: 'Unit', mobile: false },
];

const rowHref = (row) => row.show_url;
</script>

<template>
    <component :is="scope === 'project' ? ProjectLayout : AppLayout" v-bind="scope === 'project' ? { project, active: 'scrap', title: 'Scrap balances' } : { title: 'Scrap balances' }">
        <ScrapNav active="balances" :scope="scope" :project-id="project?.id" />
        <AppCard title="Scrap balances" subtitle="On-hand scrap by type. Only lines with quantity greater than zero are listed." :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', category: filters.category ?? 'all', type: filters.type ?? 'all', project: filters.project ?? 'all' }"
                :selects="[
                    scope === 'company' && projects.length ? { key: 'project', options: [{ value: 'all', label: 'All projects' }, ...projects] } : null,
                    { key: 'category', options: [{ value: 'all', label: 'All categories' }, ...categories] },
                    { key: 'type', options: [{ value: 'all', label: 'All types' }, ...scrapTypes] },
                ].filter(Boolean)"
                placeholder="Search scrap type…"
            />
            <DataTable :columns="columns" :rows="balances.data" :row-href="rowHref" empty-icon="archive" empty-title="No scrap on hand" empty-description="Approve a scrap generation to build balances.">
                <template #cell-project="{ row }">{{ row.project?.name ?? '—' }}</template>
                <template #cell-scrap_type="{ row }">{{ row.scrap_type?.name ?? '—' }}</template>
                <template #cell-category="{ row }">{{ row.category ?? '—' }}</template>
                <template #cell-quantity="{ row }"><span class="tabular font-medium">{{ formatQty(row.quantity) }}</span></template>
                <template #cell-unit="{ row }">{{ row.unit ?? '—' }}</template>
            </DataTable>
            <Pagination :paginator="balances" />
        </AppCard>
    </component>
</template>
