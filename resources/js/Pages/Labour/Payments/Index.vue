<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import LabourNav from '@/Components/Labour/LabourNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    payments: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    unpaid: { type: Object, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'payment_number', label: 'Payment' },
    { key: 'status', label: 'Status' },
    { key: 'lines_count', label: 'Labourers', align: 'right', mobile: false },
    { key: 'total_gross', label: 'Gross', align: 'right', mobile: false },
    { key: 'total_net', label: 'Net', align: 'right' },
];

const drawer = ref(false);
const form = useForm({ period_from: props.unpaid.from ?? props.today, period_to: props.unpaid.to ?? props.today, remarks: '' });
function create() {
    form.post(route('projects.labour-payments.store', props.project.id), { preserveScroll: true });
}
</script>

<template>
    <ProjectLayout :project="project" active="labour" title="Labour payments">
        <LabourNav :project-id="project.id" active="payments" />
        <AppCard title="Labour payments" subtitle="Wage batches built from approved attendance. The cost was already posted at attendance approval; payments only settle it." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :disabled="!unpaid.rows" @click="drawer = true">New payment</AppButton>
            </template>
            <div v-if="unpaid.rows" class="border-b border-line bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                {{ unpaid.rows }} approved attendance {{ unpaid.rows === 1 ? 'day is' : 'days are' }} not yet in a payment ({{ formatDate(unpaid.from) }} – {{ formatDate(unpaid.to) }}).
            </div>
            <FilterBar :filters="{ status: filters.status ?? 'all' }" :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]" :searchable="false" />
            <DataTable
                :columns="columns"
                :rows="payments.data"
                :row-href="(row) => route('projects.labour-payments.show', [project.id, row.id])"
                empty-icon="banknotes"
                empty-title="No labour payments"
                empty-description="Approve attendance, then create a payment for a period."
            >
                <template #cell-payment_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.payment_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.period_from) }} – {{ formatDate(row.period_to) }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-lines_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #cell-total_gross="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-total_net="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
            <Pagination :paginator="payments" />
        </AppCard>

        <AppDrawer :show="drawer" title="New labour payment" subtitle="Collects every approved, unpaid attendance day of the period." @close="drawer = false">
            <div class="space-y-4">
                <FormInput v-model="form.period_from" type="date" label="From" required :max="today" :error="form.errors.period_from" />
                <FormInput v-model="form.period_to" type="date" label="To" required :max="today" :error="form.errors.period_to" />
                <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="1000" :error="form.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="drawer = false">Cancel</AppButton>
                <AppButton :loading="form.processing" @click="create">Create payment</AppButton>
            </template>
        </AppDrawer>
    </ProjectLayout>
</template>
