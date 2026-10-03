<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import SubcontractNav from '@/Components/Subcontract/SubcontractNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    bill: { type: Object, required: true },
    items: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['bill', 'approval', 'items'].map((k) => page.props.errors?.[k]).find(Boolean));

const adjust = useForm({ items: [], advance_recovery: null, other_deductions: null });
function init() {
    adjust.defaults({
        items: props.items.map((i) => ({ id: i.id, certified_qty: i.certified_qty })),
        advance_recovery: props.bill.advance_recovery,
        other_deductions: props.bill.other_deductions,
    });
    adjust.reset();
}
init();
watch(() => [props.items, props.bill], init);
function saveAdjust() {
    adjust.put(route('projects.subcontractor-bills.adjust', [props.project.id, props.bill.id]), { preserveScroll: true });
}

const confirming = ref(null);
const reversing = ref(false);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for certification?', message: 'Quantities are checked against the work order balance, including other bills awaiting certification.', label: 'Submit', danger: false, method: 'post', route: 'projects.subcontractor-bills.submit' },
    delete: { title: 'Delete this bill?', message: 'This draft will be removed.', label: 'Delete', danger: true, method: 'delete', route: 'projects.subcontractor-bills.destroy' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(action.route, [props.project.id, props.bill.id]);
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const statusNote = computed(() => {
    if (props.approval) {
        return null;
    }

    return {
        certified: 'Certified: the certified amount (excluding GST) was posted to the project cost per work order line.',
        partially_paid: 'Certified and partly paid.',
        paid: 'Certified and fully paid.',
        rejected: 'Rejected. Edit and resubmit, or delete this bill.',
        draft: props.bill.revision > 0 ? `Reopened (revision ${props.bill.revision})${props.bill.reopen_reason ? `: ${props.bill.reopen_reason}` : ''}. Its earlier cost posting was reversed.` : null,
    }[props.bill.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="subcontract" :title="bill.bill_number">
        <SubcontractNav :project-id="project.id" active="bills" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.subcontractor-bills.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Subcontractor bills</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ bill.bill_number }}</h2>
                            <StatusBadge :status="bill.status" :label="bill.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">
                            {{ bill.subcontractor?.name }} ·
                            <Link v-if="bill.work_order" :href="route('projects.work-orders.show', [project.id, bill.work_order.id])" class="font-mono text-brand-700 hover:underline">{{ bill.work_order.wo_number }}</Link>
                        </p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Bill date</dt><dd>{{ formatDate(bill.bill_date) }}</dd></div>
                            <div><dt class="text-slate-500">Work period</dt><dd>{{ formatDate(bill.period_from) }} – {{ formatDate(bill.period_to) }}</dd></div>
                            <div v-if="bill.subcontractor_invoice_no"><dt class="text-slate-500">Invoice no.</dt><dd>{{ bill.subcontractor_invoice_no }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ bill.created_by ?? '—' }}
                            <template v-if="bill.certified_at"> · Certified {{ formatDateTime(bill.certified_at) }} by {{ bill.certified_by }}</template>
                            <template v-if="bill.reopened_at"> · Reopened {{ formatDateTime(bill.reopened_at) }} by {{ bill.reopened_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.subcontractor-bills.edit', [project.id, bill.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for certification</AppButton>
                        <ApprovalActions :approval="approval" noun="bill" />
                        <AppButton v-if="can.reverse" size="sm" variant="danger" @click="reversing = true">Reverse certification</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete bill" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting certification · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>.
                    <template v-if="can.certify"> You can adjust certified quantities below before approving.</template>
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="bill.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ bill.remarks }}</p>
            </AppCard>

            <AppCard title="Measurement" subtitle="Previous + this bill = cumulative, never more than the work order quantity." :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="(item, i) in items" :key="item.id" class="grid grid-cols-2 gap-3 px-4 py-3 sm:grid-cols-6 sm:px-5">
                        <div class="col-span-2 min-w-0 sm:col-span-3">
                            <p class="font-medium text-slate-900">{{ item.description }}</p>
                            <p v-if="item.boq_item" class="text-xs text-slate-500">{{ item.boq_item }}</p>
                            <p class="text-xs text-slate-500 tabular">
                                WO {{ formatQty(item.wo_qty) }} {{ item.unit }} · previous {{ formatQty(item.previous_qty) }} · claimed {{ formatQty(item.claimed_qty) }} · cumulative {{ formatQty(item.cumulative_qty) }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <DecimalInput v-if="can.certify" v-model="adjust.items[i].certified_qty" label="Certified" :decimals="4" :suffix="item.unit" :error="adjust.errors[`items.${i}.certified_qty`]" />
                            <p v-else class="text-sm tabular"><span class="text-xs text-slate-500">Certified</span><br />{{ formatQty(item.certified_qty) }} {{ item.unit }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-slate-500 tabular">@ {{ formatRate(item.rate) }}</p>
                            <p class="font-semibold tabular">{{ formatMoney(item.amount) }}</p>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Bill summary">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Gross (certified work)</dt><dd class="tabular">{{ formatMoney(bill.gross_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">GST {{ formatPercent(bill.tax_percent) }}</dt><dd class="tabular">{{ formatMoney(bill.tax_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Retention {{ formatPercent(bill.retention_percent) }}</dt><dd class="tabular">-{{ formatMoney(bill.retention_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Advance recovery</dt><dd class="tabular">-{{ formatMoney(bill.advance_recovery) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">TDS {{ formatPercent(bill.tds_percent) }}</dt><dd class="tabular">-{{ formatMoney(bill.tds_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Other deductions</dt><dd class="tabular">-{{ formatMoney(bill.other_deductions) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Net payable</dt><dd class="tabular">{{ formatMoney(bill.net_payable) }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-slate-500">Project cost on certification = gross {{ formatMoney(bill.gross_amount) }} (GST is recoverable input tax; retention and TDS are payment-side).</p>
                </AppCard>
                <AppCard v-if="can.certify" title="Certifier adjustment" subtitle="Reduce certified quantities or change deductions, save, then approve to certify.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <DecimalInput v-model="adjust.advance_recovery" label="Advance recovery" prefix="₹" :help="`Unrecovered ${formatMoney(bill.advance_balance)}`" :error="adjust.errors.advance_recovery" />
                        <DecimalInput v-model="adjust.other_deductions" label="Other deductions" prefix="₹" :error="adjust.errors.other_deductions" />
                    </div>
                    <div class="mt-4 flex justify-end">
                        <AppButton :loading="adjust.processing" :disabled="!adjust.isDirty" @click="saveAdjust">Save adjustment</AppButton>
                    </div>
                </AppCard>
            </div>

            <AttachmentPanel :attachments="attachments" attachable-type="subcontractor_bill" :attachable-id="bill.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Measurement sheet, invoice copy…" />
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
            :show="reversing"
            :url="route('projects.subcontractor-bills.reverse', [project.id, bill.id])"
            title="Reverse certification"
            message="The subcontract cost is reversed and the bill returns to draft as a new revision. Only the latest certified, unpaid bill of a work order can be reversed."
            confirm-label="Reverse"
            @close="reversing = false"
        />
    </ProjectLayout>
</template>
