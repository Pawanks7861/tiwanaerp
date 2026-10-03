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
    { key: 'bill_number', label: 'Bill' },
    { key: 'vendor_name', label: 'Vendor' },
    { key: 'status', label: 'Status' },
    { key: 'total_amount', label: 'Invoice total', align: 'right', mobile: false },
    { key: 'net_payable', label: 'Net payable', align: 'right' },
    { key: 'paid_amount', label: 'Paid', align: 'right', mobile: false },
];
</script>

<template>
    <ProjectLayout :project="project" active="finance" title="Vendor bills">
        <FinanceNav :project-id="project.id" active="vendor-bills" />
        <AppCard title="Vendor bills" subtitle="Purchase bills are matched to the PO and accepted GRN quantities (no cost: the material was costed on issue). Direct bills post cost on approval." :padded="false">
            <template #actions>
                <div v-if="can.create" class="flex gap-2">
                    <AppButton size="sm" icon="plus" :href="route('projects.vendor-bills.create', { project: project.id, bill_type: 'purchase_order' })">PO bill</AppButton>
                    <AppButton size="sm" variant="secondary" icon="plus" :href="route('projects.vendor-bills.create', { project: project.id, bill_type: 'direct' })">Direct bill</AppButton>
                </div>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search bill or vendor invoice number…"
            />
            <DataTable
                :columns="columns"
                :rows="bills.data"
                :row-href="(row) => route('projects.vendor-bills.show', [project.id, row.id])"
                empty-icon="receipt"
                empty-title="No vendor bills"
                empty-description="Book a vendor invoice against a received purchase order, or a direct bill for services."
            >
                <template #cell-bill_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.bill_number }}</div>
                    <div class="text-xs text-slate-500">{{ row.vendor_invoice_no }} · {{ formatDate(row.vendor_invoice_date) }}</div>
                    <div class="text-xs text-slate-500">{{ row.po_number ? `PO ${row.po_number}` : row.bill_type_label }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-total_amount="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-net_payable="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-paid_amount="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
            <Pagination :paginator="bills" />
        </AppCard>
    </ProjectLayout>
</template>
