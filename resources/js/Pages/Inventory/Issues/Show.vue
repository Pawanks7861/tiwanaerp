<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatQty, formatRate } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    issue: { type: Object, required: true },
    items: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const confirming = ref(null);
const cancelling = ref(false);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'Stock availability is checked now and again at approval. Approval posts the issue to the stock ledger and the project cost.', label: 'Submit', danger: false },
    delete: { title: 'Delete this issue?', message: 'This draft will be removed.', label: 'Delete', danger: true },
};
function confirmAction() {
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    confirming.value === 'delete'
        ? router.delete(route('projects.material-issues.destroy', [props.project.id, props.issue.id]), options)
        : router.post(route('projects.material-issues.submit', [props.project.id, props.issue.id]), {}, options);
}

const statusNote = computed(() => {
    if (props.approval) {
        return null;
    }

    return {
        approved: 'Posted: the material left the store at the weighted average cost and was charged to the project.',
        rejected: 'Rejected. Edit and resubmit, or delete this issue.',
        cancelled: `Cancelled${props.issue.cancellation_reason ? `: ${props.issue.cancellation_reason}` : ''}. Stock and cost postings were reversed.`,
    }[props.issue.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="inventory" :title="issue.issue_number">
        <InventoryNav :project-id="project.id" active="issues" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.material-issues.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Material issues</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ issue.issue_number }}</h2>
                            <StatusBadge :status="issue.status" :label="issue.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">Issued to {{ issue.issued_to ?? '—' }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Issue date</dt><dd>{{ formatDate(issue.issue_date) }}</dd></div>
                            <div><dt class="text-slate-500">Store</dt><dd>{{ issue.warehouse?.name }}</dd></div>
                            <div v-if="issue.purpose"><dt class="text-slate-500">Purpose</dt><dd>{{ issue.purpose }}</dd></div>
                            <div v-if="can.view_valuation && issue.total"><dt class="text-slate-500">Value</dt><dd class="font-semibold tabular">{{ formatMoney(issue.total) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ issue.created_by ?? '—' }}
                            <template v-if="issue.approved_at"> · Approved {{ formatDateTime(issue.approved_at) }} by {{ issue.approved_by }}</template>
                            <template v-if="issue.cancelled_at"> · Cancelled {{ formatDateTime(issue.cancelled_at) }} by {{ issue.cancelled_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.material-issues.edit', [project.id, issue.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="issue" />
                        <AppButton v-if="can.cancel" size="sm" variant="danger" @click="cancelling = true">Cancel issue</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete issue" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="page.props.errors?.items" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ page.props.errors.items }}</p>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="issue.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ issue.remarks }}</p>
            </AppCard>

            <AppCard title="Lines" :subtitle="`${items.length} line(s)`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Item</th>
                                <th class="px-4 py-2.5 text-left">BOQ item / task</th>
                                <th class="px-4 py-2.5 text-right">Quantity</th>
                                <th v-if="can.view_valuation" class="px-4 py-2.5 text-right">Unit cost</th>
                                <th v-if="can.view_valuation" class="px-4 py-2.5 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in items" :key="item.id" class="align-top">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ item.material?.name }}</div>
                                    <div class="text-xs text-slate-500"><span class="font-mono">{{ item.material?.code }}</span><template v-if="item.remarks"> · {{ item.remarks }}</template></div>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600">
                                    <div>{{ item.boq_item ?? '—' }}</div>
                                    <div v-if="item.task" class="text-slate-500">{{ item.task }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular">{{ formatQty(item.quantity) }} <span class="font-normal text-slate-500">{{ item.unit }}</span></td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ item.unit_cost ? formatRate(item.unit_cost) : '—' }}</td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ item.amount ? formatMoney(item.amount) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="`m-${item.id}`" class="px-4 py-3">
                        <div class="flex justify-between gap-3">
                            <p class="font-medium text-slate-900">{{ item.material?.name }}</p>
                            <p class="shrink-0 font-semibold tabular">{{ formatQty(item.quantity) }} <span class="text-xs font-normal text-slate-500">{{ item.unit }}</span></p>
                        </div>
                        <p v-if="item.boq_item || item.task" class="text-xs text-slate-500">{{ [item.boq_item, item.task].filter(Boolean).join(' · ') }}</p>
                        <p v-if="can.view_valuation && item.amount" class="text-xs text-slate-500 tabular">{{ formatRate(item.unit_cost) }} · {{ formatMoney(item.amount) }}</p>
                    </li>
                </ul>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="material_issue" :attachable-id="issue.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Signed issue slip, photos…" />
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
            :url="route('projects.material-issues.cancel', [project.id, issue.id])"
            title="Cancel posted issue"
            message="The stock goes back into the store and the project cost is reversed. Not possible while a return refers to this issue."
            confirm-label="Cancel issue"
            @close="cancelling = false"
        />
    </ProjectLayout>
</template>
