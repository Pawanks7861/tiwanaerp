<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
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
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    order: { type: Object, required: true },
    items: { type: Array, required: true },
    milestones: { type: Array, required: true },
    bills: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    milestoneStatuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['work_order', 'approval', 'milestones'].map((k) => page.props.errors?.[k]).find(Boolean));

const confirming = ref(null);
const cancelling = ref(false);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'The work order is locked while it is reviewed. After approval it can be billed against.', label: 'Submit', danger: false, method: 'post', route: 'projects.work-orders.submit' },
    complete: { title: 'Mark work completed?', message: 'Further bills can still be raised for certified work until the work order is closed.', label: 'Mark completed', danger: false, method: 'post', route: 'projects.work-orders.complete' },
    close: { title: 'Close this work order?', message: 'No further bills can be raised. Not possible while a bill is draft or awaiting certification.', label: 'Close', danger: false, method: 'post', route: 'projects.work-orders.close' },
    delete: { title: 'Delete this work order?', message: 'This draft will be removed.', label: 'Delete', danger: true, method: 'delete', route: 'projects.work-orders.destroy' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(action.route, [props.project.id, props.order.id]);
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const savingMilestone = ref(null);
function toggleMilestone(m) {
    savingMilestone.value = m.id;
    const rows = props.milestones.map((x) => ({ id: x.id, status: x.id === m.id ? (m.status === 'achieved' ? 'pending' : 'achieved') : x.status }));
    router.put(route('projects.work-orders.milestones', [props.project.id, props.order.id]), { milestones: rows }, { preserveScroll: true, onFinish: () => (savingMilestone.value = null) });
}

const statusNote = computed(() => {
    if (props.approval) {
        return null;
    }

    return {
        approved: 'Approved and locked. Raise subcontractor bills against certified quantities.',
        in_progress: 'Work in progress: at least one bill has been certified.',
        completed: 'Work completed. Close the work order once the final bill is certified.',
        closed: 'Closed. No further bills can be raised.',
        rejected: 'Rejected. Edit and resubmit, or delete this work order.',
        cancelled: `Cancelled${props.order.cancellation_reason ? `: ${props.order.cancellation_reason}` : ''}.`,
    }[props.order.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="subcontract" :title="order.wo_number">
        <SubcontractNav :project-id="project.id" active="work-orders" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.work-orders.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Work orders</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ order.wo_number }}</h2>
                            <StatusBadge :status="order.status" :label="order.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ order.subcontractor?.name }}<span v-if="order.subcontractor?.gstin" class="ml-2 font-mono text-xs text-slate-500">{{ order.subcontractor.gstin }}</span></p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">WO date</dt><dd>{{ formatDate(order.wo_date) }}</dd></div>
                            <div><dt class="text-slate-500">Period</dt><dd>{{ order.start_date ? formatDate(order.start_date) : '—' }} – {{ order.end_date ? formatDate(order.end_date) : '—' }}</dd></div>
                            <div><dt class="text-slate-500">Sub total</dt><dd class="tabular">{{ formatMoney(order.subtotal) }}</dd></div>
                            <div><dt class="text-slate-500">GST {{ formatPercent(order.tax_percent) }}</dt><dd class="tabular">{{ formatMoney(order.tax_amount) }}</dd></div>
                            <div><dt class="text-slate-500">Value</dt><dd class="font-semibold tabular">{{ formatMoney(order.total_value) }}</dd></div>
                            <div><dt class="text-slate-500">Retention</dt><dd class="tabular">{{ formatPercent(order.retention_percent) }}</dd></div>
                            <div><dt class="text-slate-500">TDS</dt><dd class="tabular">{{ formatPercent(order.tds_percent) }}</dd></div>
                            <div><dt class="text-slate-500">Advance (unrecovered)</dt><dd class="tabular">{{ formatMoney(order.advance_amount) }} ({{ formatMoney(order.advance_balance) }})</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ order.created_by ?? '—' }}
                            <template v-if="order.approved_at"> · Approved {{ formatDateTime(order.approved_at) }} by {{ order.approved_by }}</template>
                            <template v-if="order.closed_at"> · Closed {{ formatDateTime(order.closed_at) }} by {{ order.closed_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.create_bill" size="sm" icon="plus" :href="route('projects.subcontractor-bills.create', { project: project.id, work_order_id: order.id })">New bill</AppButton>
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.work-orders.edit', [project.id, order.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="work order" />
                        <AppButton v-if="can.complete" size="sm" variant="secondary" @click="confirming = 'complete'">Mark completed</AppButton>
                        <AppButton v-if="can.close" size="sm" variant="secondary" @click="confirming = 'close'">Close</AppButton>
                        <AppButton v-if="can.cancel" size="sm" variant="danger" @click="cancelling = true">Cancel</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete work order" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="order.scope" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ order.scope }}</p>
            </AppCard>

            <AppCard title="Items" :subtitle="`${items.length} item(s) · certified quantity is recalculated from certified bills`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Item</th>
                                <th class="px-4 py-2.5 text-right">Quantity</th>
                                <th class="px-4 py-2.5 text-right">Rate</th>
                                <th class="px-4 py-2.5 text-right">Amount</th>
                                <th class="px-4 py-2.5 text-right">Certified</th>
                                <th class="px-4 py-2.5 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in items" :key="item.id" class="align-top">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ item.description }}</div>
                                    <div class="text-xs text-slate-500">{{ [item.boq_item, item.task].filter(Boolean).join(' · ') || 'Not linked to BOQ' }}</div>
                                </td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.quantity) }} <span class="text-slate-500">{{ item.unit }}</span></td>
                                <td class="px-4 py-3 text-right tabular">{{ formatRate(item.rate) }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatMoney(item.amount) }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.certified_qty) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular">{{ formatQty(item.balance_qty) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="`m-${item.id}`" class="px-4 py-3">
                        <div class="flex justify-between gap-3">
                            <p class="min-w-0 font-medium text-slate-900">{{ item.description }}</p>
                            <p class="shrink-0 font-semibold tabular">{{ formatMoney(item.amount) }}</p>
                        </div>
                        <p class="text-xs text-slate-500 tabular">{{ formatQty(item.quantity) }} {{ item.unit }} × {{ formatRate(item.rate) }} · certified {{ formatQty(item.certified_qty) }} · balance {{ formatQty(item.balance_qty) }}</p>
                        <p v-if="item.boq_item || item.task" class="text-xs text-slate-500">{{ [item.boq_item, item.task].filter(Boolean).join(' · ') }}</p>
                    </li>
                </ul>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Milestones" :padded="false">
                    <p v-if="!milestones.length" class="px-4 py-6 text-center text-sm text-slate-500">No milestones.</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="m in milestones" :key="m.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-5">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">{{ m.name }}</p>
                                <p class="text-xs text-slate-500">{{ formatPercent(m.amount_percent) }}<template v-if="m.due_date"> · due {{ formatDate(m.due_date) }}</template><template v-if="m.achieved_at"> · achieved {{ formatDate(m.achieved_at) }}</template></p>
                            </div>
                            <div class="flex items-center gap-2">
                                <StatusBadge :status="m.status" />
                                <AppButton v-if="can.milestones" size="sm" variant="ghost" :loading="savingMilestone === m.id" @click="toggleMilestone(m)">{{ m.status === 'achieved' ? 'Undo' : 'Mark achieved' }}</AppButton>
                            </div>
                        </li>
                    </ul>
                </AppCard>
                <AppCard title="Bills" :padded="false">
                    <p v-if="!bills.length" class="px-4 py-6 text-center text-sm text-slate-500">No bills yet.</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="b in bills" :key="b.id">
                            <Link :href="route('projects.subcontractor-bills.show', [project.id, b.id])" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-slate-50 sm:px-5">
                                <div>
                                    <p class="font-mono text-xs font-medium text-slate-900">{{ b.bill_number }}</p>
                                    <p class="text-xs text-slate-500">{{ formatDate(b.bill_date) }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <StatusBadge :status="b.status" :label="b.status_label" />
                                    <span class="text-sm font-semibold tabular">{{ formatMoney(b.net_payable) }}</span>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </AppCard>
            </div>

            <p v-if="order.terms" class="rounded-xl border border-line bg-white px-4 py-3 text-sm whitespace-pre-line text-slate-700 shadow-sm sm:px-5"><span class="block text-xs font-semibold text-slate-500 uppercase">Terms</span>{{ order.terms }}</p>

            <AttachmentPanel :attachments="attachments" attachable-type="work_order" :attachable-id="order.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Signed work order, drawings…" />
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
            :show="cancelling"
            :url="route('projects.work-orders.cancel', [project.id, order.id])"
            title="Cancel work order"
            message="Only possible while no bill has been raised. To change an approved work order, cancel it and create a new one."
            confirm-label="Cancel work order"
            @close="cancelling = false"
        />
    </ProjectLayout>
</template>
