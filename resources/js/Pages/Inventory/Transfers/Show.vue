<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    transfer: { type: Object, required: true },
    items: { type: Array, required: true },
    receipts: { type: Array, required: true },
    attachments: { type: Array, required: true },
    receipt_key: { type: String, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const confirming = ref(null);
const processing = ref(false);
const reasonFor = ref(null);
const ACTIONS = {
    dispatch: { title: 'Dispatch this transfer?', message: 'The quantities leave the source store now, at its weighted average cost, and stay in transit until received.', label: 'Dispatch', danger: false },
    delete: { title: 'Delete this transfer?', message: 'This draft will be removed.', label: 'Delete', danger: true },
};
const REASONS = {
    cancel: { title: 'Cancel transfer', message: 'Nothing has been received yet; all goods go back into the source store.', label: 'Cancel transfer', route: 'projects.stock-transfers.cancel' },
    close: { title: 'Close short', message: 'The quantity still in transit goes back into the source store and the transfer is closed.', label: 'Close short', route: 'projects.stock-transfers.close-short' },
};
function confirmAction() {
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    confirming.value === 'delete'
        ? router.delete(route('projects.stock-transfers.destroy', [props.project.id, props.transfer.id]), options)
        : router.post(route('projects.stock-transfers.dispatch', [props.project.id, props.transfer.id]), {}, options);
}

const receiving = ref(false);
const receipt = useForm({ idempotency_key: props.receipt_key, receipt_date: props.today, remarks: '', items: [] });
function openReceipt() {
    receipt.reset();
    receipt.clearErrors();
    receipt.idempotency_key = props.receipt_key;
    receipt.items = props.items.filter((i) => Number(i.in_transit_qty) > 0).map((i) => ({ stock_transfer_item_id: i.id, quantity: i.in_transit_qty }));
    receiving.value = true;
}
const itemOf = (id) => props.items.find((i) => i.id === id);
function submitReceipt() {
    receipt
        .transform((d) => ({ ...d, remarks: d.remarks || null, items: d.items.map((l) => ({ ...l, quantity: l.quantity || '0' })) }))
        .post(route('projects.stock-transfers.receive', [props.project.id, props.transfer.id]), { preserveScroll: true, onSuccess: () => (receiving.value = false) });
}

const statusNote = computed(
    () =>
        ({
            dispatched: 'In transit: the goods have left the source store and are not yet in the destination.',
            partially_received: 'Partly received. Record further receipts, or close short if the rest will not arrive.',
            received: 'Fully received.',
            closed_short: `Closed short${props.transfer.close_reason ? `: ${props.transfer.close_reason}` : ''}. The undelivered quantity went back to the source store.`,
            cancelled: `Cancelled${props.transfer.close_reason ? `: ${props.transfer.close_reason}` : ''}. All goods went back to the source store.`,
        })[props.transfer.status] ?? null,
);
</script>

<template>
    <ProjectLayout :project="project" active="inventory" :title="transfer.transfer_number">
        <InventoryNav :project-id="project.id" active="transfers" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.stock-transfers.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Stock transfers</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ transfer.transfer_number }}</h2>
                            <StatusBadge :status="transfer.status" :label="transfer.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ transfer.from?.name }} → {{ transfer.to?.name }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Transfer date</dt><dd>{{ formatDate(transfer.transfer_date) }}</dd></div>
                            <div v-if="transfer.vehicle_no"><dt class="text-slate-500">Vehicle</dt><dd>{{ transfer.vehicle_no }}</dd></div>
                            <div v-if="transfer.dispatched_at"><dt class="text-slate-500">Dispatched</dt><dd>{{ formatDateTime(transfer.dispatched_at) }} · {{ transfer.dispatched_by }}</dd></div>
                            <div v-if="transfer.completed_at"><dt class="text-slate-500">Completed</dt><dd>{{ formatDateTime(transfer.completed_at) }}</dd></div>
                        </dl>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.stock-transfers.edit', [project.id, transfer.id])">Edit</AppButton>
                        <AppButton v-if="can.dispatch" size="sm" @click="confirming = 'dispatch'">Dispatch</AppButton>
                        <AppButton v-if="can.receive" size="sm" @click="openReceipt">Record receipt</AppButton>
                        <AppButton v-if="can.close_short" size="sm" variant="secondary" @click="reasonFor = 'close'">Close short</AppButton>
                        <AppButton v-if="can.cancel" size="sm" variant="danger" @click="reasonFor = 'cancel'">Cancel</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete transfer" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="page.props.errors?.items && !receiving" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ page.props.errors.items }}</p>
                <div v-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <p v-if="transfer.remarks" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ transfer.remarks }}</p>
            </AppCard>

            <AppCard title="Lines" :subtitle="`${items.length} line(s)`" :padded="false">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Item</th>
                                <th class="px-4 py-2.5 text-right">Dispatched</th>
                                <th class="px-4 py-2.5 text-right">Received</th>
                                <th class="px-4 py-2.5 text-right">In transit</th>
                                <th class="px-4 py-2.5 text-right">Closed short</th>
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
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</td>
                                <td class="px-4 py-3 text-right tabular">{{ formatQty(item.received_qty) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular" :class="Number(item.in_transit_qty) > 0 ? 'text-amber-700' : 'text-slate-400'">{{ item.in_transit_qty === null ? '—' : formatQty(item.in_transit_qty) }}</td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular">{{ Number(item.short_closed_qty) ? formatQty(item.short_closed_qty) : '—' }}</td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ item.unit_cost ? formatRate(item.unit_cost) : '—' }}</td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ item.value ? formatMoney(item.value) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="`m-${item.id}`" class="px-4 py-3">
                        <p class="font-medium text-slate-900">{{ item.material?.name }}</p>
                        <dl class="mt-1 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
                            <dt class="text-slate-500">Dispatched</dt><dd class="text-right tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</dd>
                            <dt class="text-slate-500">Received</dt><dd class="text-right tabular">{{ formatQty(item.received_qty) }}</dd>
                            <dt class="text-slate-500">In transit</dt><dd class="text-right font-semibold tabular">{{ item.in_transit_qty === null ? '—' : formatQty(item.in_transit_qty) }}</dd>
                            <template v-if="can.view_valuation && item.value"><dt class="text-slate-500">Value</dt><dd class="text-right tabular">{{ formatMoney(item.value) }}</dd></template>
                        </dl>
                    </li>
                </ul>
            </AppCard>

            <AppCard v-if="receipts.length" title="Receipts" :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="r in receipts" :key="r.id" class="px-4 py-3 sm:px-5">
                        <p class="text-sm font-medium text-slate-900">{{ formatDate(r.receipt_date) }} <span class="font-normal text-slate-500">· received by {{ r.received_by ?? '—' }}</span></p>
                        <p class="text-xs text-slate-600">
                            <span v-for="(ri, n) in r.items" :key="n">{{ n ? ' · ' : '' }}{{ ri.material }} {{ formatQty(ri.quantity) }}<template v-if="can.view_valuation"> ({{ formatMoney(ri.value) }})</template></span>
                        </p>
                        <p v-if="r.remarks" class="text-xs text-slate-500">{{ r.remarks }}</p>
                    </li>
                </ul>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="stock_transfer" :attachable-id="transfer.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Delivery challan, gate pass…" />
        </div>

        <AppModal :show="receiving" title="Record receipt" max-width="2xl" @close="receiving = false">
            <div class="space-y-3">
                <FormInput v-model="receipt.receipt_date" type="date" label="Receipt date" required :min="transfer.transfer_date" :max="today" :error="receipt.errors.receipt_date" />
                <p v-if="receipt.errors.items" class="text-sm text-red-700">{{ receipt.errors.items }}</p>
                <div v-for="(line, index) in receipt.items" :key="line.stock_transfer_item_id" class="grid grid-cols-2 items-end gap-3 rounded-lg border border-line p-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ itemOf(line.stock_transfer_item_id)?.material?.name }}</p>
                        <p class="text-xs text-slate-500">In transit {{ formatQty(itemOf(line.stock_transfer_item_id)?.in_transit_qty) }} {{ itemOf(line.stock_transfer_item_id)?.unit }}</p>
                    </div>
                    <DecimalInput v-model="line.quantity" label="Received now" :decimals="4" :suffix="itemOf(line.stock_transfer_item_id)?.unit" :error="receipt.errors[`items.${index}.quantity`]" />
                </div>
                <FormInput v-model="receipt.remarks" label="Remarks" maxlength="500" :error="receipt.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="receiving = false">Back</AppButton>
                <AppButton :loading="receipt.processing" @click="submitReceipt">Record receipt</AppButton>
            </template>
        </AppModal>

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
            :url="reasonFor ? route(REASONS[reasonFor].route, [project.id, transfer.id]) : null"
            :title="reasonFor ? REASONS[reasonFor].title : ''"
            :message="reasonFor ? REASONS[reasonFor].message : null"
            :confirm-label="reasonFor ? REASONS[reasonFor].label : ''"
            @close="reasonFor = null"
        />
    </ProjectLayout>
</template>
