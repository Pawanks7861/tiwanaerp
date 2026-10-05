<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import TallyStatus from '@/Components/Integrations/TallyStatus.vue';
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
    tally: { type: Object, default: null },
});

const { can: canDo } = usePermissions();
const page = usePage();
const errorMessage = computed(() => ['bill', 'approval', 'items', 'advance_recovery'].map((k) => page.props.errors?.[k]).find(Boolean));
const certified = computed(() => ['certified', 'partially_paid', 'paid'].includes(props.bill.status));

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for certification?', message: 'Quantities are re-measured against recorded progress and the BOQ; the bill is locked while it is reviewed.', label: 'Submit', danger: false, method: 'post', route: 'projects.ra-bills.submit' },
    delete: { title: 'Delete this RA bill?', message: 'This draft is removed and its RA number is freed for the next bill.', label: 'Delete', danger: true, method: 'delete', route: 'projects.ra-bills.destroy' },
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
        certified: `Certified as tax invoice ${props.bill.invoice_number}. The bill is locked. Record receipts under Payments.`,
        partially_paid: 'Certified and partly received.',
        paid: 'Certified and fully received.',
        rejected: 'Rejected. Edit and resubmit.',
    }[props.bill.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="bill.display_number">
        <FinanceNav :project-id="project.id" active="ra-bills" />
        <TallyStatus :tally="tally" class="mb-4" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.ra-bills.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Client RA bills</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ bill.display_number }}</h2>
                            <StatusBadge :status="bill.status" :label="bill.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">RA bill {{ bill.ra_sequence }} · {{ bill.client?.company_name ?? '—' }}<template v-if="bill.client?.gstin"> · GSTIN {{ bill.client.gstin }}</template></p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Bill date</dt><dd>{{ formatDate(bill.invoice_date) }}</dd></div>
                            <div><dt class="text-slate-500">Work period</dt><dd>{{ formatDate(bill.period_from) }} – {{ formatDate(bill.period_to) }}</dd></div>
                            <div><dt class="text-slate-500">GST</dt><dd>{{ bill.tax_rate ?? 'None' }} · {{ bill.tax_type === 'inter' ? 'IGST' : 'CGST + SGST' }}</dd></div>
                            <div v-if="bill.outstanding !== null"><dt class="text-slate-500">Outstanding</dt><dd class="font-semibold">{{ formatMoney(bill.outstanding) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ bill.created_by ?? '—' }}
                            <template v-if="bill.certified_at"> · Certified {{ formatDateTime(bill.certified_at) }} by {{ bill.certified_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <a
                            v-if="can.export"
                            :href="route('projects.ra-bills.pdf', [project.id, bill.id])"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                        ><Icon name="download" :size="14" /> PDF</a>
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.ra-bills.edit', [project.id, bill.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for certification</AppButton>
                        <ApprovalActions :approval="approval" noun="RA bill" />
                        <AppButton v-if="certified && bill.outstanding !== '0.00' && canDo('payments.record')" size="sm" :href="route('projects.payments.create', { project: project.id, party_type: 'client', party_id: bill.client?.id })">Record receipt</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete RA bill" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting certification · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>. Quantities are re-checked at final certification.
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="bill.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ bill.remarks }}</p>
            </AppCard>

            <AppCard title="Measurement" subtitle="Rates are the BOQ client rates. Previous = certified on earlier RA bills." :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-line bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">BOQ item</th>
                                <th class="px-3 py-2 text-right font-medium">BOQ</th>
                                <th class="px-3 py-2 text-right font-medium">Executed</th>
                                <th class="px-3 py-2 text-right font-medium">Previous</th>
                                <th class="px-3 py-2 text-right font-medium">This bill</th>
                                <th class="px-3 py-2 text-right font-medium">Cumulative</th>
                                <th class="px-3 py-2 text-right font-medium">Rate</th>
                                <th class="px-4 py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in items" :key="item.id">
                                <td class="min-w-56 px-4 py-2">
                                    <span v-if="item.item_code" class="font-mono text-xs text-slate-500">{{ item.item_code }} </span>{{ item.description }}
                                    <p v-if="item.is_override" class="mt-0.5 text-xs text-amber-800"><Icon name="warning" :size="12" class="mr-0.5 inline" />Override: {{ item.override_reason }}</p>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap tabular">{{ formatQty(item.boq_qty) }} {{ item.unit }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(item.executed_qty) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(item.previous_qty) }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular">{{ formatQty(item.current_qty) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(item.cumulative_qty) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatRate(item.rate) }}</td>
                                <td class="px-4 py-2 text-right font-semibold tabular">{{ formatMoney(item.current_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Bill summary">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Work value this bill</dt><dd class="tabular">{{ formatMoney(bill.gross_amount) }}</dd></div>
                        <div v-if="bill.tax_type === 'inter'" class="flex justify-between"><dt class="text-slate-500">IGST</dt><dd class="tabular">{{ formatMoney(bill.igst_amount) }}</dd></div>
                        <template v-else>
                            <div class="flex justify-between"><dt class="text-slate-500">CGST</dt><dd class="tabular">{{ formatMoney(bill.cgst_amount) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">SGST</dt><dd class="tabular">{{ formatMoney(bill.sgst_amount) }}</dd></div>
                        </template>
                        <div class="flex justify-between font-medium"><dt>Invoice total</dt><dd class="tabular">{{ formatMoney(bill.invoice_total) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Retention {{ formatPercent(bill.retention_percent) }}</dt><dd class="tabular">-{{ formatMoney(bill.retention_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Advance recovery</dt><dd class="tabular">-{{ formatMoney(bill.advance_recovery) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">TDS {{ formatPercent(bill.tds_percent) }}</dt><dd class="tabular">-{{ formatMoney(bill.tds_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Other deductions</dt><dd class="tabular">-{{ formatMoney(bill.other_deductions) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Net receivable</dt><dd class="tabular">{{ formatMoney(bill.net_payable) }}</dd></div>
                    </dl>
                </AppCard>
                <AppCard title="Receivable">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Net receivable</dt><dd class="tabular">{{ formatMoney(bill.net_payable) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Retention released</dt><dd class="tabular">+{{ formatMoney(bill.retention_released) }}</dd></div>
                        <div class="flex justify-between font-medium"><dt>Due</dt><dd class="tabular">{{ formatMoney(bill.due) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Received</dt><dd class="tabular">-{{ formatMoney(bill.received_amount) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Outstanding</dt><dd class="tabular">{{ bill.outstanding === null ? 'After certification' : formatMoney(bill.outstanding) }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-slate-500">Client billing is revenue; it never posts to the project cost.</p>
                </AppCard>
            </div>

            <AttachmentPanel :attachments="attachments" attachable-type="client_invoice" :attachable-id="bill.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Measurement book, client certificate…" />
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
