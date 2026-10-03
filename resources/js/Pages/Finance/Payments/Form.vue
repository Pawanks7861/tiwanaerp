<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    payment: { type: Object, default: null },
    partyType: { type: String, default: null },
    partyId: { type: Number, default: null },
    partyTypes: { type: Array, required: true },
    parties: { type: Array, required: true },
    payables: { type: Array, required: true },
    modes: { type: Array, required: true },
    today: { type: String, required: true },
});

const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};

const editing = computed(() => !!props.payment);
const p = props.payment;
const form = useForm({
    party_type: props.partyType,
    party_id: props.partyId,
    payment_date: p?.payment_date ?? props.today,
    mode: p?.mode ?? 'bank_transfer',
    bank_reference: p?.bank_reference ?? '',
    amount: p?.amount ?? null,
    tds_amount: p && p.tds_amount !== '0.00' ? p.tds_amount : null,
    remarks: p?.remarks ?? '',
    allocations: props.payables.map((row) => ({ payable_id: row.id, amount: row.allocated || null })),
});

const isReceipt = computed(() => form.party_type === 'client');
const isLabour = computed(() => form.party_type === 'labour_payment');

function reloadWith(params) {
    router.get(route('projects.payments.create', props.project.id), params, { preserveScroll: true });
}
function chooseType(type) {
    if (!editing.value && type !== props.partyType) {
        reloadWith({ party_type: type });
    }
}
function chooseParty(id) {
    if (!editing.value && id && id !== props.partyId) {
        reloadWith({ party_type: form.party_type, party_id: id });
    }
}

const allocated = computed(() => form.allocations.reduce((s, a) => s.plus(dec(a.amount)), new Decimal(0)));
const unallocated = computed(() => dec(form.amount).minus(allocated.value));
const overOutstanding = (i) => dec(form.allocations[i]?.amount).greaterThan(dec(props.payables[i]?.outstanding));

/** Fills allocations oldest document first, up to each outstanding and the payment amount. */
function autoAllocate() {
    let left = dec(form.amount);
    const order = props.payables.map((_, i) => i).reverse();
    form.allocations.forEach((a) => (a.amount = null));
    for (const i of order) {
        if (left.lte(0)) {
            break;
        }
        const take = Decimal.min(left, dec(props.payables[i].outstanding));
        if (take.gt(0)) {
            form.allocations[i].amount = take.toFixed(2);
            left = left.minus(take);
        }
    }
}

function submit() {
    const transform = (d) => {
        const data = {
            ...d,
            bank_reference: d.bank_reference || null,
            remarks: d.remarks || null,
            allocations: d.allocations.map((a) => ({ ...a, amount: a.amount || null })),
        };
        if (editing.value) {
            delete data.party_type;
            delete data.party_id;
        }

        return data;
    };
    editing.value
        ? form.transform(transform).put(route('projects.payments.update', [props.project.id, props.payment.id]))
        : form.transform(transform).post(route('projects.payments.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="editing ? payment.payment_number : 'New receipt / payment'">
        <FinanceNav :project-id="project.id" active="payments" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.payments.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Payments</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit ${payment.payment_number}` : isReceipt ? 'New receipt' : 'New payment' }}</h2>
                    <p class="text-xs text-slate-500">Receipts come from the project client; payments go to vendors, subcontractors and labour batches. Cash movements never post project cost.</p>
                </div>
                <p v-if="form.errors.payment || form.errors.allocations" class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ form.errors.payment || form.errors.allocations }}</p>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <template v-if="!editing">
                        <FormSelect :model-value="form.party_type" label="Party type" required :options="partyTypes" :error="form.errors.party_type" @update:model-value="chooseType" />
                        <SearchSelect v-if="form.party_type" :model-value="form.party_id" :label="isLabour ? 'Labour payment batch' : 'Party'" required :options="parties" class="sm:col-span-2" :error="form.errors.party_id" @update:model-value="chooseParty" />
                    </template>
                    <div v-else class="sm:col-span-2">
                        <p class="text-xs font-medium text-slate-700">Party</p>
                        <p class="mt-2 text-sm">{{ payment.party_name }}</p>
                    </div>
                </div>
                <div v-if="form.party_type && form.party_id" class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <FormInput v-model="form.payment_date" type="date" :label="isReceipt ? 'Received on' : 'Paid on'" required :max="today" :error="form.errors.payment_date" />
                    <FormSelect v-model="form.mode" label="Mode" required :options="modes" :error="form.errors.mode" />
                    <FormInput v-model="form.bank_reference" label="Reference (UTR / cheque no.)" maxlength="100" :error="form.errors.bank_reference" />
                    <DecimalInput v-model="form.amount" label="Amount" required prefix="₹" :error="form.errors.amount" />
                    <DecimalInput v-model="form.tds_amount" label="TDS (for reference)" prefix="₹" help="Informational; allocations settle net amounts." :error="form.errors.tds_amount" />
                    <FormInput v-model="form.remarks" label="Remarks" maxlength="1000" class="sm:col-span-2 lg:col-span-3" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard v-if="form.party_type && form.party_id" title="Allocation" :subtitle="isLabour ? 'A labour batch must be settled in full.' : 'Allocate to open documents. Any remainder stays on account (for a client: an advance).'" :padded="false">
                <template #actions>
                    <AppButton v-if="payables.length" size="sm" variant="secondary" :disabled="!dec(form.amount).gt(0)" @click="autoAllocate">Auto-allocate</AppButton>
                </template>
                <div v-if="payables.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-line bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">Document</th>
                                <th class="px-3 py-2 text-right font-medium">Due</th>
                                <th class="px-3 py-2 text-right font-medium">Settled</th>
                                <th class="px-3 py-2 text-right font-medium">Outstanding</th>
                                <th class="px-4 py-2 text-left font-medium">Allocate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(row, i) in payables" :key="row.id">
                                <td class="min-w-40 px-4 py-2 font-medium text-slate-900">{{ row.label }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(row.due) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(row.settled) }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular">{{ formatMoney(row.outstanding) }}</td>
                                <td class="min-w-40 px-4 py-2">
                                    <DecimalInput v-model="form.allocations[i].amount" prefix="₹" :aria-label="`Allocate to ${row.label}`" :error="form.errors[`allocations.${i}.amount`] || form.errors[`allocations.${i}.payable_id`]" />
                                    <p v-if="overOutstanding(i)" class="mt-0.5 text-xs font-medium text-red-700">More than outstanding</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else icon="receipt" title="No open documents" :description="isReceipt ? 'No certified RA bill is outstanding. The receipt will be held as a client advance.' : 'Nothing outstanding for this party. The payment will stay on account.'" />
                <dl class="flex flex-wrap justify-end gap-x-8 gap-y-1 border-t border-line px-4 py-3 text-sm">
                    <div class="flex gap-2"><dt class="text-slate-500">Allocated</dt><dd class="font-medium tabular">{{ formatMoney(allocated.toFixed(2)) }}</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500">{{ isReceipt ? 'Advance / on account' : 'On account' }}</dt><dd class="font-medium tabular" :class="unallocated.isNegative() ? 'text-red-700' : ''">{{ formatMoney(unallocated.toFixed(2)) }}</dd></div>
                </dl>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.payments.show', [project.id, payment.id]) : route('projects.payments.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!form.party_type || !form.party_id" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
