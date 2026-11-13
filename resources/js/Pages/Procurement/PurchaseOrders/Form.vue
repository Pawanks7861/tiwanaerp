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
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    order: { type: Object, default: null },
    lines: { type: Array, required: true },
    vendors: { type: Array, required: true },
    taxRates: { type: Array, required: true },
    states: { type: Array, required: true },
    defaults: { type: Object, required: true },
    invoiceTo: { type: Object, required: true },
    termsTemplates: { type: Array, default: () => [] },
    prefillItemIds: { type: Array, default: () => [] },
});

const rfqEnabled = computed(() => usePage().props.features?.procurement_rfq === true);
const editing = computed(() => !!props.order);
const direct = computed(() => !editing.value || props.order.is_direct);
const lineById = Object.fromEntries(props.lines.map((l) => [l.id, l]));
let tempId = 0;

const form = useForm({
    vendor_id: null,
    direct_justification: props.order?.direct_justification ?? '',
    po_date: props.order?.po_date ?? props.defaults.today,
    delivery_date: props.order?.delivery_date ?? null,
    reference_number: props.order?.reference_number ?? '',
    reference_date: props.order?.reference_date ?? null,
    place_of_supply_state: props.order?.place_of_supply_state ?? props.defaults.place_of_supply_state ?? null,
    billing_address: props.order?.billing_address ?? '',
    shipping_address: props.order?.shipping_address ?? props.defaults.shipping_address ?? '',
    consignee_name: props.order?.consignee_name ?? props.defaults.consignee_name ?? '',
    consignee_email: props.order?.consignee_email ?? '',
    consignee_gstin: props.order?.consignee_gstin ?? '',
    payment_terms: props.order?.payment_terms ?? '',
    document_terms_template_id: props.order?.document_terms_template_id ?? null,
    terms: props.order?.terms ?? '',
    remarks: props.order?.remarks ?? '',
    other_references: props.order?.other_references ?? '',
    dispatched_through: props.order?.dispatched_through ?? '',
    destination: props.order?.destination ?? props.defaults.destination ?? '',
    delivery_terms: props.order?.delivery_terms ?? '',
    freight_amount: props.order?.freight_amount ?? null,
    other_charges: props.order?.other_charges ?? null,
    items: (props.order?.items ?? []).map((i) => ({
        _key: `i${i.id}`,
        ...i,
        discount_percent: i.discount_percent ?? '0',
        brand: i.brand ?? '',
        make: i.make ?? '',
        due_date: i.due_date ?? props.order?.delivery_date ?? null,
    })),
});

watch(
    () => form.document_terms_template_id,
    (id) => {
        const t = props.termsTemplates.find((x) => x.value === id);
        if (!t) return;
        if (!form.terms && t.terms) form.terms = t.terms;
        if (!form.payment_terms && t.payment_terms) form.payment_terms = t.payment_terms;
    },
);

watch(
    () => form.delivery_date,
    (date) => {
        if (!date) return;
        form.items.forEach((item) => {
            if (!item.due_date) item.due_date = date;
        });
    },
);

const vendorState = computed(() => (editing.value ? props.order.vendor?.state_code : props.vendors.find((v) => v.value === form.vendor_id)?.state_code) ?? null);
const selectedVendor = computed(() => (editing.value ? props.order.vendor : props.vendors.find((v) => v.value === form.vendor_id)) ?? null);
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
            brand: '',
            make: '',
            due_date: form.delivery_date ?? null,
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
if (!editing.value) {
    props.prefillItemIds.forEach((id) => addLine(id));
}
const lineError = (index, field) => form.errors[`items.${index}.${field}`];
const openQty = (item) => (item.material_request_item_id && lineById[item.material_request_item_id] ? lineById[item.material_request_item_id].remaining_qty : null);

