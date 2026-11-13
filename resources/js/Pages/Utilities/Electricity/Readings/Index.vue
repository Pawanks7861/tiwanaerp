<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatNumber } from '@/lib/format';

defineProps({
    readings: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    sites: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'period', label: 'Period' },
    { key: 'site', label: 'Site', mobile: false },
    { key: 'connection', label: 'Connection', mobile: false },
    { key: 'kwh', label: 'kWh', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Meter readings">
        <PageHeader title="Meter readings" subtitle="Monthly register snapshots per meter." :back="route('utilities.electricity.hub')">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" :href="route('utilities.electricity.readings.create')">New reading</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', utility_site_id: filters.utility_site_id ?? 'all', status: filters.status ?? 'all', billing_month: filters.billing_month ?? '' }"
                :selects="[
                    { key: 'utility_site_id', options: [{ value: 'all', label: 'All sites' }, ...sites] },
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                ]"
                placeholder="Search meter or connection…"
            />
            <DataTable
                :columns="columns"
                :rows="readings.data"
                :row-href="(row) => route('utilities.electricity.readings.show', row.id)"
                empty-title="No readings"
                empty-description="Capture opening and closing registers for a billing month."
            >
                <template #cell-period="{ row }">
                    <div class="text-sm font-medium">{{ formatDate(row.billing_month) }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.from_date) }} – {{ formatDate(row.to_date) }}</div>
                </template>
                <template #cell-site="{ row }">{{ row.site?.name ?? '—' }}</template>
                <template #cell-connection="{ row }"><span class="font-mono text-xs">{{ row.connection?.connection_number ?? '—' }}</span></template>
                <template #cell-kwh="{ row }"><span class="tabular">{{ formatNumber(row.kwh_consumption, 0, 2) }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label ?? row.status" /></template>
            </DataTable>
            <Pagination :paginator="readings" />
        </AppCard>
    </AppLayout>
</template>
