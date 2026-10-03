<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
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
    adjustment: { type: Object, required: true },
    items: { type: Array, required: true },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const confirming = ref(null);
const reasonFor = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'Book quantities are checked again; another user with adjustment approval rights must approve.', label: 'Submit', danger: false, route: 'projects.stock-adjustments.submit' },
    approve: { title: 'Approve and post?', message: 'The differences are posted to the stock ledger now. Approval is refused if the book stock changed since the count.', label: 'Approve', danger: false, route: 'projects.stock-adjustments.approve' },
    delete: { title: 'Delete this adjustment?', message: 'This draft will be removed.', label: 'Delete', danger: true },
};
const REASONS = {
    reject: { title: 'Reject adjustment', message: 'The adjustment goes back to the store team to recount or correct.', label: 'Reject', route: 'projects.stock-adjustments.reject' },
    cancel: { title: 'Cancel posted adjustment', message: 'Every posted difference is reversed. Refused if added stock has since been used.', label: 'Cancel adjustment', route: 'projects.stock-adjustments.cancel' },
};
function confirmAction() {
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    confirming.value === 'delete'
        ? router.delete(route('projects.stock-adjustments.destroy', [props.project.id, props.adjustment.id]), options)
        : router.post(route(ACTIONS[confirming.value].route, [props.project.id, props.adjustment.id]), {}, options);
}
const pageErrors = computed(() => [page.props.errors?.items].filter(Boolean));

const statusNote = computed(
    () =>
        ({
            submitted: `Submitted${props.adjustment.submitted_by ? ` by ${props.adjustment.submitted_by}` : ''}; waiting for approval by another user with adjustment approval rights.`,
            approved: 'Posted to the stock ledger.',
            rejected: `Rejected${props.adjustment.rejection_reason ? `: ${props.adjustment.rejection_reason}` : ''}. Save it again to refresh the book quantities, then resubmit.`,
            cancelled: `Cancelled${props.adjustment.cancellation_reason ? `: ${props.adjustment.cancellation_reason}` : ''}. Postings were reversed.`,
        })[props.adjustment.status] ?? null,
);
</script>

<template>
    <ProjectLayout :project="project" active="inventory" :title="adjustment.adjustment_number">
        <InventoryNav :project-id="project.id" active="adjustments" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.stock-adjustments.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Stock adjustments</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ adjustment.adjustment_number }}</h2>
                            <StatusBadge :status="adjustment.status" :label="adjustment.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ adjustment.reason_label }} · {{ adjustment.warehouse?.name }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Date</dt><dd>{{ formatDate(adjustment.adjustment_date) }}</dd></div>
                            <div v-if="adjustment.submitted_at"><dt class="text-slate-500">Submitted</dt><dd>{{ formatDateTime(adjustment.submitted_at) }} · {{ adjustment.submitted_by }}</dd></div>
                            <div v-if="adjustment.approved_at"><dt class="text-slate-500">Approved</dt><dd>{{ formatDateTime(adjustment.approved_at) }} · {{ adjustment.approved_by }}</dd></div>
                            <div v-if="adjustment.cancelled_at"><dt class="text-slate-500">Cancelled</dt><dd>{{ formatDateTime(adjustment.cancelled_at) }} · {{ adjustment.cancelled_by }}</dd></div>
                        </dl>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.stock-adjustments.edit', [project.id, adjustment.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <AppButton v-if="can.approve" size="sm" @click="confirming = 'approve'">Approve</AppButton>
                        <AppButton v-if="can.reject" size="sm" variant="secondary" @click="reasonFor = 'reject'">Reject</AppButton>
                        <AppButton v-if="can.cancel" size="sm" variant="danger" @click="reasonFor = 'cancel'">Cancel</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete adjustment" @click="confirming = 'delete'" />
                    </div>
                </div>
                <div v-if="pageErrors.length" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">
                    <p v-for="(e, i) in pageErrors" :key="i">{{ e }}</p>
                </div>
                <div v-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ adjustment.remarks }}</p>
            </AppCard>

            <AppCard title="Counted items" :subtitle="`${items.length} line(s)`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Item</th>
                                <th class="px-4 py-2.5 text-right">Book</th>
                                <th class="px-4 py-2.5 text-right">Physical</th>
                                <th class="px-4 py-2.5 text-right">Difference</th>
                                <th v-if="can.view_valuation" class="px-4 py-2.5 text-right">Unit cost</th>
                                <th v-if="can.view_valuation" class="px-4 py-2.5 text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in items" :key="item.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ item.material?.name }}</div>
                                    <div class="text-xs text-slate-500"><span class="font-mono">{{ item.material?.code }}</span><template v-if="item.remarks"> · {{ item.remarks }}</template></div>
                                </td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular">{{ formatQty(item.system_qty) }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.physical_qty) }} {{ item.unit }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular" :class="Number(item.difference) < 0 ? 'text-red-700' : 'text-emerald-700'">{{ Number(item.difference) > 0 ? '+' : '' }}{{ formatQty(item.difference) }}</td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ item.unit_cost ? formatRate(item.unit_cost) : '—' }}</td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ item.value ? formatMoney(item.value) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="`m-${item.id}`" class="px-4 py-3">
                        <div class="flex justify-between gap-3">
                            <p class="font-medium text-slate-900">{{ item.material?.name }}</p>
                            <p class="shrink-0 font-semibold tabular" :class="Number(item.difference) < 0 ? 'text-red-700' : 'text-emerald-700'">{{ Number(item.difference) > 0 ? '+' : '' }}{{ formatQty(item.difference) }} {{ item.unit }}</p>
                        </div>
                        <p class="text-xs text-slate-500 tabular">Book {{ formatQty(item.system_qty) }} → physical {{ formatQty(item.physical_qty) }}<template v-if="can.view_valuation && item.value"> · {{ formatMoney(item.value) }}</template></p>
                    </li>
                </ul>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="stock_adjustment" :attachable-id="adjustment.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Count sheet, damage photos, police report…" />
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
            :show="!!reasonFor"
            :url="reasonFor ? route(REASONS[reasonFor].route, [project.id, adjustment.id]) : null"
            :title="reasonFor ? REASONS[reasonFor].title : ''"
            :message="reasonFor ? REASONS[reasonFor].message : null"
            :confirm-label="reasonFor ? REASONS[reasonFor].label : ''"
            @close="reasonFor = null"
        />
    </ProjectLayout>
</template>
