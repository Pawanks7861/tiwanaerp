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
import { formatDate, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    billType: { type: String, required: true },
    bill: { type: Object, default: null },
    purchaseOrders: { type: Array, required: true },
    purchaseOrder: { type: Object, default: null },
    vendors: { type: Array, required: true },
    units: { type: Array, required: true },
    taxRates: { type: Array, required: true },
    tasks: { type: Array, required: true },
    boqItems: { type: Array, required: true },
    costHeads: { type: Array, required: true },
    projectState: { type: String, default: null },
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
const direct = computed(() => props.billType === 'direct');
const b = props.bill;
const blankLine = () => ({ description: '', hsn_sac: '', unit_id: null, quantity: null, rate: null, discount_percent: null, tax_rate_id: null });
const form = useForm({
    bill_type: props.billType,
    purchase_order_id: props.purchaseOrder?.id ?? null,
    vendor_id: b?.vendor_id ?? null,
    cost_head: b?.cost_head ?? null,
    task_id: b?.task_id ?? null,
    boq_item_id: b?.boq_item_id ?? null,
    vendor_invoice_no: b?.vendor_invoice_no ?? '',
    vendor_invoice_date: b?.vendor_invoice_date ?? props.today,
    due_date: b?.due_date ?? null,
    tds_percent: nz(b?.tds_percent),
    remarks: b?.remarks ?? '',
    items: direct.value
        ? (b?.items?.length ? b.items.map((i) => ({ ...i, discount_percent: nz(i.discount_percent) })) : [blankLine()])
        : (props.purchaseOrder?.lines ?? []).map((l) => ({ grn_item_id: l.grn_item_id, quantity: nz(l.quantity) })),
});

function choosePurchaseOrder(id) {
    if (!editing.value && id && id !== props.purchaseOrder?.id) {
        router.get(route('projects.vendor-bills.create', props.project.id), { bill_type: 'purchase_order', purchase_order_id: id }, { preserveScroll: true });
    }
}

const money = (d) => d.toDecimalPlaces(2, Decimal.ROUND_HALF_UP);
const vendorState = computed(() => props.vendors.find((v) => v.value === form.vendor_id)?.state_code ?? null);
const taxType = computed(() => {
    if (!direct.value) {
        return props.purchaseOrder?.tax_type ?? 'intra';
    }

    return vendorState.value && props.projectState && vendorState.value !== props.projectState ? 'inter' : 'intra';
});

/** Preview only: the server recomputes every line (rates from the PO for purchase bills) and validates quantities. */
function preview(qty, rate, discount, taxRateId) {
    const base = money(dec(qty).times(dec(rate)));
    const taxable = base.minus(money(base.times(dec(discount)).dividedBy(100)));
    const t = props.taxRates.find((r) => r.value === taxRateId);
    let tax = new Decimal(0);
    if (t) {
        tax = taxType.value === 'inter'
            ? money(taxable.times(dec(t.igst_rate)).dividedBy(100))
            : money(taxable.times(dec(t.cgst_rate)).dividedBy(100)).plus(money(taxable.times(dec(t.sgst_rate)).dividedBy(100)));
    }

    return { taxable, tax, amount: taxable.plus(tax) };
}
const lines = computed(() =>
    direct.value
        ? form.items.map((i) => preview(i.quantity, i.rate, i.discount_percent, i.tax_rate_id))
        : (props.purchaseOrder?.lines ?? []).map((l, i) => preview(form.items[i]?.quantity, l.rate, l.discount_percent, l.tax_rate_id)),
);
const subtotal = computed(() => lines.value.reduce((s, l) => s.plus(l.taxable), new Decimal(0)));
const taxTotal = computed(() => lines.value.reduce((s, l) => s.plus(l.tax), new Decimal(0)));
const total = computed(() => subtotal.value.plus(taxTotal.value));
const tds = computed(() => money(subtotal.value.times(dec(form.tds_percent)).dividedBy(100)));
const overBalance = (line, i) => dec(form.items[i]?.quantity).greaterThan(dec(line.balance_qty));
const lineError = (i, field) => form.errors[`items.${i}.${field}`];

function submit() {
    const transform = (d) => {
        const data = { ...d, remarks: d.remarks || null };
        if (direct.value) {
            delete data.purchase_order_id;
            data.items = d.items.map((i) => ({ ...i, hsn_sac: i.hsn_sac || null }));
        } else {
            ['vendor_id', 'cost_head', 'task_id', 'boq_item_id'].forEach((k) => delete data[k]);
        }
        if (editing.value) {
            delete data.bill_type;
            delete data.purchase_order_id;
        }

        return data;
    };
    editing.value
        ? form.transform(transform).put(route('projects.vendor-bills.update', [props.project.id, props.bill.id]))
        : form.transform(transform).post(route('projects.vendor-bills.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="editing ? bill.bill_number : 'New vendor bill'">
        <FinanceNav :project-id="project.id" active="vendor-bills" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.vendor-bills.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Vendor bills</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit ${bill.bill_number}` : direct ? 'New direct vendor bill' : 'New purchase bill' }}</h2>
                    <p class="text-xs text-slate-500">
                        <template v-if="direct">A direct bill (services, hire, utilities) is classified with a cost head and posted to the project cost on approval.</template>
                        <template v-else>Bill quantities are matched to accepted GRN quantities; rates and GST come from the purchase order. Purchase bills post no cost.</template>
                    </p>
                </div>
                <p v-if="form.errors.bill || form.errors.items" class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ form.errors.bill || form.errors.items }}</p>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <template v-if="!direct">
                        <SearchSelect v-if="!editing" :model-value="form.purchase_order_id" label="Purchase order" required :options="purchaseOrders" class="sm:col-span-2" help="Approved POs with at least one approved GRN." :error="form.errors.purchase_order_id" @update:model-value="choosePurchaseOrder" />
                        <div v-else class="sm:col-span-2">
                            <p class="text-xs font-medium text-slate-700">Purchase order</p>
                            <p class="mt-2 font-mono text-sm">{{ purchaseOrder?.po_number }}</p>
                        </div>
                        <div v-if="purchaseOrder" class="sm:col-span-2">
                            <p class="text-xs font-medium text-slate-700">Vendor</p>
                            <p class="mt-2 text-sm">{{ purchaseOrder.vendor }}</p>
                        </div>
                    </template>
                    <template v-else>
                        <SearchSelect v-model="form.vendor_id" label="Vendor" required :options="vendors" class="sm:col-span-2" :error="form.errors.vendor_id" />
                        <FormSelect v-model="form.cost_head" label="Cost head" required :options="costHeads" :error="form.errors.cost_head" />
                        <SearchSelect v-model="form.task_id" label="Task" :options="tasks" :error="form.errors.task_id" />
                        <SearchSelect v-model="form.boq_item_id" label="BOQ item" :options="boqItems" class="sm:col-span-2" :error="form.errors.boq_item_id" />
                    </template>
                    <FormInput v-model="form.vendor_invoice_no" label="Vendor invoice no." required maxlength="60" :error="form.errors.vendor_invoice_no" />
                    <FormInput v-model="form.vendor_invoice_date" type="date" label="Invoice date" required :max="today" :error="form.errors.vendor_invoice_date" />
                    <FormInput v-model="form.due_date" type="date" label="Due date" :min="form.vendor_invoice_date" :error="form.errors.due_date" />
                </div>
            </AppCard>

            <template v-if="!direct">
                <AppCard v-if="purchaseOrder" title="Three-way match" subtitle="PO quantity → GRN received / accepted → billed. Bill quantity cannot exceed accepted less already billed." :padded="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="border-b border-line bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium">GRN line</th>
                                    <th class="px-3 py-2 text-right font-medium">PO qty</th>
                                    <th class="px-3 py-2 text-right font-medium">Received</th>
                                    <th class="px-3 py-2 text-right font-medium">Accepted</th>
                                    <th class="px-3 py-2 text-right font-medium">Billed</th>
                                    <th class="px-3 py-2 text-left font-medium">This bill</th>
                                    <th class="px-3 py-2 text-right font-medium">Rate</th>
                                    <th class="px-4 py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="(line, i) in purchaseOrder.lines" :key="line.grn_item_id">
                                    <td class="min-w-48 px-4 py-2">
                                        <p class="font-medium text-slate-900">{{ line.material }}</p>
                                        <p class="text-xs text-slate-500"><span class="font-mono">{{ line.grn_number }}</span> · {{ formatDate(line.receipt_date) }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap tabular">{{ formatQty(line.po_qty) }} {{ line.unit }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(line.received_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(line.accepted_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(line.billed_qty) }}</td>
                                    <td class="min-w-36 px-3 py-2">
                                        <DecimalInput v-model="form.items[i].quantity" :decimals="4" :suffix="line.unit" :aria-label="`Bill quantity for ${line.material}`" :error="lineError(i, 'quantity') || lineError(i, 'grn_item_id')" />
                                        <p class="mt-0.5 text-xs tabular" :class="overBalance(line, i) ? 'font-medium text-red-700' : 'text-slate-500'">Balance {{ formatQty(line.balance_qty) }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap tabular">
                                        {{ formatRate(line.rate) }}
                                        <p v-if="dec(line.discount_percent).greaterThan(0)" class="text-xs text-slate-500">less {{ formatPercent(line.discount_percent) }}</p>
                                    </td>
                                    <td class="px-4 py-2 text-right font-semibold tabular">{{ formatMoney(lines[i].amount.toFixed(2)) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </AppCard>
                <AppCard v-else>
                    <EmptyState icon="truck" title="Choose a purchase order" description="Only goods received on an approved GRN can be billed." />
                </AppCard>
            </template>
            <AppCard v-else title="Lines" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(item, i) in form.items" :key="i" class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4 lg:grid-cols-8">
                        <FormInput v-model="item.description" label="Description" required maxlength="255" class="col-span-2 lg:col-span-3" :error="lineError(i, 'description')" />
                        <FormInput v-model="item.hsn_sac" label="HSN/SAC" maxlength="10" :error="lineError(i, 'hsn_sac')" />
                        <SearchSelect v-model="item.unit_id" label="Unit" required :options="units" :error="lineError(i, 'unit_id')" />
                        <DecimalInput v-model="item.quantity" label="Qty" required :decimals="4" :error="lineError(i, 'quantity')" />
                        <DecimalInput v-model="item.rate" label="Rate" required prefix="₹" :decimals="4" :error="lineError(i, 'rate')" />
                        <DecimalInput v-model="item.discount_percent" label="Disc." suffix="%" :decimals="4" :error="lineError(i, 'discount_percent')" />
                        <SearchSelect v-model="item.tax_rate_id" label="GST" :options="taxRates" class="col-span-2 sm:col-span-2" :error="lineError(i, 'tax_rate_id')" />
                        <div class="col-span-2 flex items-end justify-between gap-2 sm:col-span-2 lg:col-span-6">
                            <AppButton v-if="form.items.length > 1" size="sm" variant="ghost" icon="trash" :aria-label="`Remove line ${i + 1}`" @click="form.items.splice(i, 1)" />
                            <span class="ml-auto text-sm font-semibold tabular">{{ formatMoney(lines[i].amount.toFixed(2)) }}</span>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="secondary" icon="plus" @click="form.items.push(blankLine())">Add line</AppButton>
                </div>
            </AppCard>

            <div v-if="direct || purchaseOrder" class="grid gap-4 lg:grid-cols-2">
                <AppCard title="TDS & remarks">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <DecimalInput v-model="form.tds_percent" label="TDS" suffix="%" :decimals="4" help="On the taxable value." :error="form.errors.tds_percent" />
                        <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="1000" class="sm:col-span-2" :error="form.errors.remarks" />
                    </div>
                </AppCard>
                <AppCard title="Bill preview" :subtitle="taxType === 'inter' ? 'IGST (inter-state)' : 'CGST + SGST (intra-state)'">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Taxable value</dt><dd class="tabular">{{ formatMoney(subtotal.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">GST</dt><dd class="tabular">{{ formatMoney(taxTotal.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between font-medium"><dt>Invoice total</dt><dd class="tabular">{{ formatMoney(total.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">TDS {{ formatPercent(form.tds_percent || 0) }}</dt><dd class="tabular">-{{ formatMoney(tds.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Net payable</dt><dd class="tabular">{{ formatMoney(total.minus(tds).toFixed(2)) }}</dd></div>
                    </dl>
                    <p v-if="direct" class="mt-3 text-xs text-slate-500">Project cost on approval = taxable value {{ formatMoney(subtotal.toFixed(2)) }}.</p>
                </AppCard>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.vendor-bills.show', [project.id, bill.id]) : route('projects.vendor-bills.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!direct && !purchaseOrder" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
