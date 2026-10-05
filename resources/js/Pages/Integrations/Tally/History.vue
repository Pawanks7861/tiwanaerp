<script setup>
import TallyNav from '@/Components/Integrations/TallyNav.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    can: { type: Object, required: true },
});

const filter = reactive({
    from: props.filters.from || '',
    to: props.filters.to || '',
    type: props.filters.type || '',
    status: props.filters.status || '',
    reference: props.filters.reference || '',
});

function apply() {
    router.get(route('integrations.tally.history'), filter, { preserveState: true, replace: true });
}
</script>

<template>
    <AppLayout title="Tally sync history">
        <PageHeader title="Sync history" subtitle="Each row is one attempt to copy a finalized ERP document into Tally." />
        <TallyNav active="history" />
        <AppCard class="mb-4">
            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="apply">
                <FormInput v-model="filter.from" type="date" label="From" />
                <FormInput v-model="filter.to" type="date" label="To" />
                <FormInput v-model="filter.reference" label="Reference" />
                <FormSelect v-model="filter.type" label="Type" :options="[{ value: '', label: 'All' }, { value: 'client_invoice', label: 'Client invoice' }, { value: 'payment', label: 'Payment' }, { value: 'vendor_bill', label: 'Vendor bill' }, { value: 'expense', label: 'Expense' }]" />
                <FormSelect v-model="filter.status" label="Status" :options="[{ value: '', label: 'All' }, { value: 'synced', label: 'Synced' }, { value: 'pending', label: 'Pending' }, { value: 'failed', label: 'Failed' }, { value: 'needs_mapping', label: 'Needs mapping' }, { value: 'conflict', label: 'Conflict' }]" />
                <AppButton type="submit" variant="secondary">Filter</AppButton>
            </form>
        </AppCard>
        <AppCard :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2">ERP reference</th>
                            <th class="px-4 py-2">Project</th>
                            <th class="px-4 py-2">Type</th>
                            <th class="px-4 py-2">Voucher</th>
                            <th class="px-4 py-2 text-right">Amount</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Tally reference</th>
                            <th class="px-4 py-2">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in records.data" :key="row.id" class="border-t border-line">
                            <td class="px-4 py-2 whitespace-nowrap">{{ row.date || '—' }}</td>
                            <td class="px-4 py-2"><Link :href="route('integrations.tally.history.show', row.id)" class="font-medium text-brand-700">{{ row.reference }}</Link></td>
                            <td class="px-4 py-2">{{ row.project || '—' }}</td>
                            <td class="px-4 py-2">{{ row.type }}</td>
                            <td class="px-4 py-2">{{ row.voucher_type || '—' }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ row.amount ? formatMoney(row.amount) : '—' }}</td>
                            <td class="px-4 py-2"><StatusBadge :status="row.status" :label="row.status_label" /></td>
                            <td class="px-4 py-2">{{ row.tally_reference || '—' }}</td>
                            <td class="px-4 py-2 max-w-xs truncate text-slate-500">{{ row.error || '—' }}</td>
                        </tr>
                        <tr v-if="!records.data.length">
                            <td colspan="9" class="px-4 py-8 text-center text-slate-500">No sync attempts yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </AppLayout>
</template>
