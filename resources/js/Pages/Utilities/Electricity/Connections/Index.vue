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
    connections: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    sites: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'connection', label: 'Connection' },
    { key: 'site', label: 'Site', mobile: false },
    { key: 'discom', label: 'DISCOM', mobile: false },
    { key: 'demand', label: 'Contract kVA', align: 'right', mobile: false },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Connections">
        <PageHeader title="Electricity connections" subtitle="Consumer accounts linked to utility sites." :back="route('utilities.electricity.hub')">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" :href="route('utilities.electricity.connections.create')">New connection</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', utility_site_id: filters.utility_site_id ?? 'all', active: filters.active ?? 'all' }"
                :selects="[
                    { key: 'utility_site_id', options: [{ value: 'all', label: 'All sites' }, ...sites] },
                    { key: 'active', options: [{ value: 'all', label: 'All' }, { value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] },
                ]"
                placeholder="Search connection, consumer or DISCOM…"
            />
            <DataTable
                :columns="columns"
                :rows="connections.data"
                :row-href="(row) => route('utilities.electricity.connections.show', row.id)"
                empty-title="No connections"
                empty-description="Register a connection under a utility site."
            >
                <template #cell-connection="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.connection_number }}</div>
                    <div v-if="row.consumer_number" class="text-xs text-slate-500">Consumer {{ row.consumer_number }}</div>
                </template>
                <template #cell-site="{ row }">{{ row.site?.name ?? row.utility_site?.name ?? '—' }}</template>
                <template #cell-discom="{ row }">{{ row.discom_name ?? '—' }}</template>
                <template #cell-demand="{ row }"><span class="tabular">{{ row.contract_demand ?? '—' }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.is_active ? 'active' : 'inactive'" :label="row.is_active ? 'Active' : 'Inactive'" /></template>
            </DataTable>
            <Pagination :paginator="connections" />
        </AppCard>
    </AppLayout>
</template>
