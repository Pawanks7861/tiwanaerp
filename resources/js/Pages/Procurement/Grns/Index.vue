<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    grns: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    receivable: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'grn_number', label: 'GRN' },
    { key: 'vendor', label: 'Vendor' },
    { key: 'status', label: 'Status' },
    { key: 'po_number', label: 'Purchase order', mobile: false },
    { key: 'warehouse', label: 'Warehouse', mobile: false },
    { key: 'items_count', label: 'Lines', align: 'right', mobile: false },
];

const showPick = ref(false);
const poId = ref(null);
function start() {
    router.get(route('projects.grns.create', { project: props.project.id, purchase_order: poId.value }));
}
</script>

<template>
    <ProjectLayout :project="project" active="procurement" title="Goods receipts">
        <ProcurementNav :project-id="project.id" active="grns" />
        <AppCard title="Goods receipt notes" subtitle="Material received at site against approved purchase orders." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :disabled="!receivable.length" @click="((poId = receivable[0]?.value ?? null), (showPick = true))">Receive goods</AppButton>
            </template>

            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search GRN, PO or invoice number…"
            />

            <DataTable
                :columns="columns"
                :rows="grns.data"
                :row-href="(row) => route('projects.grns.show', [project.id, row.id])"
                empty-icon="truck"
                empty-title="No goods receipts"
                empty-description="Receive goods against an approved purchase order to record what arrived at site."
            >
                <template #cell-grn_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.grn_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.receipt_date) }}</div>
                </template>
                <template #cell-vendor="{ row }">{{ row.vendor?.name ?? '—' }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-po_number="{ value }"><span class="font-mono text-xs">{{ value ?? '—' }}</span></template>
                <template #cell-warehouse="{ value }">{{ value ?? 'Site' }}</template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
            </DataTable>
            <Pagination :paginator="grns" />
        </AppCard>

        <AppModal :show="showPick" title="Receive goods" @close="showPick = false">
            <SearchSelect v-model="poId" label="Purchase order" required :options="receivable" help="Only approved orders that are not fully received are listed." />
            <template #footer>
                <AppButton variant="secondary" @click="showPick = false">Cancel</AppButton>
                <AppButton :disabled="!poId" @click="start">Continue</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
