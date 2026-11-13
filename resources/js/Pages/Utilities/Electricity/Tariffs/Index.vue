<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';

defineProps({
    tariffs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    connections: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'name', label: 'Tariff' },
    { key: 'connection', label: 'Connection', mobile: false },
    { key: 'period', label: 'Effective', mobile: false },
    { key: 'components', label: 'Components', align: 'right', mobile: false },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Tariffs">
        <PageHeader title="Electricity tariffs" subtitle="Versioned rate cards per connection." :back="route('utilities.electricity.hub')">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" :href="route('utilities.electricity.tariffs.create')">New tariff</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', electricity_connection_id: filters.electricity_connection_id ?? 'all' }"
                :selects="[{ key: 'electricity_connection_id', options: [{ value: 'all', label: 'All connections' }, ...connections] }]"
                placeholder="Search name or category…"
            />
            <DataTable
                :columns="columns"
                :rows="tariffs.data"
                :row-href="(row) => route('utilities.electricity.tariffs.show', row.id)"
                empty-title="No tariffs"
                empty-description="Create a tariff for a connection to enable bill calculation."
            >
                <template #cell-name="{ row }">
                    <div class="text-sm font-medium text-slate-900">{{ row.name }}</div>
                    <div v-if="row.tariff_category" class="text-xs text-slate-500">{{ row.tariff_category }}</div>
                </template>
                <template #cell-connection="{ row }">{{ row.connection?.connection_number ?? '—' }}</template>
                <template #cell-period="{ row }">{{ formatDate(row.effective_from) }}<span v-if="row.effective_to"> – {{ formatDate(row.effective_to) }}</span></template>
                <template #cell-components="{ row }"><span class="tabular">{{ row.components_count ?? row.components?.length ?? '—' }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.is_active ? 'active' : 'inactive'" :label="row.is_active ? 'Active' : 'Inactive'" /></template>
            </DataTable>
            <Pagination :paginator="tariffs" />
        </AppCard>
    </AppLayout>
</template>
