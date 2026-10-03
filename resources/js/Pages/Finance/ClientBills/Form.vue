<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
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
    defaults: { type: Object, required: true },
    client: { type: Object, default: null },
    taxType: { type: String, default: null },
    lines: { type: Array, required: true },
    advanceBalance: { type: String, required: true },
    taxRates: { type: Array, required: true },
    canOverride: { type: Boolean, default: false },
    today: { type: String, required: true },
});

const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};
const nz = (v) => (v === null || v === undefined || dec(v).isZero() ? null : v);

const editing = computed(() => !!props.bill);
const b = props.bill;
const form = useForm({
    invoice_date: b?.invoice_date ?? props.today,
    period_from: b?.period_from ?? props.defaults.period_from,
    period_to: props.defaults.period_to,
    tax_rate_id: b?.tax_rate_id ?? props.defaults.tax_rate_id ?? null,
    retention_percent: nz(b?.retention_percent ?? props.defaults.retention_percent),
    tds_percent: nz(b?.tds_percent ?? props.defaults.tds_percent),
    advance_recovery: nz(b?.advance_recovery),
    other_deductions: nz(b?.other_deductions),
    remarks: b?.remarks ?? '',
    items: props.lines.map((l) => ({ boq_item_id: l.boq_item_id, current_qty: nz(l.current_qty), override_reason: l.override_reason ?? '' })),
});

function reloadExecuted() {
    const name = editing.value ? 'projects.ra-bills.edit' : 'projects.ra-bills.create';
    const params = editing.value ? [props.project.id, props.bill.id] : props.project.id;
    router.get(route(name, params), { period_to: form.period_to }, { preserveScroll: true });
}

const money = (d) => d.toDecimalPlaces(2, Decimal.ROUND_HALF_UP);
/** Preview only: the server recomputes every amount and re-checks every cap on save, submit and certification. */
const lineAmount = (line, i) => money(dec(form.items[i]?.current_qty).times(dec(line.rate)));
const overLimit = (line, i) => dec(form.items[i]?.current_qty).greaterThan(dec(line.balance_qty));
const gross = computed(() => props.lines.reduce((s, l, i) => s.plus(lineAmount(l, i)), new Decimal(0)));
const taxRate = computed(() => props.taxRates.find((t) => t.value === form.tax_rate_id) ?? null);
const tax = computed(() => {
    const t = taxRate.value;
    if (!t) {
        return new Decimal(0);
    }

    return props.taxType === 'inter'
        ? money(gross.value.times(dec(t.igst_rate)).dividedBy(100))
        : money(gross.value.times(dec(t.cgst_rate)).dividedBy(100)).plus(money(gross.value.times(dec(t.sgst_rate)).dividedBy(100)));
});
const pct = (p) => money(gross.value.times(dec(p)).dividedBy(100));
const retention = computed(() => pct(form.retention_percent));
const tds = computed(() => pct(form.tds_percent));
const net = computed(() => gross.value.plus(tax.value).minus(retention.value).minus(dec(form.advance_recovery)).minus(tds.value).minus(dec(form.other_deductions)));
const lineError = (i, field) => form.errors[`items.${i}.${field}`];

