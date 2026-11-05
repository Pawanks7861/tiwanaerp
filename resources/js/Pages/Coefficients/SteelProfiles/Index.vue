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
    profiles: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
});

const columns = [
    { key: 'name', label: 'Profile' },
    { key: 'version', label: 'Ver', align: 'right' },
    { key: 'line_count', label: 'Diameters', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Steel unit-weight profiles">
        <PageHeader title="Steel unit-weight profiles" subtitle="Diameter → kg/m tables for BBS when profile method is selected.">
            <template #actions>
                <AppButton icon="plus" :href="route('coefficients.steel-profiles.create')">New profile</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search name…"
            />
            <DataTable
                :columns="columns"
                :rows="profiles.data"
                :row-href="(row) => route('coefficients.steel-profiles.show', row.id)"
                empty-icon="wrench"
                empty-title="No profiles"
            >
                <template #cell-status="{ row }">
                    <StatusBadge :status="row.status" :label="row.status_label" />
                </template>
            </DataTable>
            <Pagination :paginator="profiles" />
        </AppCard>
    </AppLayout>
</template>
