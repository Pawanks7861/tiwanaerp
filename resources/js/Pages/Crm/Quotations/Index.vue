<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

defineProps({
    quotations: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'number', label: 'Quotation' },
    { key: 'party', label: 'For', mobile: false },
    { key: 'status', label: 'Status' },
    { key: 'total_amount', label: 'Total', align: 'right' },
];
</script>

<template>
    <AppLayout title="Quotations">
        <PageHeader title="Quotations" subtitle="Client quotations. Revisions keep the same number; an accepted quotation can be converted into a project.">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" :href="route('crm.quotations.create')">New quotation</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search number, title or project…"
            />
            <DataTable
                :columns="columns"
                :rows="quotations.data"
                :row-href="(row) => route('crm.quotations.show', row.id)"
                empty-icon="document"
                empty-title="No quotations"
                empty-description="Prepare a quotation for a lead or an existing client."
            >
                <template #cell-number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.number }}</div>
                    <div class="text-sm text-slate-800">{{ row.title }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.quotation_date) }}<template v-if="row.converted_project_code"> · project {{ row.converted_project_code }}</template></div>
                </template>
                <template #cell-party="{ row }">
                    <div class="text-sm">{{ row.party ?? '—' }}</div>
                    <div v-if="row.lead" class="font-mono text-xs text-slate-500">{{ row.lead.number }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-total_amount="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
            <Pagination :paginator="quotations" />
        </AppCard>
    </AppLayout>
</template>
