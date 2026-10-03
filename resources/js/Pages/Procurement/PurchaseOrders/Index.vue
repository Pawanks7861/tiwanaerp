<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    orders: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'po_number', label: 'Purchase order' },
    { key: 'vendor', label: 'Vendor' },
    { key: 'status', label: 'Status' },
    { key: 'delivery_date', label: 'Delivery', mobile: false },
    { key: 'tax_type', label: 'GST', mobile: false },
    { key: 'grand_total', label: 'Total', type: 'money' },
];
</script>

<template>
    <ProjectLayout :project="project" active="procurement" title="Purchase orders">
        <ProcurementNav :project-id="project.id" active="purchase-orders" />
        <AppCard title="Purchase orders" subtitle="Created from approved bid comparisons, or directly with a justification." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" variant="secondary" icon="plus" :href="route('projects.purchase-orders.create', project.id)">Direct PO</AppButton>
            </template>

            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search PO number or vendor…"
            />

            <DataTable
                :columns="columns"
                :rows="orders.data"
                :row-href="(row) => route('projects.purchase-orders.show', [project.id, row.id])"
                empty-icon="document"
                empty-title="No purchase orders"
                empty-description="Approve a bid comparison on an RFQ to create a purchase order."
            >
                <template #cell-po_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">
                        {{ row.po_number }}
                        <span v-if="row.revision_no > 0" class="ml-1 rounded bg-slate-100 px-1 py-0.5 text-[10px] text-slate-600">Rev {{ row.revision_no }}</span>
                        <span v-if="row.is_direct" class="ml-1 rounded bg-amber-50 px-1 py-0.5 text-[10px] text-amber-700">Direct</span>
                    </div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.po_date) }} · {{ row.items_count }} item(s)</div>
                </template>
                <template #cell-vendor="{ row }">{{ row.vendor?.name ?? '—' }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-delivery_date="{ value }">{{ formatDate(value) }}</template>
                <template #cell-tax_type="{ value }"><span class="text-xs">{{ value === 'intra' ? 'CGST + SGST' : value === 'inter' ? 'IGST' : '—' }}</span></template>
                <template #cell-grand_total="{ value }"><span class="font-medium tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
            <Pagination :paginator="orders" />
        </AppCard>
    </ProjectLayout>
</template>
