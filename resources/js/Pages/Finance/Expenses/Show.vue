<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import TallyStatus from '@/Components/Integrations/TallyStatus.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    expense: { type: Object, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    audit: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    tally: { type: Object, default: null },
    today: { type: String, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['expense', 'approval', 'petty_cash_account_id', 'amount'].map((k) => page.props.errors?.[k]).find(Boolean));

const confirming = ref(null);
const reversing = ref(false);
const paying = ref(false);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'Once approved, the amount excluding GST is posted to the project cost.', label: 'Submit', danger: false, method: 'post', route: 'projects.expenses.submit' },
    delete: { title: 'Delete this expense?', message: 'This draft will be removed.', label: 'Delete', danger: true, method: 'delete', route: 'projects.expenses.destroy' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(action.route, [props.project.id, props.expense.id]);
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const payForm = useForm({ paid_on: props.today, payment_reference: '' });
function markPaid() {
    payForm
        .transform((d) => ({ ...d, payment_reference: d.payment_reference || null }))
        .post(route('projects.expenses.mark-paid', [props.project.id, props.expense.id]), { preserveScroll: true, onSuccess: () => (paying.value = false) });
}

const statusNote = computed(() => {
    if (props.approval) {
        return null;
    }
    const e = props.expense;

    return {
        approved: 'Approved: the cost was posted to the project. Mark it paid when the money goes out (no further cost is posted).',
        paid: e.payment_mode === 'petty_cash' ? 'Approved and paid from petty cash; the cost was posted once at approval.' : 'Paid. The cost was posted at approval; payment posted nothing.',
        rejected: 'Rejected. Edit and resubmit, or delete this expense.',
        draft: e.revision > 0 ? `Reversed (revision ${e.revision})${e.reversal_reason ? `: ${e.reversal_reason}` : ''}. Its cost${e.payment_mode === 'petty_cash' ? ' and petty cash spend were' : ' was'} reversed.` : null,
    }[e.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="expense.expense_number">
        <FinanceNav :project-id="project.id" active="expenses" />
        <TallyStatus :tally="tally" class="mb-4" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.expenses.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Expenses</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ expense.expense_number }}</h2>
                            <StatusBadge :status="expense.status" :label="expense.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ expense.description }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Date</dt><dd>{{ formatDate(expense.expense_date) }}</dd></div>
                            <div><dt class="text-slate-500">Category</dt><dd>{{ expense.category }}</dd></div>
                            <div><dt class="text-slate-500">Cost head</dt><dd>{{ expense.cost_head_label }}</dd></div>
                            <div><dt class="text-slate-500">Payee</dt><dd>{{ expense.payee ?? '—' }}</dd></div>
                            <div><dt class="text-slate-500">Paid by</dt><dd>{{ expense.payment_mode_label }}<template v-if="expense.petty_cash_account"> · {{ expense.petty_cash_account }}</template></dd></div>
                            <div v-if="expense.reference_no"><dt class="text-slate-500">Bill no.</dt><dd>{{ expense.reference_no }}</dd></div>
                            <div v-if="expense.task"><dt class="text-slate-500">Task</dt><dd>{{ expense.task }}</dd></div>
                            <div v-if="expense.paid_on"><dt class="text-slate-500">Paid on</dt><dd>{{ formatDate(expense.paid_on) }}<template v-if="expense.payment_reference"> · {{ expense.payment_reference }}</template></dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ expense.created_by ?? '—' }}
                            <template v-if="expense.approved_at"> · Approved {{ formatDateTime(expense.approved_at) }} by {{ expense.approved_by }}</template>
                            <template v-if="expense.reversed_at"> · Reversed {{ formatDateTime(expense.reversed_at) }} by {{ expense.reversed_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.expenses.edit', [project.id, expense.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="expense" />
                        <AppButton v-if="can.markPaid" size="sm" @click="paying = true">Mark paid</AppButton>
                        <AppButton v-if="can.reverse" size="sm" variant="danger" @click="reversing = true">Reverse</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete expense" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>.
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
            </AppCard>

            <AppCard title="Amount">
                <dl class="max-w-md space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Amount (project cost)</dt><dd class="tabular">{{ formatMoney(expense.amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">GST</dt><dd class="tabular">{{ formatMoney(expense.tax_amount) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Total paid out</dt><dd class="tabular">{{ formatMoney(expense.total_amount) }}</dd></div>
                </dl>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="expense" :attachable-id="expense.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Bill, receipt photo…" />
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
            :show="reversing"
            :url="route('projects.expenses.reverse', [project.id, expense.id])"
            title="Reverse expense"
            message="A compensating cost entry is posted (and any petty cash spend is returned to the account). The expense returns to draft as a new revision."
            confirm-label="Reverse"
            @close="reversing = false"
        />
        <AppModal :show="paying" title="Mark expense paid" @close="paying = false">
            <p class="mb-3 text-sm text-slate-600">Records the payment only. The cost was already posted at approval.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput v-model="payForm.paid_on" type="date" label="Paid on" required :max="today" :error="payForm.errors.paid_on" />
                <FormInput v-model="payForm.payment_reference" label="Reference" maxlength="100" :error="payForm.errors.payment_reference" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="paying = false">Back</AppButton>
                <AppButton :loading="payForm.processing" @click="markPaid">Mark paid</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
