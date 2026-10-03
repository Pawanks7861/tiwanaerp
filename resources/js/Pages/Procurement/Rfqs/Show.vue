<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatQty } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    rfq: { type: Object, required: true },
    items: { type: Array, required: true },
    vendors: { type: Array, required: true },
    vendorOptions: { type: Array, required: true },
    comparison: { type: Object, default: null },
    purchaseOrder: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    send: { title: 'Mark RFQ as sent?', message: 'Items are locked once the RFQ is sent. Quotations can then be recorded for each invited vendor.', label: 'Mark as sent', danger: false, method: 'post', route: 'projects.rfqs.send' },
    close: { title: 'Close this RFQ?', message: 'No more quotations can be recorded. Quantities that were not ordered become available again on the material requests.', label: 'Close RFQ', danger: false, method: 'post', route: 'projects.rfqs.close' },
    delete: { title: 'Delete this RFQ?', message: 'This draft RFQ will be removed.', label: 'Delete', danger: true, method: 'delete', route: 'projects.rfqs.destroy' },
    createPo: { title: 'Create purchase order?', message: 'A draft purchase order is created for the approved vendor with the quoted rates. GST is calculated from the vendor state and the place of supply.', label: 'Create PO', danger: false, method: 'post', route: 'projects.rfqs.purchase-order' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const url = route(action.route, [props.project.id, props.rfq.id]);
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
function cancelRfq() {
    cancelForm.post(route('projects.rfqs.cancel', [props.project.id, props.rfq.id]), { preserveScroll: true, onSuccess: () => (showCancel.value = false) });
}

// Vendor management (draft / sent RFQs)
const showVendors = ref(false);
const vendorForm = useForm({ vendor_ids: [] });
const vendorToAdd = ref(null);
const vendorLabel = (id) => props.vendorOptions.find((v) => v.value === id)?.label ?? props.vendors.find((v) => v.vendor?.id === id)?.vendor?.name ?? `#${id}`;
const quotedVendorIds = computed(() => props.vendors.filter((v) => v.quotation).map((v) => v.vendor.id));
function openVendors() {
    vendorForm.clearErrors();
    vendorForm.vendor_ids = props.vendors.map((v) => v.vendor.id);
    showVendors.value = true;
}
function addVendor(id) {
    if (id && !vendorForm.vendor_ids.includes(id)) {
        vendorForm.vendor_ids.push(id);
    }
    vendorToAdd.value = null;
}
function saveVendors() {
    vendorForm.put(route('projects.rfqs.vendors', [props.project.id, props.rfq.id]), { preserveScroll: true, onSuccess: () => (showVendors.value = false) });
}
const vendorFormErrors = computed(() => Object.entries(vendorForm.errors).map(([, v]) => v));

const quotedCount = computed(() => props.vendors.filter((v) => v.quotation).length);
const lowestTotal = computed(() => {
    const totals = props.vendors.filter((v) => v.quotation).map((v) => v.quotation.grand_total);

    return totals.length > 1 ? totals.reduce((a, b) => (new Decimal(a).lte(new Decimal(b)) ? a : b)) : null;
});

const statusNote = computed(() => ({
    draft: 'Draft. Add items and vendors, then mark the RFQ as sent.',
    sent: 'Sent to vendors. Record each vendor’s quotation as it arrives.',
    quotes_received: 'Quotations received. Compare bids to select a vendor.',
    evaluated: 'Bid comparison submitted or approved.',
    closed: 'Closed.',
    cancelled: props.rfq.cancelled_reason ? `Cancelled: ${props.rfq.cancelled_reason}` : 'Cancelled.',
})[props.rfq.status]);
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="rfq.rfq_number">
        <ProcurementNav :project-id="project.id" active="rfqs" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.rfqs.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">RFQs</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ rfq.rfq_number }}</h2>
                            <StatusBadge :status="rfq.status" :label="rfq.status_label" />
                        </div>
                        <p v-if="rfq.title" class="text-sm text-slate-700">{{ rfq.title }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">RFQ date</dt><dd>{{ formatDate(rfq.rfq_date) }}</dd></div>
                            <div><dt class="text-slate-500">Quotes due</dt><dd>{{ formatDate(rfq.due_date) }}</dd></div>
                            <div><dt class="text-slate-500">Required by</dt><dd>{{ formatDate(rfq.required_date) }}</dd></div>
                            <div v-if="rfq.sent_at"><dt class="text-slate-500">Sent</dt><dd>{{ formatDateTime(rfq.sent_at) }}</dd></div>
                        </dl>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.rfqs.edit', [project.id, rfq.id])">Edit</AppButton>
                        <AppButton v-if="can.send" size="sm" @click="confirming = 'send'">Mark as sent</AppButton>
                        <AppButton v-if="can.compare" size="sm" :variant="comparison ? 'secondary' : 'primary'" :href="route('projects.rfqs.comparison', [project.id, rfq.id])">
                            {{ comparison ? 'Bid comparison' : 'Compare bids' }}
                        </AppButton>
                        <AppButton v-if="can.create_po" size="sm" @click="confirming = 'createPo'">Create purchase order</AppButton>
                        <AppButton v-if="purchaseOrder" size="sm" variant="secondary" :href="purchaseOrder.url">{{ purchaseOrder.po_number }}</AppButton>
                        <AppButton v-if="can.close" size="sm" variant="secondary" @click="confirming = 'close'">Close</AppButton>
                        <AppButton v-if="can.cancel" size="sm" variant="ghost" class="text-red-600" @click="(cancelForm.reset(), (showCancel = true))">Cancel RFQ</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete RFQ" @click="confirming = 'delete'" />
                    </div>
                </div>
                <div v-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                    <template v-if="comparison"> · Comparison: <StatusBadge :status="comparison.status" :label="comparison.status_label" /></template>
                </div>
                <p v-if="rfq.terms" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ rfq.terms }}</p>
            </AppCard>

            <AppCard title="Vendors & quotations" :subtitle="`${quotedCount} of ${vendors.length} vendor(s) quoted`" :padded="false">
                <template v-if="can.manage_vendors" #actions>
                    <AppButton size="sm" variant="secondary" icon="users" @click="openVendors">Manage vendors</AppButton>
                </template>
                <EmptyState v-if="!vendors.length" icon="truck" title="No vendors invited" description="Invite at least one vendor before sending the RFQ." />
                <ul v-else class="divide-y divide-line">
                    <li v-for="v in vendors" :key="v.id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-slate-900">{{ v.vendor?.name }}</span>
                                <StatusBadge :status="v.status" :label="v.status_label" />
                                <span v-if="v.quotation?.is_selected" class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 uppercase">Selected</span>
                                <span v-if="v.vendor && !v.vendor.is_active" class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 uppercase">Inactive</span>
                            </div>
                            <p class="text-xs text-slate-500">
                                <span class="font-mono">{{ v.vendor?.code }}</span>
                                <template v-if="v.vendor?.city"> · {{ v.vendor.city }}</template>
                                · {{ v.vendor?.state_code ? `State ${v.vendor.state_code}` : 'No GST state' }}
                                <template v-if="v.vendor?.gstin"> · {{ v.vendor.gstin }}</template>
                            </p>
                        </div>
                        <div class="flex items-center gap-3 sm:justify-end">
                            <div v-if="v.quotation" class="text-right">
                                <p class="font-semibold tabular" :class="lowestTotal === v.quotation.grand_total ? 'text-emerald-700' : 'text-slate-900'">{{ formatMoney(v.quotation.grand_total) }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ v.quotation.quotation_number || 'Quotation' }} · {{ formatDate(v.quotation.quotation_date) }}
                                    <template v-if="v.quotation.delivery_days !== null"> · {{ v.quotation.delivery_days }} days</template>
                                </p>
                            </div>
                            <AppButton
                                v-if="v.quotation && can.edit_quote"
                                size="sm"
                                variant="secondary"
                                :href="route('projects.rfqs.quotations.edit', [project.id, rfq.id, v.quotation.id])"
                            >Edit quote</AppButton>
                            <AppButton
                                v-else-if="!v.quotation && can.quote"
                                size="sm"
                                :href="route('projects.rfqs.quotations.create', { project: project.id, rfq: rfq.id, vendor: v.vendor.id })"
                            >Record quote</AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppCard title="Items" :subtitle="`${items.length} line(s)`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">#</th>
                                <th class="px-4 py-2.5 text-left">Material</th>
                                <th class="px-4 py-2.5 text-left">Material request</th>
                                <th class="px-4 py-2.5 text-right">Quantity</th>
                                <th class="px-4 py-2.5 text-left">Required by</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(item, i) in items" :key="item.id" class="align-top">
                                <td class="px-4 py-3 text-slate-400 tabular">{{ i + 1 }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ item.material?.name }}</div>
                                    <div class="text-xs text-slate-500"><span class="font-mono">{{ item.material?.code }}</span><template v-if="item.specification"> · {{ item.specification }}</template></div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">{{ item.material_request ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</td>
                                <td class="px-4 py-3 text-xs">{{ formatDate(item.required_date) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="item.id" class="px-4 py-3">
                        <div class="flex justify-between gap-3">
                            <p class="font-medium text-slate-900">{{ item.material?.name }}</p>
                            <p class="shrink-0 text-sm tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</p>
                        </div>
                        <p class="text-xs text-slate-500"><span class="font-mono">{{ item.material_request }}</span><template v-if="item.specification"> · {{ item.specification }}</template></p>
                    </li>
                </ul>
            </AppCard>

            <AttachmentPanel
                :attachments="attachments"
                attachable-type="rfq"
                :attachable-id="rfq.id"
                :can-upload="can.attach"
                :can-delete="can.attach"
                placeholder="RFQ letter, drawings, specification…"
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

        <AppModal :show="showCancel" title="Cancel RFQ" @close="showCancel = false">
            <FormInput v-model="cancelForm.reason" label="Reason" required multiline :rows="3" maxlength="500" :error="cancelForm.errors.reason || cancelForm.errors.rfq" />
            <template #footer>
                <AppButton variant="secondary" @click="showCancel = false">Back</AppButton>
                <AppButton variant="danger" :loading="cancelForm.processing" @click="cancelRfq">Cancel RFQ</AppButton>
            </template>
        </AppModal>

        <AppModal :show="showVendors" title="Invited vendors" @close="showVendors = false">
            <div class="space-y-3">
                <SearchSelect
                    :model-value="vendorToAdd"
                    label="Add vendor"
                    placeholder="Search vendors…"
                    :options="vendorOptions.filter((o) => !vendorForm.vendor_ids.includes(o.value))"
                    @update:model-value="addVendor"
                />
                <p v-for="(e, i) in vendorFormErrors" :key="i" class="text-sm text-red-600">{{ e }}</p>
                <ul class="divide-y divide-line rounded-lg border border-line">
                    <li v-for="id in vendorForm.vendor_ids" :key="id" class="flex items-center justify-between px-3 py-2 text-sm">
                        <span>{{ vendorLabel(id) }}</span>
                        <span v-if="quotedVendorIds.includes(id)" class="text-xs text-slate-500">Quoted</span>
                        <button v-else type="button" class="rounded p-1 text-slate-400 hover:text-red-600" :aria-label="`Remove ${vendorLabel(id)}`" @click="vendorForm.vendor_ids.splice(vendorForm.vendor_ids.indexOf(id), 1)">
                            <Icon name="close" :size="14" />
                        </button>
                    </li>
                    <li v-if="!vendorForm.vendor_ids.length" class="px-3 py-4 text-center text-sm text-slate-500">No vendors.</li>
                </ul>
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="showVendors = false">Cancel</AppButton>
                <AppButton :loading="vendorForm.processing" @click="saveVendors">Save vendors</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
