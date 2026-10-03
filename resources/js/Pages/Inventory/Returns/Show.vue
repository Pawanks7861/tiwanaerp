<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
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
    return: { type: Object, required: true },
    items: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const doc = computed(() => props.return);
const site = computed(() => doc.value.return_type === 'site_to_store');
const page = usePage();
const confirming = ref(null);
const cancelling = ref(false);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'Quantities are checked against what was issued (or accepted on the GRN). Approval posts the return to stock.', label: 'Submit', danger: false },
    delete: { title: 'Delete this return?', message: 'This draft will be removed.', label: 'Delete', danger: true },
};
function confirmAction() {
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    confirming.value === 'delete'
        ? router.delete(route('projects.material-returns.destroy', [props.project.id, doc.value.id]), options)
        : router.post(route('projects.material-returns.submit', [props.project.id, doc.value.id]), {}, options);
}

const statusNote = computed(() => {
    if (props.approval) {
        return null;
    }

    return {
        approved: site.value ? 'Posted: back in the store at the original issue cost; the project cost was credited.' : 'Posted: the goods left the store at the weighted average cost.',
        rejected: 'Rejected. Edit and resubmit, or delete this return.',
        cancelled: `Cancelled${doc.value.cancellation_reason ? `: ${doc.value.cancellation_reason}` : ''}. Postings were reversed.`,
    }[doc.value.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="inventory" :title="doc.return_number">
        <InventoryNav :project-id="project.id" active="returns" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.material-returns.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Material returns</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ doc.return_number }}</h2>
                            <StatusBadge :status="doc.status" :label="doc.status_label" />
                            <AppBadge :color="site ? 'blue' : 'orange'">{{ doc.type_label }}</AppBadge>
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ doc.reason }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Return date</dt><dd>{{ formatDate(doc.return_date) }}</dd></div>
                            <div><dt class="text-slate-500">Store</dt><dd>{{ doc.warehouse?.name }}</dd></div>
                            <div v-if="doc.vendor"><dt class="text-slate-500">Vendor</dt><dd>{{ doc.vendor.name }}</dd></div>
                            <div v-if="doc.grn">
                                <dt class="text-slate-500">GRN</dt>
                                <dd><Link v-if="doc.grn.url" :href="doc.grn.url" class="font-mono text-brand-700 hover:underline">{{ doc.grn.number }}</Link><span v-else class="font-mono">{{ doc.grn.number }}</span></dd>
                            </div>
                            <div v-if="can.view_valuation && doc.total"><dt class="text-slate-500">Value</dt><dd class="font-semibold tabular">{{ formatMoney(doc.total) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ doc.created_by ?? '—' }}
                            <template v-if="doc.approved_at"> · Approved {{ formatDateTime(doc.approved_at) }} by {{ doc.approved_by }}</template>
                            <template v-if="doc.cancelled_at"> · Cancelled {{ formatDateTime(doc.cancelled_at) }} by {{ doc.cancelled_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.material-returns.edit', [project.id, doc.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="return" />
                        <AppButton v-if="can.cancel" size="sm" variant="danger" @click="cancelling = true">Cancel return</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete return" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="page.props.errors?.items" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ page.props.errors.items }}</p>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="doc.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ doc.remarks }}</p>
            </AppCard>

            <AppCard title="Lines" :subtitle="`${items.length} line(s)`" :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="item in items" :key="item.id" class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">{{ item.material?.name }} <span class="font-mono text-xs font-normal text-slate-500">{{ item.material?.code }}</span></p>
                            <p class="text-xs text-slate-500">
                                <template v-if="item.issue_number">From issue <span class="font-mono">{{ item.issue_number }}</span></template>
                                <template v-if="item.remarks"> · {{ item.remarks }}</template>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-baseline gap-4 text-sm tabular">
                            <span class="font-semibold">{{ formatQty(item.quantity) }} <span class="text-xs font-normal text-slate-500">{{ item.unit }}</span></span>
                            <span v-if="can.view_valuation" class="text-xs text-slate-500">{{ item.unit_cost ? `${formatRate(item.unit_cost)} · ${formatMoney(item.value)}` : 'Valued on approval' }}</span>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="material_return" :attachable-id="doc.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Return slip, photos…" />
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
            :url="route('projects.material-returns.cancel', [project.id, doc.id])"
            title="Cancel posted return"
            message="The stock movement (and for site returns the cost credit) is reversed. Not possible if the returned stock has since been used."
            confirm-label="Cancel return"
            @close="cancelling = false"
        />
    </ProjectLayout>
</template>
