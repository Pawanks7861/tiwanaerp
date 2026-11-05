<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    recipes: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
});

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'version', label: 'Ver', align: 'right' },
    { key: 'line_count', label: 'Lines', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Material recipes">
        <PageHeader title="Material recipes" subtitle="Company templates for BOQ material norms. Activate a version to apply on items.">
            <template #actions>
                <AppButton icon="plus" :href="route('coefficients.recipes.create')">New recipe</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search code or name…"
            />
            <DataTable
                :columns="columns"
                :rows="recipes.data"
                :row-href="(row) => route('coefficients.recipes.show', row.id)"
                empty-icon="beaker"
                empty-title="No recipes"
                empty-description="Create a draft recipe with consumption lines per BOQ unit."
            >
                <template #cell-code="{ row }">
                    <span class="font-medium text-slate-900">{{ row.code }}</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="row.status" :label="row.status_label" />
                </template>
            </DataTable>
            <Pagination :paginator="recipes" />
        </AppCard>
    </AppLayout>
</template>
