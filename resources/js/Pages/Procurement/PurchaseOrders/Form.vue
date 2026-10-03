<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatQty } from '@/lib/format';
import { orderLine, orderTotals, str } from '@/lib/gstPreview';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    order: { type: Object, default: null },
    lines: { type: Array, required: true },
    vendors: { type: Array, required: true },
    taxRates: { type: Array, required: true },
    states: { type: Array, required: true },
    defaults: { type: Object, required: true },
});

const editing = computed(() => !!props.order);
const direct = computed(() => !editing.value || props.order.is_direct);
const lineById = Object.fromEntries(props.lines.map((l) => [l.id, l]));
let tempId = 0;

const form = useForm({
    vendor_id: null,
    direct_justification: props.order?.direct_justification ?? '',
    po_date: props.order?.po_date ?? props.defaults.today,
    delivery_date: props.order?.delivery_date ?? null,
    place_of_supply_state: props.order?.place_of_supply_state ?? props.defaults.place_of_supply_state ?? null,
    billing_address: props.order?.billing_address ?? '',
    shipping_address: props.order?.shipping_address ?? props.defaults.shipping_address ?? '',
    payment_terms: props.order?.payment_terms ?? '',
    terms: props.order?.terms ?? '',
    remarks: props.order?.remarks ?? '',
    freight_amount: props.order?.freight_amount ?? null,
    other_charges: props.order?.other_charges ?? null,
    items: (props.order?.items ?? []).map((i) => ({ _key: `i${i.id}`, ...i, discount_percent: i.discount_percent ?? '0' })),
});

const vendorState = computed(() => (editing.value ? props.order.vendor?.state_code : props.vendors.find((v) => v.value === form.vendor_id)?.state_code) ?? null);
const intra = computed(() => !!vendorState.value && vendorState.value === form.place_of_supply_state);
const taxTypeNote = computed(() => {
    if (!vendorState.value) {
        return editing.value || form.vendor_id ? 'The vendor has no GST state in the vendor master. Update the vendor before saving.' : 'Select a vendor to see the GST type.';
    }
    if (!form.place_of_supply_state) {
        return 'Select the place of supply.';
    }

    return intra.value ? `Intra-state (vendor state ${vendorState.value} = place of supply): CGST + SGST` : `Inter-state (vendor state ${vendorState.value} ≠ place of supply ${form.place_of_supply_state}): IGST`;
});

const taxRateById = (id) => props.taxRates.find((t) => t.value === id) ?? null;
const taxOptions = computed(() => props.taxRates.map((t) => ({ value: t.value, label: t.label })));
const preview = computed(() => form.items.map((i) => orderLine(i.quantity, i.rate, i.discount_percent, taxRateById(i.tax_rate_id), intra.value)));
const totals = computed(() => orderTotals(preview.value, form.freight_amount, form.other_charges));

// Adding lines from approved material requests (direct POs)
const lineToAdd = ref(null);
const addable = computed(() =>
    props.lines
        .filter((l) => !form.items.some((i) => i.material_request_item_id === l.id))
        .map((l) => ({ value: l.id, label: l.material?.name ?? `Line ${l.id}`, description: `${l.request_number} · open ${formatQty(l.remaining_qty)} ${l.unit ?? ''}` })),
);
function addLine(id) {
    const line = lineById[id];
    if (line) {
        form.items.push({
            _key: `n${++tempId}`,
            id: null,
            material_request_item_id: line.id,
            item_code: line.material?.code,
            description: line.material?.name,
            hsn_sac: line.hsn_sac ?? '',
            quantity: line.remaining_qty,
            rate: null,
            discount_percent: '0',
            tax_rate_id: line.tax_rate_id,
            unit: line.unit,
            material_request: line.request_number,
            received_qty: '0',
        });
    }
    lineToAdd.value = null;
}
const lineError = (index, field) => form.errors[`items.${index}.${field}`];
const openQty = (item) => (item.material_request_item_id && lineById[item.material_request_item_id] ? lineById[item.material_request_item_id].remaining_qty : null);

