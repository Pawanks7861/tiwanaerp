<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    rfq: { type: Object, required: true },
    items: { type: Array, required: true },
    quotations: { type: Array, required: true },
    comparison: { type: Object, default: null },
    bases: { type: Array, required: true },
    can: { type: Object, required: true },
});

const form = useForm({
    selected_vendor_quotation_id: props.comparison?.selected_vendor_quotation_id ?? null,
    selection_basis: props.comparison?.selection_basis ?? null,
    justification: props.comparison?.justification ?? '',
});

/** Lowest / highest values across vendors, compared with decimal.js (never floats). */
function extremes(values) {
    const present = values.filter((v) => v !== null && v !== undefined && v !== '');
    if (present.length < 2) {
        return { min: null, max: null };
    }
    const sorted = [...present].sort((a, b) => new Decimal(a).comparedTo(new Decimal(b)));
    const min = sorted[0];
    const max = sorted[sorted.length - 1];

    return new Decimal(min).eq(new Decimal(max)) ? { min: null, max: null } : { min, max };
}
const cellTone = (value, ext) => {
    if (value === null || value === undefined || ext.min === null) {
        return '';
    }
    if (new Decimal(value).eq(new Decimal(ext.min))) {
        return 'bg-emerald-50 text-emerald-800 font-semibold';
    }
    if (new Decimal(value).eq(new Decimal(ext.max))) {
        return 'bg-red-50/70 text-red-800';
    }

    return '';
};

const rowExtremes = computed(() => Object.fromEntries(props.items.map((item) => [item.id, extremes(props.quotations.map((q) => q.lines[item.id]?.amount ?? null))])));
const summaryRows = [
    { key: 'subtotal', label: 'Sub total' },
    { key: 'discount_amount', label: 'Discount' },
    { key: 'taxable_amount', label: 'Taxable' },
    { key: 'tax_amount', label: 'GST' },
    { key: 'freight_amount', label: 'Freight' },
    { key: 'other_charges', label: 'Other charges' },
];
const grandExtremes = computed(() => extremes(props.quotations.map((q) => q.grand_total)));
const deliveryExtremes = computed(() => extremes(props.quotations.map((q) => (q.delivery_days === null ? null : String(q.delivery_days)))));
const quotedCount = (q) => props.items.filter((i) => q.lines[i.id]).length;

const locked = computed(() => !props.can.edit);
function save() {
    form.transform((d) => ({ ...d, justification: d.justification || null })).put(route('projects.rfqs.comparison.save', [props.project.id, props.rfq.id]), { preserveScroll: true });
}

const confirming = ref(null);
const processing = ref(false);
function runConfirmed() {
    processing.value = true;
    const name = confirming.value === 'submit' ? 'projects.rfqs.comparison.submit' : 'projects.rfqs.comparison.approve';
    router.post(route(name, [props.project.id, props.rfq.id]), {}, { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) });
}

const showReject = ref(false);
const rejectForm = useForm({ reason: '' });
function reject() {
    rejectForm.post(route('projects.rfqs.comparison.reject', [props.project.id, props.rfq.id]), { preserveScroll: true, onSuccess: () => (showReject.value = false) });
}

