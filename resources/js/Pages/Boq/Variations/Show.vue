<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    variation: { type: Object, required: true },
    preview: { type: Object, default: null },
    contract: { type: Object, default: null },
    can: { type: Object, required: true },
});

const showReject = ref(false);
const rejectForm = useForm({ rejection_reason: '' });

function submit() {
    router.post(route('projects.variations.submit', [props.project.id, props.variation.id]));
}
function approve() {
    router.post(route('projects.variations.approve', [props.project.id, props.variation.id]));
}
function reject() {
    rejectForm.post(route('projects.variations.reject', [props.project.id, props.variation.id]));
}
function destroy() {
    if (!confirm('Delete this draft variation?')) return;
    router.delete(route('projects.variations.destroy', [props.project.id, props.variation.id]));
}

function statusClass(status) {
    if (status === 'approved') return 'bg-emerald-100 text-emerald-800';
    if (status === 'submitted') return 'bg-sky-100 text-sky-800';
    if (status === 'rejected') return 'bg-rose-100 text-rose-800';
    return 'bg-slate-100 text-slate-700';
}
</script>

<template>
    <ProjectLayout :project="project" title="Variation" active="variations">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link :href="route('projects.variations.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">
                    ← Variation Register
                </Link>
                <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ variation.variation_number }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ variation.title }}</p>
                <span class="mt-2 inline-flex rounded px-1.5 py-0.5 text-[11px] font-medium" :class="statusClass(variation.status)">
                    {{ variation.status_label }}
                </span>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="can.manage"
                    :href="route('projects.variations.edit', [project.id, variation.id])"
                    class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Edit
                </Link>
                <AppButton v-if="can.submit" type="button" @click="submit">Submit</AppButton>
                <AppButton v-if="can.approve" type="button" @click="approve">Approve</AppButton>
                <AppButton v-if="can.reject" type="button" variant="secondary" @click="showReject = !showReject">Reject</AppButton>
                <AppButton v-if="can.manage" type="button" variant="danger" @click="destroy">Delete</AppButton>
            </div>
        </div>

        <AppCard v-if="showReject" class="mb-4" title="Reject variation">
            <FormInput v-model="rejectForm.rejection_reason" label="Rejection reason" multiline />
            <div class="mt-3">
                <AppButton type="button" :loading="rejectForm.processing" @click="reject">Confirm reject</AppButton>
            </div>
        </AppCard>

        <div v-if="preview" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <AppCard>
                <p class="text-xs text-slate-500">Current effective contract</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ preview.current_contract_value }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">This variation revenue</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ preview.variation_revenue_impact }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">After approval</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ preview.effective_contract_value_after }}</p>
            </AppCard>
            <AppCard v-if="can.view_costs && preview.variation_cost_impact != null">
                <p class="text-xs text-slate-500">Cost impact</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ preview.variation_cost_impact }}</p>
            </AppCard>
        </div>

        <div class="mb-4 grid gap-3 lg:grid-cols-2">
            <AppCard title="Header">
                <dl class="grid grid-cols-2 gap-2 text-xs">
                    <dt class="text-slate-500">Type</dt><dd>{{ variation.change_order_type }}</dd>
                    <dt class="text-slate-500">Source</dt><dd>{{ variation.source_type }}</dd>
                    <dt class="text-slate-500">Reason</dt><dd class="col-span-2">{{ variation.reason }}</dd>
                    <dt class="text-slate-500">Client ref</dt><dd>{{ variation.client_instruction_reference || '—' }}</dd>
                    <dt class="text-slate-500">Drawing</dt><dd>{{ variation.drawing?.label || '—' }}</dd>
                    <dt class="text-slate-500">BOQ</dt><dd>{{ variation.boq?.boq_number }} v{{ variation.boq?.version }}</dd>
                    <dt class="text-slate-500">Submitted</dt><dd>{{ variation.submitted_by || '—' }} {{ variation.submitted_at || '' }}</dd>
                    <dt class="text-slate-500">Approved</dt><dd>{{ variation.approved_by || '—' }} {{ variation.approved_at || '' }}</dd>
                    <dt v-if="variation.rejection_reason" class="text-slate-500">Rejection</dt>
                    <dd v-if="variation.rejection_reason" class="col-span-2 text-rose-700">{{ variation.rejection_reason }}</dd>
                </dl>
            </AppCard>
            <AppCard v-if="can.view_costs" title="Commercial impact">
                <dl class="grid grid-cols-2 gap-2 text-xs">
                    <dt class="text-slate-500">Revenue</dt><dd class="tabular font-semibold">{{ variation.revenue_impact }}</dd>
                    <dt class="text-slate-500">Cost</dt><dd class="tabular">{{ variation.cost_impact }}</dd>
                    <dt class="text-slate-500">Margin</dt><dd class="tabular">{{ variation.margin_impact }}</dd>
                </dl>
                <p class="mt-3 text-[11px] text-slate-400">Approval alone does not post stock, cost ledger, payments, or Tally.</p>
            </AppCard>
        </div>

        <AppCard title="Items">
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-[1000px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">Item</th>
                            <th class="px-2 py-2">Change</th>
                            <th class="px-2 py-2 text-right">Original Qty</th>
                            <th class="px-2 py-2 text-right">Change</th>
                            <th class="px-2 py-2 text-right">Revised</th>
                            <th class="px-2 py-2 text-right">Sell rate</th>
                            <th v-if="can.view_costs" class="px-2 py-2 text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in variation.items" :key="item.id" class="border-t border-line">
                            <td class="px-2 py-2">
                                <div class="font-medium">{{ item.item_code ? `${item.item_code} — ` : '' }}{{ item.name }}</div>
                                <div class="text-[11px] text-slate-400">{{ item.unit }}</div>
                            </td>
                            <td class="px-2 py-2">{{ item.change_type_label }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(item.original_quantity) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(item.quantity_change) }}</td>
                            <td class="px-2 py-2 text-right font-semibold tabular">{{ formatQty(item.revised_quantity) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ item.proposed_selling_rate }}</td>
                            <td v-if="can.view_costs" class="px-2 py-2 text-right tabular">{{ item.revenue_impact }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
