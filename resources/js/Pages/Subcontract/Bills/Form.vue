<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import SubcontractNav from '@/Components/Subcontract/SubcontractNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    bill: { type: Object, default: null },
    workOrders: { type: Array, required: true },
    workOrder: { type: Object, default: null },
    today: { type: String, required: true },
});

const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};

const editing = computed(() => !!props.bill);
const form = useForm({
    work_order_id: props.workOrder?.id ?? null,
    bill_date: props.bill?.bill_date ?? props.today,
    subcontractor_invoice_no: props.bill?.subcontractor_invoice_no ?? '',
    period_from: props.bill?.period_from ?? null,
    period_to: props.bill?.period_to ?? props.today,
    remarks: props.bill?.remarks ?? '',
    advance_recovery: props.bill && props.bill.advance_recovery !== '0.00' ? props.bill.advance_recovery : null,
    other_deductions: props.bill && props.bill.other_deductions !== '0.00' ? props.bill.other_deductions : null,
    items: (props.workOrder?.lines ?? []).map((l) => ({ work_order_item_id: l.id, claimed_qty: dec(l.claimed_qty).isZero() ? null : l.claimed_qty })),
});

function chooseWorkOrder(id) {
    if (!editing.value && id && id !== props.workOrder?.id) {
        router.get(route('projects.subcontractor-bills.create', props.project.id), { work_order_id: id }, { preserveScroll: true });
    }
}

const money = (d) => d.toDecimalPlaces(2, Decimal.ROUND_HALF_UP);
/** Preview only: the server recalculates and validates every amount on save, submit and certification. */
const lineAmount = (line, i) => money(dec(form.items[i]?.claimed_qty).times(dec(line.rate)));
const gross = computed(() => (props.workOrder?.lines ?? []).reduce((s, l, i) => s.plus(lineAmount(l, i)), new Decimal(0)));
const pct = (p) => money(gross.value.times(dec(p)).dividedBy(100));
const tax = computed(() => pct(props.workOrder?.tax_percent));
const retention = computed(() => pct(props.workOrder?.retention_percent));
const tds = computed(() => pct(props.workOrder?.tds_percent));
const net = computed(() => gross.value.plus(tax.value).minus(retention.value).minus(dec(form.advance_recovery)).minus(tds.value).minus(dec(form.other_deductions)));
const lineError = (i, field) => form.errors[`items.${i}.${field}`];
const overBalance = (line, i) => dec(form.items[i]?.claimed_qty).greaterThan(dec(line.balance_qty));

