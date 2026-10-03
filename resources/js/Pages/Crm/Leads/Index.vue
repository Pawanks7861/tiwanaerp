<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

defineProps({
    leads: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    assignees: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'lead_number', label: 'Lead' },
    { key: 'company_name', label: 'Company', mobile: false },
    { key: 'status', label: 'Status' },
    { key: 'assignee', label: 'Assigned to', mobile: false },
    { key: 'estimated_value', label: 'Est. value', align: 'right', mobile: false },
];
</script>

<template>
    <AppLayout title="Leads">
        <PageHeader title="Leads" subtitle="Enquiries from first contact to won or lost.">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" :href="route('crm.leads.create')">New lead</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all', assigned_to: filters.assigned_to ?? 'all' }"
                :selects="[
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                    { key: 'assigned_to', options: [{ value: 'all', label: 'Anyone' }, ...assignees] },
                ]"
                placeholder="Search number, name, company or mobile…"
            />
            <DataTable
                :columns="columns"
                :rows="leads.data"
                :row-href="(row) => route('crm.leads.show', row.id)"
                empty-icon="users"
                empty-title="No leads"
                empty-description="Record an enquiry to start tracking it."
            >
                <template #cell-lead_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.lead_number }}</div>
                    <div class="text-sm text-slate-800">{{ row.name }}</div>
                    <div v-if="row.mobile" class="text-xs text-slate-500">{{ row.mobile }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-estimated_value="{ value }"><span class="tabular">{{ value ? formatMoney(value) : '—' }}</span></template>
            </DataTable>
            <Pagination :paginator="leads" />
        </AppCard>
    </AppLayout>
</template>
