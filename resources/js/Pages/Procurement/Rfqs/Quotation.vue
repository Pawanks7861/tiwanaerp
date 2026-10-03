<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatQty } from '@/lib/format';
import { orderTotals, quotationLine, str } from '@/lib/gstPreview';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    rfq: { type: Object, required: true },
    quotation: { type: Object, default: null },
    items: { type: Array, required: true },
    vendors: { type: Array, required: true },
    preselectVendor: { type: Number, default: null },
    taxRates: { type: Array, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const editing = computed(() => !!props.quotation);
const form = useForm({
    vendor_id: editing.value ? undefined : props.vendors.some((v) => v.value === props.preselectVendor) ? props.preselectVendor : (props.vendors[0]?.value ?? null),
    quotation_number: props.quotation?.quotation_number ?? '',
    quotation_date: props.quotation?.quotation_date ?? props.today,
    valid_until: props.quotation?.valid_until ?? null,
    delivery_days: props.quotation?.delivery_days ?? null,
    payment_terms: props.quotation?.payment_terms ?? '',
    warranty: props.quotation?.warranty ?? '',
    freight_amount: props.quotation?.freight_amount ?? null,
    other_charges: props.quotation?.other_charges ?? null,
    remarks: props.quotation?.remarks ?? '',
    items: props.items.map((i) => ({ rfq_item_id: i.rfq_item_id, rate: i.rate, discount_percent: i.discount_percent, tax_rate_id: i.tax_rate_id, remarks: i.remarks ?? '' })),
});

const taxPercent = (id) => props.taxRates.find((t) => t.value === id)?.rate ?? '0';
const taxOptions = computed(() => props.taxRates.map((t) => ({ value: t.value, label: t.label })));
const preview = computed(() => form.items.map((row, index) => (row.rate === null || row.rate === '' ? null : quotationLine(props.items[index].quantity, row.rate, row.discount_percent, taxPercent(row.tax_rate_id)))));
const totals = computed(() => orderTotals(preview.value.filter(Boolean), form.freight_amount, form.other_charges));
const lineError = (index, field) => form.errors[`items.${index}.${field}`];

function submit() {
    const transform = (d) => {
        const data = {
            ...d,
            quotation_number: d.quotation_number || null,
            payment_terms: d.payment_terms || null,
            warranty: d.warranty || null,
            remarks: d.remarks || null,
            delivery_days: d.delivery_days === '' ? null : d.delivery_days,
            items: d.items.map((i) => ({ ...i, discount_percent: i.discount_percent || '0', remarks: i.remarks || null })),
        };
        if (editing.value) {
            delete data.vendor_id;
        }

        return data;
    };
    if (editing.value) {
        form.transform(transform).put(route('projects.rfqs.quotations.update', [props.project.id, props.rfq.id, props.quotation.id]));
    } else {
        form.transform(transform).post(route('projects.rfqs.quotations.store', [props.project.id, props.rfq.id]));
    }
}

const confirmDelete = ref(false);
const deleting = ref(false);
function destroy() {
    deleting.value = true;
    router.delete(route('projects.rfqs.quotations.destroy', [props.project.id, props.rfq.id, props.quotation.id]), { onFinish: () => ((deleting.value = false), (confirmDelete.value = false)) });
}
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="`Quotation · ${rfq.rfq_number}`">
        <ProcurementNav :project-id="project.id" active="rfqs" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.rfqs.show', [project.id, rfq.id])" class="text-xs font-medium text-slate-500 hover:text-slate-700">{{ rfq.rfq_number }}</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Quotation from ${quotation.vendor?.name}` : 'Record vendor quotation' }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Leave the rate empty for items the vendor did not quote. GST here is the total rate; CGST/SGST or IGST is decided on the purchase order.</p>
                </div>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <FormSelect v-if="!editing" v-model="form.vendor_id" label="Vendor" required :options="vendors" class="sm:col-span-2" :error="form.errors.vendor_id" />
                    <FormInput v-model="form.quotation_number" label="Vendor quotation no." maxlength="50" :error="form.errors.quotation_number" />
                    <FormInput v-model="form.quotation_date" type="date" label="Quotation date" required :error="form.errors.quotation_date" />
                    <FormInput v-model="form.valid_until" type="date" label="Valid until" :min="form.quotation_date" :error="form.errors.valid_until" />
                    <FormInput v-model="form.delivery_days" type="number" min="0" label="Delivery (days)" :error="form.errors.delivery_days" />
                    <FormInput v-model="form.payment_terms" label="Payment terms" maxlength="255" :error="form.errors.payment_terms" />
                    <FormInput v-model="form.warranty" label="Warranty" maxlength="255" :error="form.errors.warranty" />
                </div>
            </AppCard>

            <AppCard title="Rates" :padded="false">
                <p v-if="form.errors.items || form.errors.quotation" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items || form.errors.quotation }}</p>
                <ol class="divide-y divide-line">
                    <li v-for="(item, index) in items" :key="item.rfq_item_id" class="p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-medium text-slate-900">{{ item.material?.name }} <span class="font-mono text-xs text-slate-500">{{ item.material?.code }}</span></p>
                            <p class="text-sm text-slate-600 tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</p>
                        </div>
                        <p v-if="item.specification" class="text-xs text-slate-500">{{ item.specification }}</p>
                        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                            <DecimalInput v-model="form.items[index].rate" label="Rate" :decimals="4" prefix="₹" :error="lineError(index, 'rate')" />
                            <DecimalInput v-model="form.items[index].discount_percent" label="Discount" :decimals="4" suffix="%" :error="lineError(index, 'discount_percent')" />
                            <FormSelect v-model="form.items[index].tax_rate_id" label="GST" placeholder="No GST" :options="taxOptions" :error="lineError(index, 'tax_rate_id')" />
                            <FormInput v-model="form.items[index].remarks" label="Remarks" maxlength="500" class="col-span-2 sm:col-span-1 lg:col-span-2" :error="lineError(index, 'remarks')" />
                            <div class="col-span-2 flex flex-col justify-end text-right sm:col-span-4 lg:col-span-1">
                                <span class="text-xs text-slate-500">Amount (incl. GST)</span>
                                <span class="font-semibold tabular">{{ preview[index] ? formatMoney(str(preview[index].amount)) : 'Not quoted' }}</span>
                            </div>
                        </div>
                    </li>
                </ol>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Other charges & remarks">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <DecimalInput v-model="form.freight_amount" label="Freight" prefix="₹" :error="form.errors.freight_amount" />
                        <DecimalInput v-model="form.other_charges" label="Other charges" prefix="₹" :error="form.errors.other_charges" />
                        <FormInput v-model="form.remarks" label="Remarks" multiline :rows="3" class="sm:col-span-2" :error="form.errors.remarks" />
                    </div>
                </AppCard>
                <AppCard title="Summary" subtitle="Preview; the server recalculates when you save.">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Sub total</dt><dd class="tabular">{{ formatMoney(str(totals.base)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="tabular">-{{ formatMoney(str(totals.discount)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Taxable</dt><dd class="tabular">{{ formatMoney(str(totals.taxable)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">GST</dt><dd class="tabular">{{ formatMoney(str(totals.tax)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Freight & other</dt><dd class="tabular">{{ formatMoney(str(totals.freight.plus(totals.other))) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Total</dt><dd class="tabular">{{ formatMoney(str(totals.beforeRounding)) }}</dd></div>
                    </dl>
                </AppCard>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <AppButton v-if="can.delete" variant="ghost" class="text-red-600 md:mr-auto" icon="trash" @click="confirmDelete = true">Delete</AppButton>
                <Link
                    :href="route('projects.rfqs.show', [project.id, rfq.id])"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">Save quotation</AppButton>
            </div>
        </form>

        <ConfirmDialog
            :show="confirmDelete"
            title="Delete this quotation?"
            message="The vendor's rates will be removed from the RFQ."
            confirm-label="Delete"
            :processing="deleting"
            @close="confirmDelete = false"
            @confirm="destroy"
        />
    </ProjectLayout>
</template>