function submit() {
    const transform = (d) => {
        const data = { ...d, subcontractor_invoice_no: d.subcontractor_invoice_no || null, remarks: d.remarks || null };
        if (editing.value) {
            delete data.work_order_id;
        }

        return data;
    };
    editing.value
        ? form.transform(transform).put(route('projects.subcontractor-bills.update', [props.project.id, props.bill.id]))
        : form.transform(transform).post(route('projects.subcontractor-bills.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="subcontract" :title="editing ? bill.bill_number : 'New subcontractor bill'">
        <SubcontractNav :project-id="project.id" active="bills" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.subcontractor-bills.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Subcontractor bills</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit ${bill.bill_number}` : 'New subcontractor bill' }}</h2>
                    <p class="text-xs text-slate-500">Claim the quantity executed in this period. Certification posts the certified amount (excluding GST) to the project cost.</p>
                </div>
                <p v-if="form.errors.bill" class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ form.errors.bill }}</p>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <SearchSelect v-if="!editing" :model-value="form.work_order_id" label="Work order" required :options="workOrders" class="sm:col-span-2" :error="form.errors.work_order_id" @update:model-value="chooseWorkOrder" />
                    <div v-else class="sm:col-span-2">
                        <p class="text-xs font-medium text-slate-700">Work order</p>
                        <p class="mt-2 font-mono text-sm">{{ workOrder?.wo_number }}</p>
                    </div>
                    <div v-if="workOrder" class="sm:col-span-2">
                        <p class="text-xs font-medium text-slate-700">Subcontractor</p>
                        <p class="mt-2 text-sm">{{ workOrder.subcontractor }}</p>
                    </div>
                    <FormInput v-model="form.bill_date" type="date" label="Bill date" required :max="today" :error="form.errors.bill_date" />
                    <FormInput v-model="form.subcontractor_invoice_no" label="Subcontractor invoice no." maxlength="60" :error="form.errors.subcontractor_invoice_no" />
                    <FormInput v-model="form.period_from" type="date" label="Work from" required :error="form.errors.period_from" />
                    <FormInput v-model="form.period_to" type="date" label="Work to" required :min="form.period_from" :error="form.errors.period_to" />
                </div>
            </AppCard>

            <AppCard v-if="workOrder" title="Quantities" subtitle="Previous = already certified on earlier bills; pending = on bills awaiting certification." :padded="false">
                <p v-if="form.errors.items" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items }}</p>
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in workOrder.lines" :key="line.id" class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-6">
                        <div class="col-span-2 min-w-0 sm:col-span-3">
                            <p class="font-medium text-slate-900">{{ line.description }}</p>
                            <p class="text-xs text-slate-500 tabular">
                                WO {{ formatQty(line.quantity) }} {{ line.unit }} @ {{ formatRate(line.rate) }} · previous {{ formatQty(line.previous_qty) }}<template v-if="line.pending_qty !== '0.0000'"> · pending {{ formatQty(line.pending_qty) }}</template>
                            </p>
                            <p class="text-xs font-medium tabular" :class="overBalance(line, i) ? 'text-red-700' : 'text-slate-600'">Balance {{ formatQty(line.balance_qty) }} {{ line.unit }}</p>
                        </div>
                        <DecimalInput v-model="form.items[i].claimed_qty" label="Claimed this bill" :decimals="4" :suffix="line.unit" class="sm:col-span-2" :error="lineError(i, 'claimed_qty') || lineError(i, 'work_order_item_id')" />
                        <div class="flex items-end justify-end text-sm text-slate-600">
                            <span class="font-semibold text-slate-900 tabular">{{ formatMoney(lineAmount(line, i).toFixed(2)) }}</span>
                        </div>
                    </li>
                </ol>
            </AppCard>
            <AppCard v-else>
                <EmptyState icon="briefcase" title="Choose a work order" description="Bills are raised against approved, in-progress or completed work orders." />
            </AppCard>

            <div v-if="workOrder" class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Deductions & remarks">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <DecimalInput v-model="form.advance_recovery" label="Advance recovery" prefix="₹" :help="`Unrecovered advance ${formatMoney(workOrder.advance_balance)}`" :error="form.errors.advance_recovery" />
                        <DecimalInput v-model="form.other_deductions" label="Other deductions" prefix="₹" :error="form.errors.other_deductions" />
                        <FormInput v-model="form.remarks" label="Remarks" multiline :rows="3" maxlength="1000" class="sm:col-span-2" :error="form.errors.remarks" />
                    </div>
                </AppCard>
                <AppCard title="Bill preview" subtitle="Percentages are taken from the work order.">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Gross (work done)</dt><dd class="tabular">{{ formatMoney(gross.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">GST {{ formatPercent(workOrder.tax_percent) }}</dt><dd class="tabular">{{ formatMoney(tax.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Retention {{ formatPercent(workOrder.retention_percent) }}</dt><dd class="tabular">-{{ formatMoney(retention.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Advance recovery</dt><dd class="tabular">-{{ formatMoney(dec(form.advance_recovery).toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">TDS {{ formatPercent(workOrder.tds_percent) }}</dt><dd class="tabular">-{{ formatMoney(tds.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Other deductions</dt><dd class="tabular">-{{ formatMoney(dec(form.other_deductions).toFixed(2)) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Net payable</dt><dd class="tabular" :class="net.isNegative() ? 'text-red-700' : ''">{{ formatMoney(net.toFixed(2)) }}</dd></div>
                    </dl>
                </AppCard>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.subcontractor-bills.show', [project.id, bill.id]) : route('projects.subcontractor-bills.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!workOrder" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
