<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    payment: { type: Object, required: true },
    allocations: { type: Array, required: true },
    attachments: { type: Array, required: true },
    audit: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['payment', 'allocations', 'amount'].map((k) => page.props.errors?.[k]).find(Boolean) ?? Object.values(page.props.errors ?? {})[0]);
const receipt = computed(() => props.payment.direction === 'receipt');
const onAccount = computed(() => new Decimal(props.payment.amount || 0).minus(new Decimal(props.payment.allocated || 0)).toFixed(2));

const confirming = ref(null);
const cancelling = ref(false);
const processing = ref(false);
const ACTIONS = {
    approve: { title: 'Approve this entry?', message: 'Allocations are re-checked against the current outstanding of each document, then applied.', label: 'Approve', danger: false, method: 'post', route: 'projects.payments.approve' },
    delete: { title: 'Delete this draft?', message: 'The draft and its allocations will be removed.', label: 'Delete', danger: true, method: 'delete', route: 'projects.payments.destroy' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(action.route, [props.project.id, props.payment.id]);
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const statusNote = computed(() => ({
    draft: props.can.selfRecorded && !props.can.approve ? 'Draft. Another user with payment approval must approve it (you recorded it).' : 'Draft. Allocations take effect only after approval.',
    approved: 'Approved. The allocated documents show this amount as settled. Cancelling reverses that.',
    cancelled: `Cancelled${props.payment.cancellation_reason ? `: ${props.payment.cancellation_reason}` : ''}. Its allocations no longer count.`,
})[props.payment.status] ?? null);
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="payment.payment_number">
        <FinanceNav :project-id="project.id" active="payments" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.payments.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Receipts & payments</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ payment.payment_number }}</h2>
                            <StatusBadge :status="payment.status" :label="payment.status_label" />
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="receipt ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ payment.direction_label }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ payment.party_name }} <span class="text-xs text-slate-500">({{ payment.party_type_label }})</span></p>
                        <p class="mt-2 text-2xl font-semibold tabular" :class="receipt ? 'text-emerald-700' : 'text-slate-900'">{{ formatMoney(payment.amount) }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Date</dt><dd>{{ formatDate(payment.payment_date) }}</dd></div>
                            <div><dt class="text-slate-500">Mode</dt><dd>{{ payment.mode_label }}</dd></div>
                            <div v-if="payment.bank_reference"><dt class="text-slate-500">Reference</dt><dd>{{ payment.bank_reference }}</dd></div>
                            <div v-if="payment.tds_amount !== '0.00'"><dt class="text-slate-500">TDS (reference)</dt><dd>{{ formatMoney(payment.tds_amount) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Recorded by {{ payment.created_by ?? '—' }}
                            <template v-if="payment.approved_at"> · Approved {{ formatDateTime(payment.approved_at) }} by {{ payment.approved_by }}</template>
                            <template v-if="payment.cancelled_at"> · Cancelled {{ formatDateTime(payment.cancelled_at) }} by {{ payment.cancelled_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.payments.edit', [project.id, payment.id])">Edit</AppButton>
                        <AppButton v-if="can.approve" size="sm" @click="confirming = 'approve'">Approve</AppButton>
                        <AppButton v-if="can.cancel" size="sm" variant="danger" @click="cancelling = true">Cancel</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete draft" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="payment.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ payment.remarks }}</p>
            </AppCard>

            <AppCard title="Allocation" :padded="false">
                <div v-if="allocations.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-line bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">Document</th>
                                <th class="px-3 py-2 text-right font-medium">Due</th>
                                <th class="px-3 py-2 text-right font-medium">Outstanding now</th>
                                <th class="px-4 py-2 text-right font-medium">Allocated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="a in allocations" :key="a.id">
                                <td class="px-4 py-2">
                                    <Link v-if="a.url" :href="a.url" class="font-medium text-brand-700 hover:underline">{{ a.document }}</Link>
                                    <span v-else>{{ a.document }}</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(a.due) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(a.outstanding) }}</td>
                                <td class="px-4 py-2 text-right font-semibold tabular">{{ formatMoney(a.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-3 text-sm text-slate-500">Not allocated to any document.</p>
                <dl class="flex flex-wrap justify-end gap-x-8 gap-y-1 border-t border-line px-4 py-3 text-sm">
                    <div class="flex gap-2"><dt class="text-slate-500">Allocated</dt><dd class="font-medium tabular">{{ formatMoney(payment.allocated) }}</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500">{{ receipt ? 'Advance / on account' : 'On account' }}</dt><dd class="font-medium tabular">{{ formatMoney(onAccount) }}</dd></div>
                </dl>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="payment" :attachable-id="payment.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Bank advice, cheque copy…" />
            <div class="mt-4"><AuditTrail :entries="audit" title="Audit trail" /></div>
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
            :url="route('projects.payments.cancel', [project.id, payment.id])"
            title="Cancel this entry"
            message="The allocations stop counting and each document's received / paid amount and status are recalculated."
            confirm-label="Cancel entry"
            @close="cancelling = false"
        />
    </ProjectLayout>
</template>
