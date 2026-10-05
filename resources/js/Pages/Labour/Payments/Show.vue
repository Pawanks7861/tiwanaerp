<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import TallyStatus from '@/Components/Integrations/TallyStatus.vue';
import LabourNav from '@/Components/Labour/LabourNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    payment: { type: Object, required: true },
    lines: { type: Array, required: true },
    days: { type: Array, required: true },
    overlaps: { type: Array, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
    tally: { type: Object, default: null },
});

const page = usePage();
const form = useForm({ remarks: '', lines: [] });
function init() {
    form.defaults({
        remarks: props.payment.remarks ?? '',
        lines: props.lines.map((l) => ({ id: l.id, advance_recovery: l.advance_recovery === '0.00' ? null : l.advance_recovery, other_deductions: l.other_deductions === '0.00' ? null : l.other_deductions, remarks: l.remarks ?? '' })),
    });
    form.reset();
}
init();
watch(() => props.lines, init);

const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};
/** Display preview only; the server recalculates and validates every amount. */
function previewNet(line, index) {
    const f = form.lines[index];

    return dec(line.gross_wage).plus(dec(line.ot_amount)).minus(dec(f?.advance_recovery)).minus(dec(f?.other_deductions)).toFixed(2);
}
const previewTotal = computed(() => props.lines.reduce((sum, l, i) => sum.plus(dec(previewNet(l, i))), new Decimal(0)).toFixed(2));

