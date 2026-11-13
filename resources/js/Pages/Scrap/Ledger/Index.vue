<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import ScrapNav from '@/Components/Scrap/ScrapNav.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatQty } from '@/lib/format';

defineProps({
    scope: { type: String, required: true },
    project: { type: Object, default: null },
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    scrapTypes: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
});

const columns = [
    { key: 'transaction_date', label: 'Date' },
    { key: 'project', label: 'Project', mobile: false },
    { key: 'type_label', label: 'Type' },
    { key: 'scrap_type', label: 'Scrap type' },
    { key: 'in', label: 'In', align: 'right', mobile: false },
    { key: 'out', label: 'Out', align: 'right', mobile: false },
];
</script>

<template>
    <component :is="scope === 'project' ? ProjectLayout : AppLayout" v-bind="scope === 'project' ? { project, active: 'scrap', title: 'Scrap ledger' } : { title: 'Scrap ledger' }">
        <ScrapNav active="ledger" :scope="scope" :project-id="project?.id" />
        <AppCard title="Scrap ledger" subtitle="Append-only movement history." :padded="false">
            <FilterBar
                :filters="{ type: filters.type ?? 'all', scrap_type: filters.scrap_type ?? 'all', project: filters.project ?? 'all' }"
                :selects="[
                    scope === 'company' && projects.length ? { key: 'project', options: [{ value: 'all', label: 'All projects' }, ...projects] } : null,
                    { key: 'type', options: [{ value: 'all', label: 'All types' }, ...types] },
                    { key: 'scrap_type', options: [{ value: 'all', label: 'All scrap types' }, ...scrapTypes] },
                ].filter(Boolean)"
            />
            <DataTable :columns="columns" :rows="entries.data" empty-icon="document" empty-title="No movements" empty-description="Ledger entries appear when generations are approved or scrap is dispatched.">
                <template #cell-transaction_date="{ row }">{{ formatDate(row.transaction_date) }}</template>
                <template #cell-project="{ row }">{{ row.project?.name ?? '—' }}</template>
                <template #cell-in="{ row }"><span class="tabular">{{ formatQty(row.quantity_in) }}</span></template>
                <template #cell-out="{ row }"><span class="tabular">{{ formatQty(row.quantity_out) }}</span></template>
            </DataTable>
            <Pagination :paginator="entries" />
        </AppCard>
    </component>
</template>
