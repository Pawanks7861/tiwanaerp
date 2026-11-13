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
    sites: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'location', label: 'Location', mobile: false },
    { key: 'connections', label: 'Connections', align: 'right', mobile: false },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Utility sites">
        <PageHeader title="Utility sites" subtitle="Locations where electricity connections are registered." :back="route('utilities.electricity.hub')">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" :href="route('utilities.electricity.sites.create')">New site</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar :filters="{ search: filters.search ?? '', active: filters.active ?? 'all' }" :selects="[{ key: 'active', options: [{ value: 'all', label: 'All' }, { value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] }]" placeholder="Search code or name…" />
            <DataTable
                :columns="columns"
                :rows="sites.data"
                :row-href="(row) => (can.update ? route('utilities.electricity.sites.edit', row.id) : null)"
                empty-title="No sites"
                empty-description="Add a site before registering connections."
            >
                <template #cell-code="{ row }"><span class="font-mono text-xs font-medium">{{ row.code }}</span></template>
                <template #cell-location="{ row }"><span class="text-sm text-slate-600">{{ [row.city, row.state_code].filter(Boolean).join(', ') || '—' }}</span></template>
                <template #cell-connections="{ row }"><span class="tabular">{{ row.connections_count ?? row.connections?.length ?? '—' }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.is_active ? 'active' : 'inactive'" :label="row.is_active ? 'Active' : 'Inactive'" /></template>
            </DataTable>
            <Pagination :paginator="sites" />
        </AppCard>
    </AppLayout>
</template>
