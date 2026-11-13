<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatCost } from '@/lib/electricity';
import { formatDate } from '@/lib/format';

defineProps({
    bills: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    sites: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    can_view_costs: { type: Boolean, default: false },
});

const columns = [
    { key: 'bill', label: 'Bill' },
    { key: 'site', label: 'Site', mobile: false },
    { key: 'month', label: 'Month', mobile: false },
    { key: 'total', label: 'Calculated', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Electricity bills">
        <PageHeader title="Electricity bills" subtitle="Reconcile internal calculation with DISCOM invoices." :back="route('utilities.electricity.hub')">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" :href="route('utilities.electricity.bills.create')">New bill</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', utility_site_id: filters.utility_site_id ?? 'all', status: filters.status ?? 'all' }"
                :selects="[
                    { key: 'utility_site_id', options: [{ value: 'all', label: 'All sites' }, ...sites] },
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                ]"
                placeholder="Search bill number…"
            />
            <DataTable
                :columns="columns"
                :rows="bills.data"
                :row-href="(row) => route('utilities.electricity.bills.show', row.id)"
                empty-title="No bills"
                empty-description="Generate a bill from a confirmed meter reading."
            >
                <template #cell-bill="{ row }"><span class="font-mono text-xs font-medium">{{ row.bill_number }}</span></template>
                <template #cell-site="{ row }">{{ row.site?.name ?? '—' }}</template>
                <template #cell-month="{ row }">{{ formatDate(row.billing_month) }}</template>
                <template #cell-total="{ row }"><span class="tabular">{{ formatCost(row.calculated_total, can_view_costs) }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label ?? row.status" /></template>
            </DataTable>
            <Pagination :paginator="bills" />
        </AppCard>
    </AppLayout>
</template>
