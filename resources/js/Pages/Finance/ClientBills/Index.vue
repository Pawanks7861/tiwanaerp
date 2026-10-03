<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    bills: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'display_number', label: 'RA bill' },
    { key: 'status', label: 'Status' },
    { key: 'gross_amount', label: 'Work value', align: 'right', mobile: false },
    { key: 'invoice_total', label: 'Invoice total', align: 'right', mobile: false },
    { key: 'net_payable', label: 'Net receivable', align: 'right' },
    { key: 'received_amount', label: 'Received', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="finance" title="Client RA bills">
        <FinanceNav :project-id="project.id" active="ra-bills" />
        <AppCard title="Client RA bills" subtitle="Running account bills measured from recorded progress. The invoice number is assigned on certification." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.ra-bills.create', project.id)">New RA bill</AppButton>
            </template>
            <FilterBar
                :filters="{ status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                :searchable="false"
            />
            <DataTable
                :columns="columns"
                :rows="bills.data"
                :row-href="(row) => route('projects.ra-bills.show', [project.id, row.id])"
                empty-icon="receipt"
                empty-title="No RA bills"
                empty-description="Raise the first running bill once progress is recorded against the approved BOQ."
            >
                <template #cell-display_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.display_number }}</div>
                    <div class="text-xs text-slate-500">RA {{ row.ra_sequence }} · {{ formatDate(row.invoice_date) }}<template v-if="row.client_name"> · {{ row.client_name }}</template></div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-gross_amount="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-invoice_total="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-net_payable="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-received_amount="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
            <Pagination :paginator="bills" />
        </AppCard>
    </ProjectLayout>
</template>