function submit() {
    const transform = (d) => ({
        ...d,
        remarks: d.remarks || null,
        items: d.items.map((it) => ({ ...it, override_reason: it.override_reason || null })),
    });
    editing.value
        ? form.transform(transform).put(route('projects.ra-bills.update', [props.project.id, props.bill.id]))
        : form.transform(transform).post(route('projects.ra-bills.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="editing ? `RA bill ${bill.ra_sequence}` : 'New RA bill'">
        <FinanceNav :project-id="project.id" active="ra-bills" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.ra-bills.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Client RA bills</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit RA bill ${bill.ra_sequence}` : 'New RA bill' }}</h2>
                    <p class="text-xs text-slate-500">
                        Client: {{ client?.name ?? 'not set on the project' }} ·
                        GST {{ taxType === 'inter' ? 'IGST (inter-state)' : taxType === 'intra' ? 'CGST + SGST (intra-state)' : 'set the company and project GST states' }}
                    </p>
                </div>
                <p v-if="form.errors.bill || form.errors.items" class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ form.errors.bill || form.errors.items }}</p>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <FormInput v-model="form.invoice_date" type="date" label="Bill date" required :max="today" :error="form.errors.invoice_date" />
                    <FormInput v-model="form.period_from" type="date" label="Work from" required :error="form.errors.period_from" />
                    <FormInput v-model="form.period_to" type="date" label="Work to" required :min="form.period_from" help="Executed quantity is progress recorded up to this date." :error="form.errors.period_to" />
                    <div class="flex items-end">
                        <AppButton variant="secondary" size="sm" :disabled="form.period_to === defaults.period_to" @click="reloadExecuted">Reload executed qty</AppButton>
                    </div>
                </div>
            </AppCard>

            <AppCard v-if="lines.length" title="Measurement" subtitle="Previous (certified on earlier RA bills) + this bill = cumulative. Cumulative may not exceed executed or BOQ quantity." :padded="false">
                <div class="hidden grid-cols-12 gap-3 border-b border-line bg-slate-50 px-4 py-2 text-xs font-medium text-slate-500 lg:grid">
                    <span class="col-span-4">BOQ item</span>
                    <span class="col-span-1 text-right">BOQ</span>
                    <span class="col-span-1 text-right">Executed</span>
                    <span class="col-span-1 text-right">Previous</span>
                    <span class="col-span-2">This bill</span>
                    <span class="col-span-1 text-right">Cumulative</span>
                    <span class="col-span-2 text-right">Amount</span>
                </div>
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in lines" :key="line.boq_line_uid" class="grid grid-cols-2 gap-3 p-4 lg:grid-cols-12 lg:items-center">
                        <div class="col-span-2 min-w-0 lg:col-span-4">
                            <p class="text-sm font-medium text-slate-900"><span v-if="line.item_code" class="font-mono text-xs text-slate-500">{{ line.item_code }} </span>{{ line.description }}</p>
                            <p class="text-xs text-slate-500 tabular">@ {{ formatRate(line.rate) }} / {{ line.unit }}</p>
                            <p class="text-xs text-slate-500 tabular lg:hidden">BOQ {{ formatQty(line.boq_qty) }} · executed {{ formatQty(line.executed_qty) }} · previous {{ formatQty(line.previous_qty) }}</p>
                        </div>
                        <span class="hidden text-right text-sm tabular lg:col-span-1 lg:block">{{ formatQty(line.boq_qty) }}</span>
                        <span class="hidden text-right text-sm tabular lg:col-span-1 lg:block">{{ formatQty(line.executed_qty) }}</span>
                        <span class="hidden text-right text-sm tabular lg:col-span-1 lg:block">{{ formatQty(line.previous_qty) }}</span>
                        <div class="lg:col-span-2">
                            <DecimalInput v-model="form.items[i].current_qty" :decimals="4" :suffix="line.unit" :aria-label="`This bill quantity for ${line.description}`" :error="lineError(i, 'current_qty') || lineError(i, 'boq_item_id')" />
                            <p class="mt-1 text-xs tabular" :class="overLimit(line, i) ? 'font-medium text-red-700' : 'text-slate-500'">Billable {{ formatQty(line.balance_qty) }}</p>
                        </div>
                        <span class="text-right text-sm tabular lg:col-span-1">{{ formatQty(dec(line.previous_qty).plus(dec(form.items[i].current_qty)).toString()) }}</span>
                        <span class="text-right text-sm font-semibold tabular lg:col-span-2">{{ formatMoney(lineAmount(line, i).toFixed(2)) }}</span>
                        <div v-if="overLimit(line, i) || form.items[i].override_reason" class="col-span-2 lg:col-span-12">
                            <FormInput
                                v-if="canOverride"
                                v-model="form.items[i].override_reason"
                                label="Override justification"
                                required
                                maxlength="500"
                                help="Billing beyond executed / BOQ quantity is audited. At least 10 characters."
                                :error="lineError(i, 'override_reason')"
                            />
                            <p v-else class="text-xs text-red-700">Exceeds the billable quantity. Only users allowed to override billing quantities can bill beyond it.</p>
                        </div>
                    </li>
                </ol>
            </AppCard>
            <AppCard v-else>
                <EmptyState icon="clipboard" title="No approved BOQ" description="Client billing needs a current approved BOQ with client rates." />
            </AppCard>

            <div v-if="lines.length" class="grid gap-4 lg:grid-cols-2">
                <AppCard title="GST & deductions">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <SearchSelect v-model="form.tax_rate_id" label="GST rate" :options="taxRates" class="sm:col-span-2" :error="form.errors.tax_rate_id" />
                        <DecimalInput v-model="form.retention_percent" label="Retention" suffix="%" :decimals="4" :error="form.errors.retention_percent" />
                        <DecimalInput v-model="form.tds_percent" label="TDS" suffix="%" :decimals="4" :error="form.errors.tds_percent" />
                        <DecimalInput v-model="form.advance_recovery" label="Advance recovery" prefix="₹" :help="`Unadjusted client advance ${formatMoney(advanceBalance)}`" :error="form.errors.advance_recovery" />
                        <DecimalInput v-model="form.other_deductions" label="Other deductions" prefix="₹" :error="form.errors.other_deductions" />
                        <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="1000" class="sm:col-span-2" :error="form.errors.remarks" />
                    </div>
                </AppCard>
                <AppCard title="Bill preview" subtitle="Retention and TDS are calculated on the work value.">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Work value this bill</dt><dd class="tabular">{{ formatMoney(gross.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">GST {{ taxRate ? formatPercent(taxRate.rate) : '' }}</dt><dd class="tabular">{{ formatMoney(tax.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between font-medium"><dt>Invoice total</dt><dd class="tabular">{{ formatMoney(gross.plus(tax).toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Retention {{ formatPercent(form.retention_percent || 0) }}</dt><dd class="tabular">-{{ formatMoney(retention.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Advance recovery</dt><dd class="tabular">-{{ formatMoney(dec(form.advance_recovery).toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">TDS {{ formatPercent(form.tds_percent || 0) }}</dt><dd class="tabular">-{{ formatMoney(tds.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Other deductions</dt><dd class="tabular">-{{ formatMoney(dec(form.other_deductions).toFixed(2)) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Net receivable</dt><dd class="tabular" :class="net.isNegative() ? 'text-red-700' : ''">{{ formatMoney(net.toFixed(2)) }}</dd></div>
                    </dl>
                </AppCard>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.ra-bills.show', [project.id, bill.id]) : route('projects.ra-bills.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!lines.length" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