function submit() {
    const transform = (d) => {
        const data = {
            ...d,
            billing_address: d.billing_address || null,
            shipping_address: d.shipping_address || null,
            consignee_name: d.consignee_name || null,
            consignee_email: d.consignee_email || null,
            consignee_gstin: d.consignee_gstin || null,
            payment_terms: d.payment_terms || null,
            document_terms_template_id: d.document_terms_template_id || null,
            terms: d.terms || null,
            remarks: d.remarks || null,
            other_references: d.other_references || null,
            reference_number: d.reference_number || null,
            reference_date: d.reference_date || null,
            dispatched_through: d.dispatched_through || null,
            destination: d.destination || null,
            delivery_terms: d.delivery_terms || null,
            items: d.items.map((i) => ({
                id: i.id ?? null,
                material_request_item_id: i.material_request_item_id ?? null,
                description: i.description || null,
                hsn_sac: i.hsn_sac || null,
                brand: i.brand || null,
                make: i.make || null,
                due_date: i.due_date || null,
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
    <ProjectLayout :project="project" active="procurement" :title="editing ? order.po_number : 'New purchase order'">
        <ProcurementNav :project-id="project.id" active="purchase-orders" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.purchase-orders.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Purchase orders</Link>
                    <h2 class="text-lg font-semibold text-slate-900">
                        <template v-if="editing">Edit {{ order.po_number }}<span v-if="order.revision_no > 0" class="ml-2 text-sm font-normal text-slate-500">revision {{ order.revision_no }}</span></template>
                        <template v-else>New purchase order</template>
                    </h2>
                    <p v-if="editing" class="text-xs text-slate-500">Vendor: <span class="font-medium text-slate-700">{{ order.vendor?.name }}</span></p>
                    <p v-else class="text-xs text-slate-500">Enter transactional data only — company, site, vendor bank and HSN are derived from masters.</p>
                </div>
            </AppCard>

            <AppCard title="1 · Basic details" :padded="false">
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <SearchSelect v-if="!editing" v-model="form.vendor_id" label="Vendor" required :options="vendors" class="sm:col-span-2" :error="form.errors.vendor_id" />
                    <FormInput v-model="form.po_date" type="date" label="PO date" required :error="form.errors.po_date" />
                    <FormInput v-model="form.delivery_date" type="date" label="Expected / due date" :min="form.po_date" :error="form.errors.delivery_date" />
                    <FormSelect v-model="form.place_of_supply_state" label="Place of supply" required :options="states" :error="form.errors.place_of_supply_state" />
                    <FormInput v-model="form.payment_terms" label="Mode / terms of payment" maxlength="255" placeholder="e.g. Against Delivery, 30 Days" :error="form.errors.payment_terms" />
                    <FormInput v-model="form.reference_number" label="Reference number" maxlength="100" :error="form.errors.reference_number" />
                    <FormInput v-model="form.reference_date" type="date" label="Reference date" :error="form.errors.reference_date" />
                    <div class="flex items-end sm:col-span-2 lg:col-span-4">
                        <p class="w-full rounded-lg px-3 py-2 text-xs" :class="vendorState && form.place_of_supply_state ? 'bg-brand-50 text-brand-800' : 'bg-amber-50 text-amber-800'">
                            <Icon name="receipt" :size="14" class="mr-1 inline align-text-bottom" />{{ taxTypeNote }}
                        </p>
                    </div>
                    <FormInput v-if="direct" v-model="form.direct_justification" :label="rfqEnabled ? 'Why a direct PO?' : 'Note'" required multiline :rows="2" maxlength="2000" class="sm:col-span-2 lg:col-span-4" :error="form.errors.direct_justification" />
                </div>
            </AppCard>

            <AppCard title="2 · Billing & delivery" subtitle="Invoice To is derived from the company. Consignee / Ship To comes from the project site and can be adjusted." :padded="false">
                <div class="grid gap-4 border-t border-line p-4 lg:grid-cols-2 lg:p-5">
                    <div class="rounded-lg border border-line bg-slate-50 p-3 text-xs text-slate-700">
                        <p class="mb-1 text-[10px] font-semibold tracking-wide text-slate-500 uppercase">Invoice To (read-only)</p>
                        <p class="font-semibold text-slate-900">{{ invoiceTo.name }}</p>
                        <p v-if="invoiceTo.former_name" class="text-slate-500">(Formerly {{ invoiceTo.former_name }})</p>
                        <p class="mt-1 whitespace-pre-line">{{ invoiceTo.address }}</p>
                        <p v-if="invoiceTo.pan">PAN: {{ invoiceTo.pan }}</p>
                        <p v-if="invoiceTo.gstin">GSTIN/UIN: {{ invoiceTo.gstin }}</p>
                        <p v-if="invoiceTo.state_code">State: {{ invoiceTo.state }} · Code: {{ invoiceTo.state_code }}</p>
                        <p v-if="invoiceTo.cin">CIN: {{ invoiceTo.cin }}</p>
                        <p v-if="invoiceTo.email">{{ invoiceTo.email }}</p>
                        <FormInput v-model="form.billing_address" label="Billing address override" multiline :rows="2" class="mt-3" :placeholder="'Leave empty to use company address'" :error="form.errors.billing_address" />
                    </div>
                    <div class="space-y-3">
                        <FormInput v-model="form.consignee_name" label="Consignee name" maxlength="255" :error="form.errors.consignee_name" />
                        <FormInput v-model="form.shipping_address" label="Ship to / site address" multiline :rows="3" :error="form.errors.shipping_address" />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <FormInput v-model="form.consignee_gstin" label="Consignee GSTIN (optional)" maxlength="20" :error="form.errors.consignee_gstin" />
                            <FormInput v-model="form.consignee_email" type="email" label="Consignee email" maxlength="150" :error="form.errors.consignee_email" />
                        </div>
                    </div>
                    <FormInput v-model="form.destination" label="Destination" maxlength="255" :error="form.errors.destination" />
                    <FormInput v-model="form.dispatched_through" label="Dispatched through" maxlength="150" placeholder="Transporter / Courier / Self Pickup…" :error="form.errors.dispatched_through" />
                    <FormInput v-model="form.delivery_terms" label="Terms of delivery" maxlength="255" placeholder="e.g. FOR Site, Within 3-4 Days" class="lg:col-span-2" :error="form.errors.delivery_terms" />
                    <div v-if="selectedVendor?.bank_name || selectedVendor?.name" class="rounded-lg border border-line bg-slate-50 p-3 text-xs text-slate-700 lg:col-span-2">
                        <p class="mb-1 text-[10px] font-semibold tracking-wide text-slate-500 uppercase">Supplier bank (from vendor master)</p>
                        <template v-if="selectedVendor?.bank_name">
                            <p>Bank: {{ selectedVendor.bank_name }}</p>
                            <p v-if="selectedVendor.bank_account_no">A/c: {{ selectedVendor.bank_account_no }}</p>
                            <p v-if="selectedVendor.bank_ifsc">IFSC: {{ selectedVendor.bank_ifsc }}</p>
                        </template>
                        <p v-else class="text-slate-500">No bank details on this vendor — PDF will omit the bank block.</p>
                    </div>
                </div>
            </AppCard>

            <AppCard title="3 · Items" :subtitle="`${form.items.length} line(s) · amounts are a preview; the server recalculates GST when you save`" :padded="false">
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
                                    <template v-if="item.material_request"> · MR/Indent {{ item.material_request }}</template>
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
                            <FormInput v-model="item.brand" label="Brand" maxlength="100" class="lg:col-span-2" :error="lineError(index, 'brand')" />
                            <FormInput v-model="item.make" label="Make" maxlength="100" class="lg:col-span-2" :error="lineError(index, 'make')" />
                            <FormInput v-model="item.hsn_sac" label="HSN/SAC" maxlength="10" class="lg:col-span-2" :error="lineError(index, 'hsn_sac')" />
                            <FormInput v-model="item.due_date" type="date" label="Due on" class="lg:col-span-2" :error="lineError(index, 'due_date')" />
                            <DecimalInput v-model="item.quantity" label="Quantity" required :decimals="4" :suffix="item.unit" class="lg:col-span-2" :error="lineError(index, 'quantity')" />
                            <DecimalInput v-model="item.rate" label="Rate" required :decimals="4" prefix="₹" class="sm:col-span-2 lg:col-span-2" :error="lineError(index, 'rate')" />
                            <DecimalInput v-model="item.discount_percent" label="Disc. %" :decimals="4" suffix="%" class="lg:col-span-1" :error="lineError(index, 'discount_percent')" />
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
                <AppCard title="4 · Terms & references">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormSelect
                            v-if="termsTemplates.length"
                            v-model="form.document_terms_template_id"
                            label="Terms template"
                            placeholder="None"
                            :options="termsTemplates"
                            class="sm:col-span-2"
                            :error="form.errors.document_terms_template_id"
                        />
                        <FormInput v-model="form.terms" label="Terms & conditions" multiline :rows="4" class="sm:col-span-2" :error="form.errors.terms" />
                        <FormInput v-model="form.other_references" label="Other references" multiline :rows="2" class="sm:col-span-2" :error="form.errors.other_references" />
                        <FormInput v-model="form.remarks" label="Internal remarks" multiline :rows="2" class="sm:col-span-2" :error="form.errors.remarks" />
                        <DecimalInput v-model="form.freight_amount" label="Freight (no GST)" prefix="₹" :error="form.errors.freight_amount" />
                        <DecimalInput v-model="form.other_charges" label="Other charges (no GST)" prefix="₹" :error="form.errors.other_charges" />
                    </div>
                </AppCard>
                <AppCard title="5 · Review totals" subtitle="Preview. Round-off brings the total to the nearest rupee. Server totals are authoritative.">
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
