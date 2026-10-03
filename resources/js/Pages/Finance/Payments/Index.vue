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
    payments: { type: Object, required: true },
    filters: { type: Object, required: true },
    directions: { type: Array, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'payment_number', label: 'Number' },
    { key: 'party_name', label: 'Party' },
    { key: 'status', label: 'Status' },
    { key: 'allocated', label: 'Allocated', align: 'right', mobile: false },
    { key: 'amount', label: 'Amount', align: 'right' },
];
</script>

<template>
    <ProjectLayout :project="project" active="finance" title="Receipts & payments">
        <FinanceNav :project-id="project.id" active="payments" />
        <AppCard title="Receipts & payments" subtitle="Maker-checker: a draft is approved by another user before it settles any bill." :padded="false">
            <template #actions>
                <div v-if="can.create" class="flex gap-2">
                    <AppButton size="sm" icon="plus" :href="route('projects.payments.create', { project: project.id, party_type: 'client' })">Receipt</AppButton>
                    <AppButton size="sm" variant="secondary" icon="plus" :href="route('projects.payments.create', { project: project.id, party_type: 'vendor' })">Payment</AppButton>
                </div>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', direction: filters.direction ?? 'all', status: filters.status ?? 'all' }"
                :selects="[
                    { key: 'direction', options: [{ value: 'all', label: 'Receipts & payments' }, ...directions] },
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                ]"
                placeholder="Search number or reference…"
            />
            <DataTable
                :columns="columns"
                :rows="payments.data"
                :row-href="(row) => route('projects.payments.show', [project.id, row.id])"
                empty-icon="banknotes"
                empty-title="No receipts or payments"
                empty-description="Record money received from the client or paid to vendors, subcontractors and labour."
            >
                <template #cell-payment_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.payment_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.payment_date) }} · {{ row.mode_label }}</div>
                </template>
                <template #cell-party_name="{ row }">
                    <div class="text-sm">{{ row.party_name }}</div>
                    <div class="text-xs text-slate-500">{{ row.party_type_label }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-allocated="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-amount="{ row }">
                    <span class="font-semibold tabular" :class="row.direction === 'receipt' ? 'text-emerald-700' : 'text-slate-900'">{{ row.direction === 'receipt' ? '+' : '−' }}{{ formatMoney(row.amount) }}</span>
                </template>
            </DataTable>
            <Pagination :paginator="payments" />
        </AppCard>
    </ProjectLayout>
</template>
