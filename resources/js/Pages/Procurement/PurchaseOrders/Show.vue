<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    order: { type: Object, required: true },
    items: { type: Array, required: true },
    revisions: { type: Array, required: true },
    grns: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    audit: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const po = computed(() => props.order);
const intra = computed(() => po.value.tax_type === 'intra');

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'GST and totals are recalculated, then the order is locked while it is being approved.', label: 'Submit', danger: false },
    delete: { title: 'Delete this purchase order?', message: 'This draft is removed. If it was created from an RFQ, the RFQ is reopened for evaluation.', label: 'Delete', danger: true },
};
function confirmAction() {
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    if (confirming.value === 'delete') {
        router.delete(route('projects.purchase-orders.destroy', [props.project.id, po.value.id]), options);
    } else {
        router.post(route('projects.purchase-orders.submit', [props.project.id, po.value.id]), {}, options);
    }
}

const reasonAction = ref(null);
const reasonForm = useForm({ reason: '' });
const REASON = {
    amend: { title: 'Amend purchase order', help: 'A snapshot of the current approved order is kept as a revision. The order returns to draft and must be approved again.', button: 'Start amendment', variant: 'primary', route: 'projects.purchase-orders.amend' },
    cancel: { title: 'Cancel purchase order', help: 'Ordered quantities are released back to the material requests.', button: 'Cancel order', variant: 'danger', route: 'projects.purchase-orders.cancel' },
    close: { title: 'Close purchase order', help: 'Short-close the order: nothing more will be received. Quantities not received are released back to the material requests.', button: 'Close order', variant: 'danger', route: 'projects.purchase-orders.close' },
};
function openReason(action) {
    reasonForm.reset();
    reasonForm.clearErrors();
    reasonAction.value = action;
}
function submitReason() {
    reasonForm.post(route(REASON[reasonAction.value].route, [props.project.id, po.value.id]), { preserveScroll: true, onSuccess: () => (reasonAction.value = null) });
}

