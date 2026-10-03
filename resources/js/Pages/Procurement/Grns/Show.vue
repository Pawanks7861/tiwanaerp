<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatQty, formatRate } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    grn: { type: Object, required: true },
    items: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'The receipt is locked while it is being approved. Received quantities update the purchase order only after approval.', label: 'Submit', danger: false },
    delete: { title: 'Delete this GRN?', message: 'This draft receipt will be removed.', label: 'Delete', danger: true },
};
function confirmAction() {
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    if (confirming.value === 'delete') {
        router.delete(route('projects.grns.destroy', [props.project.id, props.grn.id]), options);
    } else {
        router.post(route('projects.grns.submit', [props.project.id, props.grn.id]), {}, options);
    }
}

const statusNote = computed(() => {
    if (props.approval) {
        return null;
    }

    return {
        approved: 'Approved and locked. Accepted quantities are counted as received on the purchase order and material request.',
        rejected: 'Rejected. Edit and resubmit, or delete this receipt.',
    }[props.grn.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="grn.grn_number">
        <ProcurementNav :project-id="project.id" active="grns" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.grns.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Goods receipts</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ grn.grn_number }}</h2>
                            <StatusBadge :status="grn.status" :label="grn.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ grn.vendor?.name }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Receipt date</dt><dd>{{ formatDate(grn.receipt_date) }}</dd></div>
                            <div>
                                <dt class="text-slate-500">Purchase order</dt>
                                <dd>
                                    <Link v-if="grn.purchase_order?.url" :href="grn.purchase_order.url" class="font-mono text-brand-700 hover:underline">{{ grn.purchase_order.po_number }}</Link>
                                    <span v-else class="font-mono">{{ grn.purchase_order?.po_number }}</span>
                                </dd>
                            </div>
                            <div><dt class="text-slate-500">Warehouse</dt><dd>{{ grn.warehouse?.name ?? 'Site' }}</dd></div>
                            <div><dt class="text-slate-500">Vendor invoice</dt><dd>{{ grn.vendor_invoice_no || '—' }}<template v-if="grn.vendor_invoice_date"> · {{ formatDate(grn.vendor_invoice_date) }}</template></dd></div>
                            <div v-if="grn.vendor_challan_no"><dt class="text-slate-500">Challan</dt><dd>{{ grn.vendor_challan_no }}</dd></div>
                            <div v-if="grn.vehicle_no"><dt class="text-slate-500">Vehicle</dt><dd>{{ grn.vehicle_no }}</dd></div>
                        </dl>
                        <p v-if="grn.approved_at" class="mt-2 text-xs text-slate-500">Approved {{ formatDateTime(grn.approved_at) }}<template v-if="grn.approved_by"> by {{ grn.approved_by }}</template></p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.grns.edit', [project.id, grn.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="GRN" />
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete GRN" @click="confirming = 'delete'" />
                    </div>
                </div>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="grn.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ grn.remarks }}</p>
            </AppCard>

            <AppCard title="Lines" :subtitle="`${items.length} line(s)`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Item</th>
                                <th class="px-4 py-2.5 text-right">Ordered</th>
                                <th class="px-4 py-2.5 text-right">Earlier</th>
                                <th class="px-4 py-2.5 text-right">Received</th>
                                <th class="px-4 py-2.5 text-right">Rejected</th>
                                <th class="px-4 py-2.5 text-right">Accepted</th>
                                <th v-if="can.view_rates" class="px-4 py-2.5 text-right">Rate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in items" :key="item.id" class="align-top">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ item.description }}</div>
                                    <div class="text-xs text-slate-500"><span class="font-mono">{{ item.item_code }}</span><template v-if="item.rejection_reason"> · Rejected: {{ item.rejection_reason }}</template></div>
                                </td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.ordered_qty) }} {{ item.unit }}</td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular">{{ formatQty(item.previously_received_qty) }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.received_qty) }}</td>
                                <td class="px-4 py-3 text-right tabular" :class="item.rejected_qty !== '0.0000' ? 'text-red-700' : 'text-slate-400'">{{ formatQty(item.rejected_qty) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular">{{ formatQty(item.accepted_qty) }}</td>
                                <td v-if="can.view_rates" class="px-4 py-3 text-right tabular">{{ formatRate(item.rate) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="item.id" class="px-4 py-3">
                        <p class="font-medium text-slate-900">{{ item.description }}</p>
                        <dl class="mt-1 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
                            <dt class="text-slate-500">Ordered</dt><dd class="text-right tabular">{{ formatQty(item.ordered_qty) }} {{ item.unit }}</dd>
                            <dt class="text-slate-500">Received</dt><dd class="text-right tabular">{{ formatQty(item.received_qty) }}</dd>
                            <dt class="text-slate-500">Rejected</dt><dd class="text-right tabular">{{ formatQty(item.rejected_qty) }}</dd>
                            <dt class="text-slate-500">Accepted</dt><dd class="text-right font-semibold tabular">{{ formatQty(item.accepted_qty) }}</dd>
                        </dl>
                        <p v-if="item.rejection_reason" class="mt-1 text-xs text-red-700">Rejected: {{ item.rejection_reason }}</p>
                    </li>
                </ul>
            </AppCard>

            <AttachmentPanel
                :attachments="attachments"
                attachable-type="grn"
                :attachable-id="grn.id"
                :can-upload="can.attach"
                :can-delete="can.attach"
                placeholder="Vendor invoice, delivery challan, photos…"
            />
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
    </ProjectLayout>
</template>
