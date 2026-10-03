<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
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
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    materialRequest: { type: Object, required: true },
    items: { type: Array, required: true },
    linked: { type: Object, default: null },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const mr = computed(() => props.materialRequest);
const showRemaining = computed(() => props.items.some((i) => i.remaining_qty !== null));

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'The request is locked while it is being approved.', label: 'Submit', danger: false },
    delete: { title: 'Delete this request?', message: 'This draft and its lines will be removed.', label: 'Delete', danger: true },
};
function confirmAction() {
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    if (confirming.value === 'delete') {
        router.delete(route('projects.material-requests.destroy', [props.project.id, mr.value.id]), options);
    } else {
        router.post(route('projects.material-requests.submit', [props.project.id, mr.value.id]), {}, options);
    }
}

const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
function cancelRequest() {
    cancelForm.post(route('projects.material-requests.cancel', [props.project.id, mr.value.id]), { preserveScroll: true, onSuccess: () => (showCancel.value = false) });
}

const lockedReason = computed(() => {
    if (props.can.update || props.approval) {
        return null;
    }
    return {
        approved: 'Approved. Lines are locked; procurement quantities update automatically as RFQs and purchase orders are raised.',
        partially_ordered: 'Partly ordered. Remaining quantities can still go out for quotation.',
        ordered: 'Fully ordered.',
        received: 'All ordered material has been received.',
        cancelled: mr.value.cancelled_reason ? `Cancelled: ${mr.value.cancelled_reason}` : 'Cancelled.',
    }[mr.value.status] ?? null;
});
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="mr.request_number">
        <ProcurementNav :project-id="project.id" active="material-requests" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.material-requests.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Material requests</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ mr.request_number }}</h2>
                            <StatusBadge :status="mr.status" :label="mr.status_label" />
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">{{ mr.priority_label }} priority</span>
                        </div>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Request date</dt><dd class="text-slate-800">{{ formatDate(mr.request_date) }}</dd></div>
                            <div><dt class="text-slate-500">Required by</dt><dd class="text-slate-800">{{ formatDate(mr.required_date) }}</dd></div>
                            <div><dt class="text-slate-500">Site</dt><dd class="text-slate-800">{{ mr.site ?? 'Whole project' }}</dd></div>
                            <div><dt class="text-slate-500">Requested by</dt><dd class="text-slate-800">{{ mr.requested_by ?? '—' }}</dd></div>
                        </dl>
                        <p v-if="mr.approved_at" class="mt-2 text-xs text-slate-500">Approved {{ formatDateTime(mr.approved_at) }}<template v-if="mr.approved_by"> by {{ mr.approved_by }}</template></p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.material-requests.edit', [project.id, mr.id])">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="material request" />
                        <AppButton v-if="can.create_rfq" size="sm" :href="route('projects.rfqs.create', { project: project.id, material_request: mr.id })">Create RFQ</AppButton>
                        <AppButton v-if="can.cancel" size="sm" variant="ghost" class="text-red-600" @click="(cancelForm.reset(), (showCancel = true))">Cancel request</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete request" @click="confirming = 'delete'" />
                    </div>
                </div>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>
                </div>
                <div v-else-if="lockedReason" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ lockedReason }}
                </div>
                <p v-if="mr.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ mr.remarks }}</p>
            </AppCard>

            <AppCard title="Items" :subtitle="`${items.length} line(s)`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">#</th>
                                <th class="px-4 py-2.5 text-left">Material</th>
                                <th class="px-4 py-2.5 text-left">BOQ item / task</th>
                                <th class="px-4 py-2.5 text-right">Requested</th>
                                <th class="px-4 py-2.5 text-right">Ordered</th>
                                <th class="px-4 py-2.5 text-right">Received</th>
                                <th v-if="showRemaining" class="px-4 py-2.5 text-right">Open to procure</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(item, i) in items" :key="item.id" class="align-top">
                                <td class="px-4 py-3 text-slate-400 tabular">{{ i + 1 }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ item.material?.name }}</div>
                                    <div class="text-xs text-slate-500"><span class="font-mono">{{ item.material?.code }}</span><template v-if="item.remarks"> · {{ item.remarks }}</template></div>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600">
                                    <div>{{ item.boq_item ?? '—' }}</div>
                                    <div v-if="item.task" class="text-slate-400">{{ item.task }}</div>
                                </td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.ordered_qty) }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.received_qty) }}</td>
                                <td v-if="showRemaining" class="px-4 py-3 text-right font-medium tabular">{{ formatQty(item.remaining_qty) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="item.id" class="px-4 py-3">
                        <p class="font-medium text-slate-900">{{ item.material?.name }}</p>
                        <p class="text-xs text-slate-500">{{ item.boq_item ?? 'No BOQ link' }}<template v-if="item.task"> · {{ item.task }}</template></p>
                        <dl class="mt-1.5 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
                            <dt class="text-slate-500">Requested</dt><dd class="text-right tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</dd>
                            <dt class="text-slate-500">Ordered</dt><dd class="text-right tabular">{{ formatQty(item.ordered_qty) }}</dd>
                            <dt class="text-slate-500">Received</dt><dd class="text-right tabular">{{ formatQty(item.received_qty) }}</dd>
                            <template v-if="showRemaining"><dt class="text-slate-500">Open to procure</dt><dd class="text-right font-medium tabular">{{ formatQty(item.remaining_qty) }}</dd></template>
                        </dl>
                    </li>
                </ul>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard v-if="linked" title="Linked documents" :padded="false">
                    <ul v-if="linked.rfqs.length || linked.purchase_orders.length" class="divide-y divide-line text-sm">
                        <li v-for="doc in [...linked.rfqs.map((d) => ({ ...d, kind: 'RFQ' })), ...linked.purchase_orders.map((d) => ({ ...d, kind: 'PO' }))]" :key="`${doc.kind}${doc.id}`" class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <Link :href="doc.url" class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ doc.number }}</Link>
                            <StatusBadge :status="doc.status" :label="doc.status_label" />
                        </li>
                    </ul>
                    <p v-else class="px-4 py-6 text-center text-sm text-slate-500">No RFQs or purchase orders yet.</p>
                </AppCard>
                <AttachmentPanel
                    :attachments="attachments"
                    attachable-type="material_request"
                    :attachable-id="mr.id"
                    :can-upload="can.attach"
                    :can-delete="can.attach"
                    placeholder="Drawing, site photo, specification…"
                />
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

        <AppModal :show="showCancel" title="Cancel material request" @close="showCancel = false">
            <p class="mb-3 text-sm text-slate-600">Only requests with nothing in an open RFQ or purchase order can be cancelled.</p>
            <FormInput v-model="cancelForm.reason" label="Reason" required multiline :rows="3" maxlength="500" :error="cancelForm.errors.reason || cancelForm.errors.material_request" />
            <template #footer>
                <AppButton variant="secondary" @click="showCancel = false">Back</AppButton>
                <AppButton variant="danger" :loading="cancelForm.processing" @click="cancelRequest">Cancel request</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
