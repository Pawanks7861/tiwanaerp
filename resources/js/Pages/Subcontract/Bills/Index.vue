<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import SubcontractNav from '@/Components/Subcontract/SubcontractNav.vue';
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
    { key: 'subcontractor_name', label: 'Subcontractor' },
    { key: 'status', label: 'Status' },
    { key: 'gross_amount', label: 'Gross', align: 'right', mobile: false },
    { key: 'net_payable', label: 'Net payable', align: 'right' },
];
</script>

<template>
    <ProjectLayout :project="project" active="subcontract" title="Subcontractor bills">
        <SubcontractNav :project-id="project.id" active="bills" />
        <AppCard title="Subcontractor bills" subtitle="Running bills against work orders. Certification posts the certified work to the project cost." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.subcontractor-bills.create', project.id)">New bill</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search bill or invoice number…"
            />
            <DataTable
                :columns="columns"
                :rows="bills.data"
                :row-href="(row) => route('projects.subcontractor-bills.show', [project.id, row.id])"
                empty-icon="receipt"
                empty-title="No subcontractor bills"
                empty-description="Raise a bill against an approved work order for the work executed."
            >
                <template #cell-bill_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.bill_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.bill_date) }} · <span class="font-mono">{{ row.wo_number }}</span></div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-gross_amount="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-net_payable="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
            <Pagination :paginator="bills" />
        </AppCard>
    </ProjectLayout>
</template>