const selectedName = computed(() => props.quotations.find((q) => q.id === (props.comparison?.selected_vendor_quotation_id ?? form.selected_vendor_quotation_id))?.vendor?.name);
const basisLabel = (value) => props.bases.find((b) => b.value === value)?.label ?? '—';
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="`Bid comparison · ${rfq.rfq_number}`">
        <ProcurementNav :project-id="project.id" active="rfqs" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-3 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Link :href="route('projects.rfqs.show', [project.id, rfq.id])" class="text-xs font-medium text-slate-500 hover:text-slate-700">{{ rfq.rfq_number }}</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-900">Bid comparison</h2>
                            <StatusBadge v-if="comparison" :status="comparison.status" :label="comparison.status_label" />
                            <StatusBadge v-else status="draft" label="Not started" />
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Lowest value per row is green, highest is red. The system does not pick a vendor: select one and record why.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <template v-if="can.approve">
                            <AppButton size="sm" @click="confirming = 'approve'">Approve</AppButton>
                            <AppButton size="sm" variant="ghost" class="text-red-600" @click="(rejectForm.reset(), (showReject = true))">Reject</AppButton>
                        </template>
                    </div>
                </div>
                <div v-if="comparison?.status === 'rejected' && comparison.rejection_reason" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-xs text-red-800 sm:px-5">
                    Rejected: {{ comparison.rejection_reason }}
                </div>
                <div v-else-if="comparison?.submitted_at" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />
                    Submitted {{ formatDateTime(comparison.submitted_at) }}<template v-if="comparison.submitted_by"> by {{ comparison.submitted_by }}</template>
                    <template v-if="comparison.approved_at"> · approved {{ formatDateTime(comparison.approved_at) }}<template v-if="comparison.approved_by"> by {{ comparison.approved_by }}</template></template>
                    <template v-else-if="comparison.status === 'submitted'"> · awaiting approval by another user</template>
                </div>
            </AppCard>

            <AppCard :padded="false" title="Comparison matrix" :subtitle="`${items.length} item(s) × ${quotations.length} quotation(s)`">
                <EmptyState v-if="!quotations.length" icon="scale" title="No quotations to compare" description="Record vendor quotations on the RFQ first." />
                <div v-else class="max-h-[70vh] overflow-auto">
                    <table class="min-w-full border-separate border-spacing-0 text-xs">
                        <thead class="sticky top-0 z-20 bg-slate-100 text-slate-700">
                            <tr>
                                <th class="sticky left-0 z-30 min-w-[11rem] border-b border-line bg-slate-100 px-3 py-2 text-left sm:min-w-[16rem]">Item</th>
                                <th
                                    v-for="q in quotations"
                                    :key="q.id"
                                    class="min-w-[10rem] border-b border-l border-line px-3 py-2 text-right align-top"
                                    :class="form.selected_vendor_quotation_id === q.id ? 'bg-brand-50' : ''"
                                >
                                    <label class="flex cursor-pointer items-start justify-end gap-2" :class="locked ? 'cursor-default' : ''">
                                        <span class="text-right">
                                            <span class="block font-semibold text-slate-900">{{ q.vendor?.name }}</span>
                                            <span class="block font-normal text-slate-500">{{ q.vendor?.state_code ? `State ${q.vendor.state_code}` : 'No GST state' }} · {{ quotedCount(q) }}/{{ items.length }} quoted</span>
                                        </span>
                                        <input v-model="form.selected_vendor_quotation_id" type="radio" :value="q.id" :disabled="locked" class="mt-0.5 border-slate-300 text-brand-600" :aria-label="`Select ${q.vendor?.name}`" />
                                    </label>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id" class="align-top">
                                <td class="sticky left-0 z-10 border-b border-line bg-white px-3 py-2">
                                    <div class="font-medium text-slate-900">{{ item.material?.name }}</div>
                                    <div class="text-slate-500 tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</div>
                                </td>
                                <td
                                    v-for="q in quotations"
                                    :key="q.id"
                                    class="border-b border-l border-line px-3 py-2 text-right"
                                    :class="[cellTone(q.lines[item.id]?.amount, rowExtremes[item.id]), form.selected_vendor_quotation_id === q.id ? 'ring-1 ring-brand-200 ring-inset' : '']"
                                >
                                    <template v-if="q.lines[item.id]">
                                        <div class="tabular">{{ formatMoney(q.lines[item.id].amount) }}</div>
                                        <div class="text-[11px] font-normal text-slate-500 tabular">
                                            {{ formatRate(q.lines[item.id].rate) }}<template v-if="q.lines[item.id].discount_percent !== '0.0000'"> −{{ formatPercent(q.lines[item.id].discount_percent) }}</template> + {{ formatPercent(q.lines[item.id].tax_percent) }} GST
                                        </div>
                                    </template>
                                    <span v-else class="text-slate-400">Not quoted</span>
                                </td>
                            </tr>
                            <tr v-for="row in summaryRows" :key="row.key" class="bg-slate-50/60">
                                <td class="sticky left-0 z-10 border-b border-line bg-slate-50 px-3 py-1.5 font-medium text-slate-600">{{ row.label }}</td>
                                <td v-for="q in quotations" :key="q.id" class="border-b border-l border-line px-3 py-1.5 text-right text-slate-700 tabular">{{ formatMoney(q[row.key]) }}</td>
                            </tr>
                            <tr>
                                <td class="sticky left-0 z-10 border-b border-line bg-white px-3 py-2 text-sm font-semibold text-slate-900">Grand total</td>
                                <td v-for="q in quotations" :key="q.id" class="border-b border-l border-line px-3 py-2 text-right text-sm tabular" :class="cellTone(q.grand_total, grandExtremes) || 'font-semibold'">
                                    {{ formatMoney(q.grand_total) }}
                                </td>
                            </tr>
                            <tr>
                                <td class="sticky left-0 z-10 border-b border-line bg-white px-3 py-1.5 text-slate-600">Delivery</td>
                                <td v-for="q in quotations" :key="q.id" class="border-b border-l border-line px-3 py-1.5 text-right" :class="cellTone(q.delivery_days === null ? null : String(q.delivery_days), deliveryExtremes)">
                                    {{ q.delivery_days === null ? '—' : `${q.delivery_days} days` }}
                                </td>
                            </tr>
                            <tr v-for="field in [
                                { key: 'payment_terms', label: 'Payment terms' },
                                { key: 'warranty', label: 'Warranty' },
                                { key: 'valid_until', label: 'Valid until', date: true },
                                { key: 'quotation_number', label: 'Vendor ref.' },
                            ]" :key="field.key">
                                <td class="sticky left-0 z-10 border-b border-line bg-white px-3 py-1.5 text-slate-600">{{ field.label }}</td>
                                <td v-for="q in quotations" :key="q.id" class="border-b border-l border-line px-3 py-1.5 text-right text-slate-700">
                                    {{ field.date ? formatDate(q[field.key]) : (q[field.key] || '—') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard v-if="quotations.length" title="Selection" :subtitle="locked ? null : 'Save as often as you like; submitting locks the comparison.'">
                <div v-if="locked" class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><p class="text-xs text-slate-500">Selected vendor</p><p class="font-medium text-slate-900">{{ selectedName ?? '—' }}</p></div>
                    <div><p class="text-xs text-slate-500">Basis</p><p class="text-slate-800">{{ basisLabel(comparison?.selection_basis) }}</p></div>
                    <div class="sm:col-span-3"><p class="text-xs text-slate-500">Justification</p><p class="whitespace-pre-line text-slate-800">{{ comparison?.justification || '—' }}</p></div>
                </div>
                <form v-else class="grid gap-4 sm:grid-cols-3" @submit.prevent="save">
                    <div class="text-sm">
                        <p class="text-xs font-medium text-slate-700">Selected vendor</p>
                        <p class="mt-2" :class="selectedName ? 'font-medium text-slate-900' : 'text-slate-500'">{{ selectedName ?? 'Pick a column in the matrix' }}</p>
                        <p v-if="form.errors.selected_vendor_quotation_id" class="mt-1 text-xs text-red-600">{{ form.errors.selected_vendor_quotation_id }}</p>
                    </div>
                    <FormSelect v-model="form.selection_basis" label="Selection basis" placeholder="Choose…" :options="bases" :error="form.errors.selection_basis" />
                    <div class="hidden sm:block" />
                    <FormInput v-model="form.justification" label="Justification" multiline :rows="3" maxlength="2000" class="sm:col-span-3" placeholder="Why this vendor? e.g. lowest landed cost for all items, delivery within 7 days." :error="form.errors.justification || form.errors.comparison" />
                    <div class="flex justify-end sm:col-span-3">
                        <AppButton type="submit" :loading="form.processing">Save selection</AppButton>
                    </div>
                </form>
            </AppCard>
        </div>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming === 'submit' ? 'Submit comparison?' : 'Approve comparison?'"
            :message="confirming === 'submit'
                ? 'The selection is locked and sent for approval. Save your latest changes first.'
                : `${selectedName ?? 'The selected vendor'} will be marked as selected and a purchase order can be created.`"
            :confirm-label="confirming === 'submit' ? 'Submit' : 'Approve'"
            :danger="false"
            :processing="processing"
            @close="confirming = null"
            @confirm="runConfirmed"
        />

        <AppModal :show="showReject" title="Reject comparison" @close="showReject = false">
            <FormInput v-model="rejectForm.reason" label="Reason" required multiline :rows="3" maxlength="1000" :error="rejectForm.errors.reason || rejectForm.errors.comparison" />
            <template #footer>
                <AppButton variant="secondary" @click="showReject = false">Cancel</AppButton>
                <AppButton variant="danger" :loading="rejectForm.processing" @click="reject">Reject</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