const statusNote = computed(() => {
    if (props.approval) {
        return null;
    }
    if (po.value.status === 'cancelled' || po.value.status === 'closed') {
        return `${po.value.status === 'cancelled' ? 'Cancelled' : 'Closed'}${po.value.cancelled_by ? ` by ${po.value.cancelled_by}` : ''}${po.value.cancelled_at ? ` on ${formatDateTime(po.value.cancelled_at)}` : ''}${po.value.cancelled_reason ? `: ${po.value.cancelled_reason}` : ''}`;
    }
    if (po.value.status === 'draft' && po.value.revision_no > 0) {
        return `Amendment in progress (revision ${po.value.revision_no}). Edit and submit for approval again.`;
    }

    return {
        approved: 'Approved and locked. Goods can be received against this order. To change it, amend the order.',
        partially_received: 'Partly received.',
        received: 'Fully received.',
        rejected: 'Rejected. Edit and resubmit, or cancel the order.',
    }[po.value.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="po.po_number">
        <ProcurementNav :project-id="project.id" active="purchase-orders" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.purchase-orders.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Purchase orders</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ po.po_number }}</h2>
                            <StatusBadge :status="po.status" :label="po.status_label" />
                            <span v-if="po.revision_no > 0" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">Revision {{ po.revision_no }}</span>
                            <span v-if="po.is_direct" class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700">Direct PO</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ po.vendor?.name }} <span class="text-xs text-slate-500">· {{ po.vendor?.gstin || 'No GSTIN' }}</span></p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">PO date</dt><dd>{{ formatDate(po.po_date) }}</dd></div>
                            <div><dt class="text-slate-500">Delivery by</dt><dd>{{ formatDate(po.delivery_date) }}</dd></div>
                            <div><dt class="text-slate-500">Place of supply</dt><dd>{{ po.place_of_supply_label ?? po.place_of_supply_state }}</dd></div>
                            <div><dt class="text-slate-500">GST</dt><dd>{{ po.tax_type_label ?? '—' }} <span class="text-slate-400">(vendor state {{ po.vendor_state_code }})</span></dd></div>
                            <div v-if="po.rfq"><dt class="text-slate-500">RFQ</dt><dd><Link :href="po.rfq.url" class="font-mono text-brand-700 hover:underline">{{ po.rfq.number }}</Link></dd></div>
                            <div v-if="po.payment_terms"><dt class="text-slate-500">Payment terms</dt><dd>{{ po.payment_terms }}</dd></div>
                        </dl>
                        <p v-if="po.approved_at" class="mt-2 text-xs text-slate-500">Approved {{ formatDateTime(po.approved_at) }}<template v-if="po.approved_by"> by {{ po.approved_by }}</template></p>
                    </div>
                    <div class="flex flex-col gap-3 lg:items-end">
                        <div class="text-right">
                            <p class="text-xs text-slate-500 uppercase">Grand total</p>
                            <p class="text-xl font-semibold text-slate-900 tabular">{{ formatMoney(po.grand_total) }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2 lg:justify-end">
                            <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.purchase-orders.edit', [project.id, po.id])">Edit</AppButton>
                            <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                            <ApprovalActions :approval="approval" noun="purchase order" />
                            <AppButton v-if="can.receive" size="sm" icon="truck" :href="route('projects.grns.create', { project: project.id, purchase_order: po.id })">Receive goods</AppButton>
                            <a
                                v-if="can.export"
                                :href="route('projects.purchase-orders.pdf', [project.id, po.id])"
                                class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                            ><Icon name="download" :size="14" /> PDF</a>
                            <AppButton v-if="can.amend" size="sm" variant="secondary" @click="openReason('amend')">Amend</AppButton>
                            <AppButton v-if="can.close" size="sm" variant="ghost" @click="openReason('close')">Close</AppButton>
                            <AppButton v-if="can.cancel" size="sm" variant="ghost" class="text-red-600" @click="openReason('cancel')">Cancel</AppButton>
                            <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete purchase order" @click="confirming = 'delete'" />
                        </div>
                    </div>
                </div>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="po.direct_justification" class="border-t border-line px-4 py-3 text-xs text-slate-600 sm:px-5"><span class="font-medium text-slate-700">Direct PO justification:</span> {{ po.direct_justification }}</p>
            </AppCard>

            <AppCard title="Lines" :subtitle="`${items.length} line(s)`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-xs">
                        <thead class="bg-slate-50 tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2.5 text-left">#</th>
                                <th class="px-3 py-2.5 text-left">Item</th>
                                <th class="px-3 py-2.5 text-right">Qty</th>
                                <th class="px-3 py-2.5 text-right">Rate</th>
                                <th class="px-3 py-2.5 text-right">Disc.</th>
                                <th class="px-3 py-2.5 text-right">Taxable</th>
                                <template v-if="intra">
                                    <th class="px-3 py-2.5 text-right">CGST</th>
                                    <th class="px-3 py-2.5 text-right">SGST</th>
                                </template>
                                <th v-else class="px-3 py-2.5 text-right">IGST</th>
                                <th class="px-3 py-2.5 text-right">Amount</th>
                                <th class="px-3 py-2.5 text-right">Received</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(item, i) in items" :key="item.id" class="align-top">
                                <td class="px-3 py-2.5 text-slate-400 tabular">{{ i + 1 }}</td>
                                <td class="px-3 py-2.5">
                                    <div class="text-sm font-medium text-slate-900">{{ item.description }}</div>
                                    <div class="text-slate-500"><span class="font-mono">{{ item.item_code }}</span><template v-if="item.hsn_sac"> · HSN {{ item.hsn_sac }}</template><template v-if="item.material_request"> · {{ item.material_request }}</template></div>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</td>
                                <td class="px-3 py-2.5 text-right tabular">{{ formatRate(item.rate) }}</td>
                                <td class="px-3 py-2.5 text-right tabular">{{ formatPercent(item.discount_percent) }}</td>
                                <td class="px-3 py-2.5 text-right tabular">{{ formatMoney(item.taxable_amount) }}</td>
                                <template v-if="intra">
                                    <td class="px-3 py-2.5 text-right tabular">{{ formatMoney(item.cgst_amount) }}<div class="text-slate-400">{{ formatPercent(item.cgst_rate) }}</div></td>
                                    <td class="px-3 py-2.5 text-right tabular">{{ formatMoney(item.sgst_amount) }}<div class="text-slate-400">{{ formatPercent(item.sgst_rate) }}</div></td>
                                </template>
                                <td v-else class="px-3 py-2.5 text-right tabular">{{ formatMoney(item.igst_amount) }}<div class="text-slate-400">{{ formatPercent(item.igst_rate) }}</div></td>
                                <td class="px-3 py-2.5 text-right font-medium text-slate-900 tabular">{{ formatMoney(item.amount) }}</td>
                                <td class="px-3 py-2.5 text-right tabular">{{ formatQty(item.received_qty) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="item.id" class="px-4 py-3">
                        <div class="flex justify-between gap-3">
                            <p class="font-medium text-slate-900">{{ item.description }}</p>
                            <p class="shrink-0 font-semibold tabular">{{ formatMoney(item.amount) }}</p>
                        </div>
                        <p class="text-xs text-slate-500 tabular">{{ formatQty(item.quantity) }} {{ item.unit }} × {{ formatRate(item.rate) }}<template v-if="item.discount_percent !== '0.0000'"> − {{ formatPercent(item.discount_percent) }}</template></p>
                        <p class="text-xs text-slate-500 tabular">
                            Taxable {{ formatMoney(item.taxable_amount) }} ·
                            <template v-if="intra">CGST {{ formatMoney(item.cgst_amount) }} · SGST {{ formatMoney(item.sgst_amount) }}</template>
                            <template v-else>IGST {{ formatMoney(item.igst_amount) }}</template>
                        </p>
                        <p class="text-xs text-slate-500">Received {{ formatQty(item.received_qty) }} {{ item.unit }}</p>
                    </li>
                </ul>
                <dl class="ml-auto max-w-sm space-y-1 border-t border-line p-4 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Sub total</dt><dd class="tabular">{{ formatMoney(po.subtotal) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="tabular">-{{ formatMoney(po.discount_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Taxable value</dt><dd class="tabular">{{ formatMoney(po.taxable_amount) }}</dd></div>
                    <template v-if="intra">
                        <div class="flex justify-between"><dt class="text-slate-500">CGST</dt><dd class="tabular">{{ formatMoney(po.cgst_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">SGST</dt><dd class="tabular">{{ formatMoney(po.sgst_amount) }}</dd></div>
                    </template>
                    <div v-else class="flex justify-between"><dt class="text-slate-500">IGST</dt><dd class="tabular">{{ formatMoney(po.igst_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Freight</dt><dd class="tabular">{{ formatMoney(po.freight_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Other charges</dt><dd class="tabular">{{ formatMoney(po.other_charges) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Round off</dt><dd class="tabular">{{ formatMoney(po.round_off) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Grand total</dt><dd class="tabular">{{ formatMoney(po.grand_total) }}</dd></div>
                </dl>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Addresses & terms">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs text-slate-500">Bill to</dt><dd class="whitespace-pre-line text-slate-800">{{ po.billing_address || '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Ship to</dt><dd class="whitespace-pre-line text-slate-800">{{ po.shipping_address || '—' }}</dd></div>
                        <div v-if="po.terms" class="sm:col-span-2"><dt class="text-xs text-slate-500">Terms</dt><dd class="whitespace-pre-line text-slate-800">{{ po.terms }}</dd></div>
                        <div v-if="po.remarks" class="sm:col-span-2"><dt class="text-xs text-slate-500">Remarks</dt><dd class="whitespace-pre-line text-slate-800">{{ po.remarks }}</dd></div>
                    </dl>
                </AppCard>
                <div class="space-y-4">
                    <AppCard title="Goods receipts" :padded="false">
                        <ul v-if="grns.length" class="divide-y divide-line text-sm">
                            <li v-for="g in grns" :key="g.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <Link :href="g.url" class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ g.grn_number }}</Link>
                                <span class="text-xs text-slate-500">{{ formatDate(g.receipt_date) }}</span>
                                <StatusBadge :status="g.status" :label="g.status_label" />
                            </li>
                        </ul>
                        <p v-else class="px-4 py-6 text-center text-sm text-slate-500">No goods received yet.</p>
                    </AppCard>
                    <AppCard v-if="revisions.length" title="Revision history" :padded="false">
                        <ul class="divide-y divide-line text-sm">
                            <li v-for="r in revisions" :key="r.id" class="px-4 py-2.5">
                                <div class="flex justify-between gap-3">
                                    <span class="font-medium text-slate-800">Revision {{ r.revision_no }}</span>
                                    <span class="tabular">{{ formatMoney(r.grand_total) }}</span>
                                </div>
                                <p class="text-xs text-slate-500">Amended {{ formatDateTime(r.created_at) }}<template v-if="r.created_by"> by {{ r.created_by }}</template> · {{ r.reason }}</p>
                            </li>
                        </ul>
                    </AppCard>
                </div>
            </div>

            <AttachmentPanel
                :attachments="attachments"
                attachable-type="purchase_order"
                :attachable-id="po.id"
                :can-upload="can.attach"
                :can-delete="can.attach"
                placeholder="Signed PO, vendor acceptance…"
            />
            <div class="mt-4">
                <AuditTrail :entries="audit" title="Audit trail" />
            </div>
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

        <AppModal :show="!!reasonAction" :title="reasonAction ? REASON[reasonAction].title : ''" @close="reasonAction = null">
            <p class="mb-3 text-sm text-slate-600">{{ reasonAction ? REASON[reasonAction].help : '' }}</p>
            <FormInput v-model="reasonForm.reason" label="Reason" required multiline :rows="3" maxlength="1000" :error="reasonForm.errors.reason || reasonForm.errors.purchase_order" />
            <template #footer>
                <AppButton variant="secondary" @click="reasonAction = null">Back</AppButton>
                <AppButton :variant="reasonAction ? REASON[reasonAction].variant : 'primary'" :loading="reasonForm.processing" @click="submitReason">
                    {{ reasonAction ? REASON[reasonAction].button : '' }}
                </AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