function submit() {
    const transform = (d) => {
        const data = {
            ...d,
            billing_address: d.billing_address || null,
            shipping_address: d.shipping_address || null,
            payment_terms: d.payment_terms || null,
            terms: d.terms || null,
            remarks: d.remarks || null,
            items: d.items.map((i) => ({
                id: i.id ?? null,
                material_request_item_id: i.material_request_item_id ?? null,
                description: i.description || null,
                hsn_sac: i.hsn_sac || null,
                quantity: i.quantity,
                rate: i.rate,
                discount_percent: i.discount_percent || '0',
                tax_rate_id: i.tax_rate_id ?? null,
            })),
        };
        if (editing.value) {
            delete data.vendor_id;
        }
        if (!direct.value) {
            delete data.direct_justification;
        }

        return data;
    };
    if (editing.value) {
        form.transform(transform).put(route('projects.purchase-orders.update', [props.project.id, props.order.id]));
    } else {
        form.transform(transform).post(route('projects.purchase-orders.store', props.project.id));
    }
}
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="editing ? order.po_number : 'Direct purchase order'">
        <ProcurementNav :project-id="project.id" active="purchase-orders" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.purchase-orders.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Purchase orders</Link>
                    <h2 class="text-lg font-semibold text-slate-900">
                        <template v-if="editing">Edit {{ order.po_number }}<span v-if="order.revision_no > 0" class="ml-2 text-sm font-normal text-slate-500">revision {{ order.revision_no }}</span></template>
                        <template v-else>Direct purchase order</template>
                    </h2>
                    <p v-if="editing" class="text-xs text-slate-500">Vendor: <span class="font-medium text-slate-700">{{ order.vendor?.name }}</span></p>
                    <p v-else class="text-xs text-slate-500">Use a direct PO only when an RFQ is not practical. Lines must come from approved material requests, and a justification is required.</p>
                </div>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <SearchSelect v-if="!editing" v-model="form.vendor_id" label="Vendor" required :options="vendors" class="sm:col-span-2" :error="form.errors.vendor_id" />
                    <FormInput v-model="form.po_date" type="date" label="PO date" required :error="form.errors.po_date" />
                    <FormInput v-model="form.delivery_date" type="date" label="Delivery by" :min="form.po_date" :error="form.errors.delivery_date" />
                    <FormSelect v-model="form.place_of_supply_state" label="Place of supply" required :options="states" :error="form.errors.place_of_supply_state" />
                    <FormInput v-model="form.payment_terms" label="Payment terms" maxlength="255" :error="form.errors.payment_terms" />
                    <div class="flex items-end sm:col-span-2">
                        <p class="w-full rounded-lg px-3 py-2 text-xs" :class="vendorState && form.place_of_supply_state ? 'bg-brand-50 text-brand-800' : 'bg-amber-50 text-amber-800'">
                            <Icon name="receipt" :size="14" class="mr-1 inline align-text-bottom" />{{ taxTypeNote }}
                        </p>
                    </div>
                    <FormInput v-if="direct" v-model="form.direct_justification" label="Why a direct PO?" required multiline :rows="2" maxlength="2000" class="sm:col-span-2 lg:col-span-4" :error="form.errors.direct_justification" />
                    <FormInput v-model="form.billing_address" label="Billing address" multiline :rows="3" class="sm:col-span-2" :placeholder="editing ? '' : 'Leave empty to use the company address'" :error="form.errors.billing_address" />
                    <FormInput v-model="form.shipping_address" label="Shipping address" multiline :rows="3" class="sm:col-span-2" :error="form.errors.shipping_address" />
                </div>
            </AppCard>

            <AppCard title="Lines" :subtitle="`${form.items.length} line(s) · amounts are a preview; the server recalculates GST when you save`" :padded="false">
                <p v-if="form.errors.items || form.errors.purchase_order" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items || form.errors.purchase_order }}</p>
                <div v-if="direct" class="border-b border-line p-4">
                    <SearchSelect :model-value="lineToAdd" label="Add an approved material request line" placeholder="Search material or request…" :options="addable" @update:model-value="addLine" />
                </div>
                <EmptyState v-if="!form.items.length" icon="cube" title="No lines" :description="direct ? 'Add material request lines above.' : 'This order has no lines.'" />
                <ol v-else class="divide-y divide-line">
                    <li v-for="(item, index) in form.items" :key="item._key" class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs text-slate-500">
                                    <span class="font-mono">{{ item.item_code }}</span>
                                    <template v-if="item.material_request"> · {{ item.material_request }}</template>
                                    <template v-if="openQty(item) !== null"> · open {{ formatQty(openQty(item)) }} {{ item.unit }}</template>
                                    <template v-if="item.received_qty && item.received_qty !== '0.0000' && item.received_qty !== '0'"> · received {{ formatQty(item.received_qty) }}</template>
                                </p>
                            </div>
                            <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove line" @click="form.items.splice(index, 1)">
                                <Icon name="trash" :size="16" />
                            </button>
                        </div>
                        <div class="mt-1 grid grid-cols-2 gap-3 sm:grid-cols-6 lg:grid-cols-12">
                            <FormInput v-model="item.description" label="Description" maxlength="255" class="col-span-2 sm:col-span-4 lg:col-span-4" :error="lineError(index, 'description') || lineError(index, 'material_request_item_id')" />
                            <FormInput v-model="item.hsn_sac" label="HSN/SAC" maxlength="10" class="sm:col-span-2 lg:col-span-2" :error="lineError(index, 'hsn_sac')" />
                            <DecimalInput v-model="item.quantity" label="Quantity" required :decimals="4" :suffix="item.unit" class="lg:col-span-2" :error="lineError(index, 'quantity')" />
                            <DecimalInput v-model="item.rate" label="Rate" required :decimals="4" prefix="₹" class="sm:col-span-2 lg:col-span-2" :error="lineError(index, 'rate')" />
                            <DecimalInput v-model="item.discount_percent" label="Disc." :decimals="4" suffix="%" class="lg:col-span-1" :error="lineError(index, 'discount_percent')" />
                            <FormSelect v-model="item.tax_rate_id" label="GST" placeholder="None" :options="taxOptions" class="sm:col-span-2 lg:col-span-1" :error="lineError(index, 'tax_rate_id')" />
                        </div>
                        <dl class="mt-2 flex flex-wrap justify-end gap-x-5 gap-y-1 text-xs text-slate-600">
                            <div>Taxable <span class="font-medium text-slate-800 tabular">{{ formatMoney(str(preview[index].taxable)) }}</span></div>
                            <template v-if="intra">
                                <div>CGST <span class="tabular">{{ formatMoney(str(preview[index].cgst)) }}</span></div>
                                <div>SGST <span class="tabular">{{ formatMoney(str(preview[index].sgst)) }}</span></div>
                            </template>
                            <div v-else>IGST <span class="tabular">{{ formatMoney(str(preview[index].igst)) }}</span></div>
                            <div>Amount <span class="font-semibold text-slate-900 tabular">{{ formatMoney(str(preview[index].amount)) }}</span></div>
                        </dl>
                    </li>
                </ol>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Charges, terms & remarks">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <DecimalInput v-model="form.freight_amount" label="Freight (no GST)" prefix="₹" :error="form.errors.freight_amount" />
                        <DecimalInput v-model="form.other_charges" label="Other charges (no GST)" prefix="₹" :error="form.errors.other_charges" />
                        <FormInput v-model="form.terms" label="Terms & conditions" multiline :rows="4" class="sm:col-span-2" :error="form.errors.terms" />
                        <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" class="sm:col-span-2" :error="form.errors.remarks" />
                    </div>
                </AppCard>
                <AppCard title="Totals" subtitle="Preview. Round-off brings the total to the nearest rupee.">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Sub total</dt><dd class="tabular">{{ formatMoney(str(totals.base)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="tabular">-{{ formatMoney(str(totals.discount)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Taxable value</dt><dd class="tabular">{{ formatMoney(str(totals.taxable)) }}</dd></div>
                        <template v-if="intra">
                            <div class="flex justify-between"><dt class="text-slate-500">CGST</dt><dd class="tabular">{{ formatMoney(str(totals.cgst)) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">SGST</dt><dd class="tabular">{{ formatMoney(str(totals.sgst)) }}</dd></div>
                        </template>
                        <div v-else class="flex justify-between"><dt class="text-slate-500">IGST</dt><dd class="tabular">{{ formatMoney(str(totals.igst)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Freight & other</dt><dd class="tabular">{{ formatMoney(str(totals.freight.plus(totals.other))) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Round off</dt><dd class="tabular">{{ formatMoney(str(totals.roundOff)) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Grand total</dt><dd class="tabular">{{ formatMoney(str(totals.grand)) }}</dd></div>
                    </dl>
                </AppCard>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.purchase-orders.show', [project.id, order.id]) : route('projects.purchase-orders.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!form.items.length" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