function save() {
    form.put(route('projects.labour-payments.update', [props.project.id, props.payment.id]), { preserveScroll: true });
}
const lineError = (index, field) => form.errors[`lines.${index}.${field}`];

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'Deductions are locked once submitted. Advance recoveries are checked again at approval.', label: 'Submit', danger: false, method: 'post', route: 'projects.labour-payments.submit' },
    approve: { title: 'Approve this payment?', message: 'Advance recoveries are applied to the labourers’ outstanding advances. No project cost is posted; labour cost was posted when attendance was approved.', label: 'Approve', danger: false, method: 'post', route: 'projects.labour-payments.approve' },
    delete: { title: 'Delete this payment?', message: 'Its attendance days become available for another payment.', label: 'Delete', danger: true, method: 'delete', route: 'projects.labour-payments.destroy' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(action.route, [props.project.id, props.payment.id]);
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const sendingBack = ref(false);
const paying = ref(false);
const payForm = useForm({ paid_on: props.today, payment_reference: '' });
function markPaid() {
    payForm.post(route('projects.labour-payments.mark-paid', [props.project.id, props.payment.id]), { preserveScroll: true, onSuccess: () => (paying.value = false) });
}

const showDays = ref(false);
</script>

<template>
    <ProjectLayout :project="project" active="labour" :title="payment.payment_number">
        <LabourNav :project-id="project.id" active="payments" />
        <TallyStatus :tally="tally" class="mb-4" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.labour-payments.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Labour payments</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ payment.payment_number }}</h2>
                            <StatusBadge :status="payment.status" :label="payment.status_label" />
                        </div>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-5">
                            <div><dt class="text-slate-500">Period</dt><dd>{{ formatDate(payment.period_from) }} – {{ formatDate(payment.period_to) }}</dd></div>
                            <div><dt class="text-slate-500">Wages</dt><dd class="tabular">{{ formatMoney(payment.total_gross) }}</dd></div>
                            <div><dt class="text-slate-500">Overtime</dt><dd class="tabular">{{ formatMoney(payment.total_ot) }}</dd></div>
                            <div><dt class="text-slate-500">Deductions</dt><dd class="tabular">{{ formatMoney(payment.total_deductions) }}</dd></div>
                            <div><dt class="text-slate-500">Net payable</dt><dd class="font-semibold tabular">{{ formatMoney(payment.total_net) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ payment.created_by ?? '—' }}
                            <template v-if="payment.submitted_at"> · Submitted {{ formatDateTime(payment.submitted_at) }} by {{ payment.submitted_by }}</template>
                            <template v-if="payment.approved_at"> · Approved {{ formatDateTime(payment.approved_at) }} by {{ payment.approved_by }}</template>
                            <template v-if="payment.paid_on"> · Paid {{ formatDate(payment.paid_on) }} by {{ payment.paid_by }}<template v-if="payment.payment_reference"> (ref {{ payment.payment_reference }})</template></template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.submit" size="sm" :disabled="form.isDirty" @click="confirming = 'submit'">Submit</AppButton>
                        <AppButton v-if="can.approve" size="sm" @click="confirming = 'approve'">Approve</AppButton>
                        <AppButton v-if="can.send_back" size="sm" variant="secondary" @click="sendingBack = true">Send back</AppButton>
                        <AppButton v-if="can.mark_paid" size="sm" @click="paying = true">Mark paid</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete payment" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="page.props.errors?.payment" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ page.props.errors.payment }}</p>
                <div v-if="payment.return_reason && payment.status === 'draft'" class="border-t border-orange-200 bg-orange-50 px-4 py-2.5 text-xs text-orange-900 sm:px-5">Sent back: {{ payment.return_reason }}</div>
                <div v-if="overlaps.length" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    <Icon name="warning" :size="14" class="mr-1 inline align-text-bottom" />Period overlaps
                    <template v-for="(o, i) in overlaps" :key="o.id"><template v-if="i">, </template><Link :href="route('projects.labour-payments.show', [project.id, o.id])" class="font-mono underline">{{ o.payment_number }}</Link> ({{ o.status_label }})</template>.
                    No attendance day is paid twice; each day belongs to exactly one payment.
                </div>
            </AppCard>

            <AppCard title="Labourers" :subtitle="can.update ? 'Enter advance recovery and other deductions; net is recalculated on save.' : `${lines.length} labourer(s)`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Labourer</th>
                                <th class="px-4 py-2.5 text-right">Days</th>
                                <th class="px-4 py-2.5 text-right">Wages</th>
                                <th class="px-4 py-2.5 text-right">OT</th>
                                <th class="px-4 py-2.5 text-right">Advance recovery</th>
                                <th class="px-4 py-2.5 text-right">Other deductions</th>
                                <th class="px-4 py-2.5 text-right">Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(line, i) in lines" :key="line.id" class="align-top">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ line.labour?.name }}</div>
                                    <div class="text-xs text-slate-500"><span class="font-mono">{{ line.labour?.code }}</span><template v-if="line.labour?.trade"> · {{ line.labour.trade }}</template></div>
                                    <FormInput v-if="can.update" v-model="form.lines[i].remarks" class="mt-1.5" placeholder="Remarks" maxlength="500" />
                                    <p v-else-if="line.remarks" class="mt-1 text-xs text-slate-500">{{ line.remarks }}</p>
                                </td>
                                <td class="px-4 py-3 text-right text-xs tabular">
                                    {{ formatNumber(line.present_days, 0, 1) }} P<template v-if="line.half_days !== '0.0' && line.half_days !== '0'"> · {{ formatNumber(line.half_days, 0, 1) }} H</template>
                                    <div v-if="line.ot_hours !== '0.00'" class="text-slate-500">{{ formatNumber(line.ot_hours, 0, 2) }} h OT</div>
                                </td>
                                <td class="px-4 py-3 text-right tabular">{{ formatMoney(line.gross_wage) }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatMoney(line.ot_amount) }}</td>
                                <td class="w-40 px-4 py-3 text-right tabular">
                                    <template v-if="can.update">
                                        <DecimalInput v-model="form.lines[i].advance_recovery" prefix="₹" :error="lineError(i, 'advance_recovery')" />
                                        <p class="mt-1 text-[11px] text-slate-500">Due {{ formatMoney(line.recoverable) }}</p>
                                    </template>
                                    <template v-else>{{ formatMoney(line.advance_recovery) }}</template>
                                </td>
                                <td class="w-40 px-4 py-3 text-right tabular">
                                    <DecimalInput v-if="can.update" v-model="form.lines[i].other_deductions" prefix="₹" :error="lineError(i, 'other_deductions')" />
                                    <template v-else>{{ formatMoney(line.other_deductions) }}</template>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular">{{ formatMoney(can.update ? previewNet(line, i) : line.net_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="(line, i) in lines" :key="`m-${line.id}`" class="px-4 py-3">
                        <div class="flex justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ line.labour?.name }}</p>
                                <p class="text-xs text-slate-500">
                                    <span class="font-mono">{{ line.labour?.code }}</span> · {{ formatNumber(line.present_days, 0, 1) }} P<template v-if="line.half_days !== '0.0' && line.half_days !== '0'"> · {{ formatNumber(line.half_days, 0, 1) }} H</template><template v-if="line.ot_hours !== '0.00'"> · {{ formatNumber(line.ot_hours, 0, 2) }} h OT</template>
                                </p>
                            </div>
                            <p class="shrink-0 font-semibold tabular">{{ formatMoney(can.update ? previewNet(line, i) : line.net_amount) }}</p>
                        </div>
                        <p class="text-xs text-slate-500 tabular">Wages {{ formatMoney(line.gross_wage) }} + OT {{ formatMoney(line.ot_amount) }}</p>
                        <div v-if="can.update" class="mt-2 grid grid-cols-2 gap-3">
                            <DecimalInput v-model="form.lines[i].advance_recovery" label="Advance recovery" prefix="₹" :help="`Due ${formatMoney(line.recoverable)}`" :error="lineError(i, 'advance_recovery')" />
                            <DecimalInput v-model="form.lines[i].other_deductions" label="Other deductions" prefix="₹" :error="lineError(i, 'other_deductions')" />
                            <FormInput v-model="form.lines[i].remarks" class="col-span-2" label="Remarks" maxlength="500" />
                        </div>
                        <p v-else-if="line.advance_recovery !== '0.00' || line.other_deductions !== '0.00'" class="text-xs text-slate-500 tabular">
                            Recovery {{ formatMoney(line.advance_recovery) }} · Deductions {{ formatMoney(line.other_deductions) }}
                        </p>
                    </li>
                </ul>
                <template v-if="can.update" #footer>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <FormInput v-model="form.remarks" class="sm:w-96" label="Remarks" maxlength="1000" :error="form.errors.remarks" />
                        <div class="flex items-center gap-3">
                            <span class="text-sm text-slate-600">Net <span class="font-semibold tabular text-slate-900">{{ formatMoney(previewTotal) }}</span></span>
                            <AppButton :loading="form.processing" :disabled="!form.isDirty" @click="save">Save deductions</AppButton>
                        </div>
                    </div>
                </template>
            </AppCard>

            <AppCard title="Attendance days" :subtitle="`${days.length} approved day(s) settled by this payment`" :padded="false">
                <template #actions>
                    <AppButton size="sm" variant="ghost" @click="showDays = !showDays">{{ showDays ? 'Hide' : 'Show' }}</AppButton>
                </template>
                <ul v-if="showDays" class="divide-y divide-line text-sm">
                    <li v-for="d in days" :key="d.id" class="flex flex-wrap justify-between gap-x-4 px-4 py-2 sm:px-5">
                        <span><span class="font-mono text-xs">{{ d.labour }}</span> · {{ formatDate(d.date) }} · {{ d.status }}</span>
                        <span class="tabular">{{ formatMoney(d.wage_amount) }}<template v-if="d.ot_amount !== '0.00'"> + {{ formatMoney(d.ot_amount) }} OT</template></span>
                    </li>
                </ul>
            </AppCard>
        </div>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? ACTIONS[confirming].title : ''"
            :message="confirming ? ACTIONS[confirming].message : ''"
            :confirm-label="confirming ? ACTIONS[confirming].label : ''"
            :danger="confirming ? ACTIONS[confirming].danger : true"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
        <ReasonDialog
            :show="sendingBack"
            :url="route('projects.labour-payments.send-back', [project.id, payment.id])"
            title="Send back to draft"
            message="The maker can correct deductions and submit again."
            confirm-label="Send back"
            :danger="false"
            @close="sendingBack = false"
        />
        <AppModal :show="paying" title="Mark as paid" @close="paying = false">
            <div class="space-y-4">
                <FormInput v-model="payForm.paid_on" type="date" label="Paid on" required :max="today" :error="payForm.errors.paid_on" />
                <FormInput v-model="payForm.payment_reference" label="Payment reference" maxlength="100" :error="payForm.errors.payment_reference" />
                <p class="text-xs text-slate-500">Recording payment does not post any project cost.</p>
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="paying = false">Cancel</AppButton>
                <AppButton :loading="payForm.processing" @click="markPaid">Mark paid</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
