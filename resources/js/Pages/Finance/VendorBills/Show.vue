<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { usePermissions } from '@/lib/permissions';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    bill: { type: Object, required: true },
    items: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    audit: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const { can: canDo } = usePermissions();
const page = usePage();
const errorMessage = computed(() => ['bill', 'approval', 'items', 'vendor_invoice_no'].map((k) => page.props.errors?.[k]).find(Boolean));
const direct = computed(() => props.bill.bill_type === 'direct');

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'Quantities are re-checked against accepted GRN quantities, including other bills awaiting approval.', label: 'Submit', danger: false, method: 'post', route: 'projects.vendor-bills.submit' },
    delete: { title: 'Delete this bill?', message: 'This draft will be removed.', label: 'Delete', danger: true, method: 'delete', route: 'projects.vendor-bills.destroy' },
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
    const approvedNote = direct.value
        ? `Approved: the taxable value was posted to the project cost under ${props.bill.cost_head_label}.`
        : 'Approved: payable to the vendor. No cost was posted (purchased material is costed when issued from stock).';

    return {
        approved: approvedNote,
        partially_paid: `${approvedNote} Partly paid.`,
        paid: `${approvedNote} Fully paid.`,
        rejected: 'Rejected. Edit and resubmit, or delete this bill.',
    }[props.bill.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="bill.bill_number">
        <FinanceNav :project-id="project.id" active="vendor-bills" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.vendor-bills.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Vendor bills</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ bill.bill_number }}</h2>
                            <StatusBadge :status="bill.status" :label="bill.status_label" />
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ bill.bill_type_label }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-800">
                            {{ bill.vendor?.name ?? '—' }}<template v-if="bill.vendor?.gstin"> · GSTIN {{ bill.vendor.gstin }}</template>
                            <template v-if="bill.purchase_order"> · <Link :href="route('projects.purchase-orders.show', [project.id, bill.purchase_order.id])" class="font-mono text-brand-700 hover:underline">{{ bill.purchase_order.po_number }}</Link></template>
                        </p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Vendor invoice</dt><dd>{{ bill.vendor_invoice_no }}</dd></div>
                            <div><dt class="text-slate-500">Invoice date</dt><dd>{{ formatDate(bill.vendor_invoice_date) }}</dd></div>
                            <div v-if="bill.due_date"><dt class="text-slate-500">Due</dt><dd>{{ formatDate(bill.due_date) }}</dd></div>
                            <div v-if="direct"><dt class="text-slate-500">Cost head</dt><dd>{{ bill.cost_head_label }}</dd></div>
                            <div v-if="bill.task"><dt class="text-slate-500">Task</dt><dd>{{ bill.task }}</dd></div>
                            <div v-if="bill.outstanding !== null"><dt class="text-slate-500">Outstanding</dt><dd class="font-semibold">{{ formatMoney(bill.outstanding) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ bill.created_by ?? '—' }}
                            <template v-if="bill.approved_at"> · Approved {{ formatDateTime(bill.approved_at) }} by {{ bill.approved_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.vendor-bills.edit', [project.id, bill.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="bill" />
                        <AppButton v-if="bill.outstanding && bill.outstanding !== '0.00' && canDo('payments.record')" size="sm" :href="route('projects.payments.create', { project: project.id, party_type: 'vendor', party_id: bill.vendor?.id })">Record payment</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete bill" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>.
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="bill.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ bill.remarks }}</p>
            </AppCard>

            <AppCard :title="direct ? 'Lines' : 'Three-way match'" :subtitle="direct ? null : 'PO → GRN → bill. Billed elsewhere = this GRN line on the vendor\'s other submitted or approved bills.'" :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-line bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">Item</th>
                                <template v-if="!direct">
                                    <th class="px-3 py-2 text-right font-medium">PO qty</th>
                                    <th class="px-3 py-2 text-right font-medium">GRN received</th>
                                    <th class="px-3 py-2 text-right font-medium">GRN accepted</th>
                                    <th class="px-3 py-2 text-right font-medium">Billed elsewhere</th>
                                </template>
                                <th class="px-3 py-2 text-right font-medium">Bill qty</th>
                                <th class="px-3 py-2 text-right font-medium">Rate</th>
                                <th class="px-3 py-2 text-right font-medium">Taxable</th>
                                <th class="px-3 py-2 text-right font-medium">GST</th>
                                <th class="px-4 py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in items" :key="item.id">
                                <td class="min-w-48 px-4 py-2">
                                    {{ item.description }}
                                    <p class="text-xs text-slate-500"><template v-if="item.grn_number"><span class="font-mono">{{ item.grn_number }}</span></template><template v-if="item.hsn_sac"> · HSN {{ item.hsn_sac }}</template></p>
                                </td>
                                <template v-if="!direct">
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(item.po_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(item.grn_received_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(item.grn_accepted_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(item.billed_elsewhere) }}</td>
                                </template>
                                <td class="px-3 py-2 text-right font-medium whitespace-nowrap tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap tabular">
                                    {{ formatRate(item.rate) }}
                                    <p v-if="item.discount_percent && item.discount_percent !== '0.0000'" class="text-xs text-slate-500">less {{ formatPercent(item.discount_percent) }}</p>
                                </td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(item.taxable_amount) }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap tabular">
                                    <template v-if="bill.tax_type === 'inter'">{{ formatMoney(item.igst_amount) }}</template>
                                    <template v-else>{{ formatMoney(item.cgst_amount) }} + {{ formatMoney(item.sgst_amount) }}</template>
                                </td>
                                <td class="px-4 py-2 text-right font-semibold tabular">{{ formatMoney(item.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard title="Bill summary">
                <dl class="max-w-md space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Taxable value</dt><dd class="tabular">{{ formatMoney(bill.subtotal) }}</dd></div>
                    <div v-if="bill.tax_type === 'inter'" class="flex justify-between"><dt class="text-slate-500">IGST</dt><dd class="tabular">{{ formatMoney(bill.igst_amount) }}</dd></div>
                    <template v-else>
                        <div class="flex justify-between"><dt class="text-slate-500">CGST</dt><dd class="tabular">{{ formatMoney(bill.cgst_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">SGST</dt><dd class="tabular">{{ formatMoney(bill.sgst_amount) }}</dd></div>
                    </template>
                    <div class="flex justify-between font-medium"><dt>Invoice total</dt><dd class="tabular">{{ formatMoney(bill.total_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">TDS {{ formatPercent(bill.tds_percent) }}</dt><dd class="tabular">-{{ formatMoney(bill.tds_amount) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Net payable</dt><dd class="tabular">{{ formatMoney(bill.net_payable) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Paid</dt><dd class="tabular">{{ formatMoney(bill.paid_amount) }}</dd></div>
                </dl>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="vendor_bill" :attachable-id="bill.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Vendor invoice copy…" />
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
    </ProjectLayout>
</template>
