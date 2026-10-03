<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty, formatRate } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    purchaseOrder: { type: Object, required: true },
    grn: { type: Object, default: null },
    lines: { type: Array, required: true },
    warehouses: { type: Array, required: true },
    tolerance: { type: String, required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.grn);
const D = (v) => {
    try {
        return new Decimal(v === null || v === undefined || v === '' ? 0 : String(v));
    } catch {
        return new Decimal(0);
    }
};

const form = useForm({
    purchase_order_id: props.purchaseOrder.id,
    warehouse_id: props.grn?.warehouse_id ?? null,
    receipt_date: props.grn?.receipt_date ?? props.today,
    vendor_invoice_no: props.grn?.vendor_invoice_no ?? '',
    vendor_invoice_date: props.grn?.vendor_invoice_date ?? null,
    vendor_challan_no: props.grn?.vendor_challan_no ?? '',
    vehicle_no: props.grn?.vehicle_no ?? '',
    remarks: props.grn?.remarks ?? '',
    items: props.lines.map((l) => ({
        purchase_order_item_id: l.purchase_order_item_id,
        received_qty: l.received_qty_input ?? null,
        rejected_qty: l.rejected_qty_input && !D(l.rejected_qty_input).isZero() ? l.rejected_qty_input : null,
        rejection_reason: l.rejection_reason ?? '',
    })),
});

const accepted = (row) => D(row.received_qty).minus(D(row.rejected_qty));
const warnings = computed(() =>
    form.items.map((row, index) => {
        const line = props.lines[index];
        if (D(row.rejected_qty).gt(D(row.received_qty))) {
            return 'Rejected cannot exceed received.';
        }
        if (accepted(row).gt(D(line.max_acceptable_qty))) {
            return `Accepted quantity exceeds the ${formatQty(line.max_acceptable_qty)} ${line.unit ?? ''} still receivable${D(props.tolerance).isZero() ? '' : ` (including ${props.tolerance.replace(/\.?0+$/, '')}% tolerance)`}.`;
        }
        if (D(row.rejected_qty).isPositive() && !row.rejection_reason) {
            return 'Give a reason for the rejected quantity.';
        }

        return null;
    }),
);
const lineError = (index, field) => form.errors[`items.${index}.${field}`];
function receiveAll() {
    form.items.forEach((row, index) => {
        const remaining = D(props.lines[index].remaining_qty);
        row.received_qty = remaining.isPositive() ? remaining.toString() : row.received_qty;
    });
}

function submit() {
    const transform = (d) => ({
        ...d,
        vendor_invoice_no: d.vendor_invoice_no || null,
        vendor_challan_no: d.vendor_challan_no || null,
        vehicle_no: d.vehicle_no || null,
        remarks: d.remarks || null,
        items: d.items.map((i) => ({ ...i, received_qty: i.received_qty || '0', rejected_qty: i.rejected_qty || '0', rejection_reason: i.rejection_reason || null })),
    });
    if (editing.value) {
        form.transform(transform).put(route('projects.grns.update', [props.project.id, props.grn.id]));
    } else {
        form.transform(transform).post(route('projects.grns.store', props.project.id));
    }
}
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="editing ? grn.grn_number : 'Receive goods'">
        <ProcurementNav :project-id="project.id" active="grns" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.grns.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Goods receipts</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit ${grn.grn_number}` : 'Receive goods' }}</h2>
                    <p class="text-xs text-slate-500">
                        Against <span class="font-mono font-medium text-slate-700">{{ purchaseOrder.po_number }}</span> · {{ purchaseOrder.vendor?.name }}
                        · Accepted = received − rejected.
                        <template v-if="!D(tolerance).isZero()"> Over-receipt tolerance {{ tolerance.replace(/\.?0+$/, '') }}%.</template>
                        <template v-else> Over-receipt is not allowed.</template>
                    </p>
                </div>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <FormInput v-model="form.receipt_date" type="date" label="Receipt date" required :min="purchaseOrder.po_date" :max="today" :error="form.errors.receipt_date" />
                    <FormSelect
                        v-model="form.warehouse_id"
                        label="Receiving store"
                        required
                        placeholder="— Choose a store —"
                        :options="warehouses"
                        help="Accepted quantities are added to this store's stock on approval."
                        :error="form.errors.warehouse_id"
                    />
                    <FormInput v-model="form.vendor_invoice_no" label="Vendor invoice no." maxlength="50" :error="form.errors.vendor_invoice_no" />
                    <FormInput v-model="form.vendor_invoice_date" type="date" label="Invoice date" :max="today" :error="form.errors.vendor_invoice_date" />
                    <FormInput v-model="form.vendor_challan_no" label="Delivery challan no." maxlength="50" :error="form.errors.vendor_challan_no" />
                    <FormInput v-model="form.vehicle_no" label="Vehicle no." uppercase maxlength="30" :error="form.errors.vehicle_no" />
                    <FormInput v-model="form.remarks" label="Remarks" class="sm:col-span-2" maxlength="2000" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard title="Received quantities" subtitle="Leave a line empty if nothing of it arrived." :padded="false">
                <template #actions>
                    <AppButton size="sm" variant="secondary" @click="receiveAll">Fill remaining</AppButton>
                </template>
                <p v-if="form.errors.items || form.errors.purchase_order || form.errors.grn" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                    {{ form.errors.items || form.errors.purchase_order || form.errors.grn }}
                </p>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in lines" :key="line.purchase_order_item_id" class="p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-medium text-slate-900">{{ line.description }} <span class="font-mono text-xs text-slate-500">{{ line.item_code }}</span></p>
                            <p v-if="line.rate" class="text-xs text-slate-500">{{ formatRate(line.rate) }} / {{ line.unit }}</p>
                        </div>
                        <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-slate-500">
                            <div>Ordered <span class="text-slate-800 tabular">{{ formatQty(line.ordered_qty) }} {{ line.unit }}</span></div>
                            <div>Received earlier <span class="text-slate-800 tabular">{{ formatQty(line.received_qty) }}</span></div>
                            <div v-if="!D(line.pending_qty).isZero()">In other open GRNs <span class="text-amber-700 tabular">{{ formatQty(line.pending_qty) }}</span></div>
                            <div>Remaining <span class="font-medium text-slate-800 tabular">{{ formatQty(line.remaining_qty) }}</span></div>
                        </dl>
                        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <DecimalInput v-model="form.items[index].received_qty" label="Received" :decimals="4" :suffix="line.unit" :error="lineError(index, 'received_qty')" />
                            <DecimalInput v-model="form.items[index].rejected_qty" label="Rejected" :decimals="4" :suffix="line.unit" :error="lineError(index, 'rejected_qty')" />
                            <div class="flex flex-col justify-end">
                                <span class="text-xs text-slate-500">Accepted</span>
                                <span class="py-2 font-semibold tabular">{{ formatQty(accepted(form.items[index]).toString()) }} {{ line.unit }}</span>
                            </div>
                            <FormInput
                                v-if="D(form.items[index].rejected_qty).isPositive()"
                                v-model="form.items[index].rejection_reason"
                                label="Rejection reason"
                                maxlength="500"
                                class="col-span-2 sm:col-span-1"
                                :error="lineError(index, 'rejection_reason')"
                            />
                        </div>
                        <p v-if="warnings[index]" class="mt-1.5 flex items-center gap-1 text-xs text-amber-700"><Icon name="warning" :size="14" />{{ warnings[index] }}</p>
                    </li>
                </ol>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.grns.show', [project.id, grn.id]) : route('projects.grns.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
