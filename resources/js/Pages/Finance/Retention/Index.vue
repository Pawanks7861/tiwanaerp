<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { router, useForm, usePage } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    bills: { type: Array, required: true },
    releases: { type: Array, required: true },
    can: { type: Object, required: true },
    today: { type: String, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['release', 'approval', 'releasable_id'].map((k) => page.props.errors?.[k]).find(Boolean));

const target = ref(null);
const editingId = ref(null);
const form = useForm({ releasable_type: null, releasable_id: null, release_date: props.today, amount: null, remarks: '' });
function openRelease(bill) {
    form.reset();
    form.clearErrors();
    editingId.value = null;
    target.value = bill;
    form.releasable_type = bill.type;
    form.releasable_id = bill.id;
    form.amount = bill.releasable;
}
function openEdit(release) {
    form.reset();
    form.clearErrors();
    editingId.value = release.id;
    target.value = { number: release.bill, releasable: null };
    form.release_date = release.release_date;
    form.amount = release.amount;
    form.remarks = release.remarks ?? '';
}
function save() {
    const options = { preserveScroll: true, onSuccess: () => (target.value = null) };
    const transform = (d) => ({ ...d, remarks: d.remarks || null });
    editingId.value
        ? form.transform(({ release_date, amount, remarks }) => transform({ release_date, amount, remarks })).put(route('projects.retention.update', [props.project.id, editingId.value]), options)
        : form.transform(transform).post(route('projects.retention.store', props.project.id), options);
}

const confirming = ref(null);
const processing = ref(false);
function confirmAction() {
    const [action, id] = confirming.value;
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    action === 'delete'
        ? router.delete(route('projects.retention.destroy', [props.project.id, id]), options)
        : router.post(route('projects.retention.submit', [props.project.id, id]), {}, options);
}
const hasBalance = (bill) => new Decimal(bill.releasable || 0).gt(0);
</script>

<template>
    <ProjectLayout :project="project" active="finance" title="Retention">
        <FinanceNav :project-id="project.id" active="retention" />
        <div class="space-y-4">
            <p v-if="errorMessage" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ errorMessage }}</p>
            <AppCard title="Retention held" subtitle="Retention deducted on certified bills. An approved release adds the amount back to the bill's due; the cash moves when a receipt / payment is allocated to it." :padded="false">
                <div v-if="bills.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-line bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">Bill</th>
                                <th class="px-3 py-2 text-right font-medium">Held</th>
                                <th class="px-3 py-2 text-right font-medium">Released</th>
                                <th class="px-3 py-2 text-right font-medium">Balance</th>
                                <th class="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="bill in bills" :key="`${bill.type}-${bill.id}`">
                                <td class="px-4 py-2">
                                    <p class="font-mono text-xs font-medium text-slate-900">{{ bill.number }}</p>
                                    <p class="text-xs text-slate-500">{{ bill.type_label }} · {{ bill.party ?? '—' }}</p>
                                </td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(bill.held) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(bill.released) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular">{{ formatMoney(bill.balance) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <AppButton v-if="can.create && hasBalance(bill)" size="sm" variant="secondary" @click="openRelease(bill)">Release</AppButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else icon="scale" title="No retention held" description="Retention appears here once a bill with retention is certified." />
            </AppCard>

            <AppCard title="Releases" :padded="false">
                <ul v-if="releases.length" class="divide-y divide-line">
                    <li v-for="r in releases" :key="r.id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-medium text-slate-900">{{ r.release_number }}</span>
                                <StatusBadge :status="r.status" :label="r.status_label" />
                            </div>
                            <p class="text-xs text-slate-500">{{ formatDate(r.release_date) }} · {{ r.bill }} · by {{ r.created_by ?? '—' }}<template v-if="r.approved_by"> · approved by {{ r.approved_by }}</template></p>
                            <p v-if="r.remarks" class="text-xs text-slate-600">{{ r.remarks }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold tabular">{{ formatMoney(r.amount) }}</span>
                            <AppButton v-if="r.can.update" size="sm" variant="ghost" icon="pencil" aria-label="Edit release" @click="openEdit(r)" />
                            <AppButton v-if="r.can.submit" size="sm" @click="confirming = ['submit', r.id]">Submit</AppButton>
                            <ApprovalActions :approval="r.approval" noun="release" />
                            <AppButton v-if="r.can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete release" @click="confirming = ['delete', r.id]" />
                        </div>
                    </li>
                </ul>
                <p v-else class="px-4 py-3 text-sm text-slate-500">No retention releases yet.</p>
            </AppCard>
        </div>

        <AppModal :show="!!target" :title="editingId ? 'Edit retention release' : `Release retention on ${target?.number ?? ''}`" @close="target = null">
            <p v-if="target?.releasable" class="mb-3 text-sm text-slate-600">Up to {{ formatMoney(target.releasable) }} can be released (net of other pending releases).</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <DecimalInput v-model="form.amount" label="Amount" required prefix="₹" :error="form.errors.amount" />
                <FormInput v-model="form.release_date" type="date" label="Release date" required :max="today" :error="form.errors.release_date" />
                <FormInput v-model="form.remarks" label="Remarks" maxlength="1000" class="sm:col-span-2" :error="form.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="target = null">Cancel</AppButton>
                <AppButton :loading="form.processing" @click="save">Save draft</AppButton>
            </template>
        </AppModal>
        <ConfirmDialog
            :show="!!confirming"
            :title="confirming?.[0] === 'delete' ? 'Delete this release?' : 'Submit for approval?'"
            :message="confirming?.[0] === 'delete' ? 'The draft release will be removed.' : 'Once approved, the released amount becomes due on the bill.'"
            :confirm-label="confirming?.[0] === 'delete' ? 'Delete' : 'Submit'"
            :danger="confirming?.[0] === 'delete'"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
    </ProjectLayout>
</template>
