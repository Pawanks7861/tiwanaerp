<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import StatCard from '@/Components/Data/StatCard.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    versions: { type: Array, required: true },
    budget: { type: Object, default: null },
    heads: { type: Array, required: true },
    lines: { type: Array, required: true },
    materialLines: { type: Array, default: () => [] },
    pendingRevision: { type: Boolean, default: false },
    boqs: { type: Array, required: true },
    costHeads: { type: Array, required: true },
    can: { type: Object, required: true },
});

const headFilter = ref('all');
const visibleLines = computed(() => (headFilter.value === 'all' ? props.lines : props.lines.filter((l) => l.cost_head === headFilter.value)));
const headLabel = (value) => props.costHeads.find((h) => h.value === value)?.label ?? value;
const editable = computed(() => props.can.update && props.budget?.editable);

const columns = [
    { key: 'description', label: 'Line' },
    { key: 'cost_head', label: 'Cost head' },
    { key: 'amount', label: 'Amount', type: 'money' },
];

const materialColumns = [
    { key: 'material_name', label: 'Material' },
    { key: 'unit', label: 'Unit' },
    { key: 'quantity', label: 'Required qty' },
    { key: 'rate', label: 'Budget rate', type: 'money' },
    { key: 'amount', label: 'Budget amount', type: 'money' },
    { key: 'source_count', label: 'BOQ sources' },
];

const showGenerate = ref(false);
const generateForm = useForm({ boq_id: props.boqs[0]?.value ?? null });
function generate() {
    generateForm.post(route('projects.budget.generate', props.project.id), { onSuccess: () => (showGenerate.value = false) });
}

const syncing = ref(false);
function syncMaterials() {
    syncing.value = true;
    router.post(
        route('projects.budget.sync-materials', props.project.id),
        { boq_id: props.budget?.boq_id ?? props.boqs[0]?.value ?? null },
        { preserveScroll: true, onFinish: () => (syncing.value = false) },
    );
}

const lineModal = ref(null);
const lineForm = useForm({ cost_head: 'other', description: '', amount: null });
function openLine(line = null) {
    lineForm.clearErrors();
    lineForm.cost_head = line?.cost_head ?? 'other';
    lineForm.description = line?.description ?? '';
    lineForm.amount = line?.amount ?? null;
    lineModal.value = { line };
}
function saveLine() {
    const line = lineModal.value.line;
    const url = line ? route('projects.budget.lines.update', [props.project.id, line.id]) : route('projects.budget.lines.store', props.project.id);
    lineForm[line ? 'put' : 'post'](url, { preserveScroll: true, onSuccess: () => (lineModal.value = null) });
}

const sourcesModal = ref(null);

const confirming = ref(null);
const processing = ref(false);
function confirmAction() {
    processing.value = true;
    const { action, line } = confirming.value;
    const done = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    if (action === 'approve') {
        router.post(route('projects.budget.approve', [props.project.id, props.budget.id]), {}, done);
    } else if (action === 'new') {
        router.post(route('projects.budget.new-version', props.project.id), {}, done);
    } else {
        router.delete(route('projects.budget.lines.destroy', [props.project.id, line.id]), done);
    }
}
</script>

<template>
    <ProjectLayout :project="project" active="budget" title="Budget">
        <div class="space-y-4">
            <AppCard :padded="false">
                <template #header>
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-slate-900">Project budget</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Generated from the current approved BOQ. Approved budgets are never overwritten; changes create a new version.</p>
                    </div>
                </template>
                <template v-if="can.update" #actions>
                    <AppButton v-if="boqs.length" size="sm" @click="showGenerate = true">Generate from BOQ</AppButton>
                    <AppButton v-if="budget?.editable" size="sm" variant="secondary" :loading="syncing" @click="syncMaterials">Sync from BOQ</AppButton>
                    <AppButton v-if="budget && !budget.editable" size="sm" variant="secondary" @click="confirming = { action: 'new' }">New version</AppButton>
                </template>

                <div v-if="pendingRevision || budget?.materials_sync_pending" class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:px-5">
                    Budget requires revision — BOQ material changes are pending a budget update. Create a new version or sync on a draft budget.
                </div>

                <div v-if="versions.length" class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 sm:px-5">
                    <span class="text-xs text-slate-500">Versions:</span>
                    <Link
                        v-for="v in versions"
                        :key="v.id"
                        :href="route('projects.budget.index', [project.id, { version_id: v.id }])"
                        class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs"
                        :class="budget?.id === v.id ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-line text-slate-600 hover:bg-slate-50'"
                        preserve-scroll
                    >
                        v{{ v.version }} <StatusBadge :status="v.status" :label="v.status_label" />
                    </Link>
                </div>

                <EmptyState
                    v-if="!budget"
                    icon="banknotes"
                    title="No budget yet"
                    :description="boqs.length ? 'Generate the budget from the approved BOQ, or add lines manually.' : 'Approve a BOQ first; the budget is generated from the current approved BOQ.'"
                >
                    <AppButton v-if="can.update" size="sm" variant="secondary" class="mt-3" @click="openLine()">Add a manual line</AppButton>
                </EmptyState>

                <div v-else class="space-y-4 p-4 sm:p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xs text-slate-500 uppercase">Budget v{{ budget.version }} total</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular">{{ formatMoney(budget.total_amount) }}</p>
                            <p class="text-xs text-slate-500">
                                <template v-for="v in versions.filter((x) => x.id === budget.id)" :key="v.id">
                                    {{ v.source_label }}
                                    <template v-if="v.boq_label"> · {{ v.boq_label }}</template>
                                    <template v-if="v.materials_synced_at"> · materials synced {{ formatDate(v.materials_synced_at) }}</template>
                                    <template v-if="v.approved_at"> · approved {{ formatDate(v.approved_at) }}<template v-if="v.approved_by"> by {{ v.approved_by }}</template></template>
                                </template>
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <AppButton v-if="editable" size="sm" variant="secondary" icon="plus" @click="openLine()">Add line</AppButton>
                            <AppButton v-if="budget.can_approve" size="sm" @click="confirming = { action: 'approve' }">Approve budget</AppButton>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                        <button v-for="head in heads" :key="head.value" type="button" class="text-left" @click="headFilter = headFilter === head.value ? 'all' : head.value">
                            <StatCard :label="head.label" :value="formatMoney(head.amount)" :tone="headFilter === head.value ? 'orange' : 'slate'" />
                        </button>
                    </div>
                </div>

                <div v-if="budget" class="border-t border-line px-4 py-4 sm:px-5">
                    <div class="mb-3">
                        <h3 class="text-sm font-semibold text-slate-900">Materials from BOQ</h3>
                        <p class="mt-0.5 text-xs text-slate-500">Calculated from the material requirements saved against BOQ items.</p>
                    </div>
                    <DataTable
                        :columns="materialColumns"
                        :rows="materialLines"
                        empty-icon="cube"
                        empty-title="No BOQ materials"
                        empty-description="Save materials on BOQ items, then generate or sync the budget."
                    >
                        <template #cell-material_name="{ row }">
                            <div class="text-slate-900">
                                <span v-if="row.material_code" class="mr-1 text-xs text-slate-500">{{ row.material_code }}</span>
                                {{ row.material_name || row.description }}
                            </div>
                        </template>
                        <template #cell-quantity="{ value }">{{ value != null ? formatNumber(value, 4) : '—' }}</template>
                        <template #cell-rate="{ value }">{{ value != null ? formatMoney(value) : '—' }}</template>
                        <template #cell-source_count="{ row }">
                            <AppButton variant="ghost" size="sm" @click="sourcesModal = row">View sources ({{ row.source_count }})</AppButton>
                        </template>
                    </DataTable>
                </div>

                <DataTable v-if="budget" :columns="columns" :rows="visibleLines" empty-icon="banknotes" empty-title="No lines" :empty-description="headFilter !== 'all' ? 'No lines for this cost head.' : null">
                    <template #cell-description="{ row }">
                        <div class="text-slate-900">{{ row.description }}</div>
                        <div v-if="row.boq_item" class="text-xs text-slate-500">BOQ line</div>
                    </template>
                    <template #cell-cost_head="{ value }">{{ headLabel(value) }}</template>
                    <template v-if="editable" #actions="{ row }">
                        <AppButton variant="ghost" size="sm" icon="pencil" @click="openLine(row)">Edit</AppButton>
                        <AppButton variant="ghost" size="sm" icon="trash" @click="confirming = { action: 'delete', line: row }" />
                    </template>
                </DataTable>
            </AppCard>
        </div>

        <AppModal :show="showGenerate" title="Generate budget from BOQ" @close="showGenerate = false">
            <form id="generate-form" class="space-y-3" @submit.prevent="generate">
                <FormSelect v-model="generateForm.boq_id" label="Approved BOQ" required :options="boqs" :error="generateForm.errors.boq_id" />
                <p class="text-xs text-slate-500">
                    Cost-head lines are created from BOQ rates. Material requirements saved on BOQ items are aggregated into Materials from BOQ. A draft budget is rebuilt; an approved budget is kept and a new draft version is created.
                </p>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="showGenerate = false">Cancel</AppButton>
                <AppButton type="submit" form="generate-form" :loading="generateForm.processing">Generate</AppButton>
            </template>
        </AppModal>

        <AppModal :show="!!lineModal" :title="lineModal?.line ? 'Edit budget line' : 'Add budget line'" @close="lineModal = null">
            <form id="line-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveLine">
                <FormSelect v-model="lineForm.cost_head" label="Cost head" required :options="costHeads" :error="lineForm.errors.cost_head" />
                <DecimalInput v-model="lineForm.amount" label="Amount" required :decimals="2" prefix="₹" :error="lineForm.errors.amount" />
                <FormInput v-model="lineForm.description" label="Description" required maxlength="255" class="sm:col-span-2" :error="lineForm.errors.description" />
                <p v-if="budget && !budget.editable" class="text-xs text-amber-700 sm:col-span-2">The current budget is approved: saving creates a new draft version.</p>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="lineModal = null">Cancel</AppButton>
                <AppButton type="submit" form="line-form" :loading="lineForm.processing">Save</AppButton>
            </template>
        </AppModal>

        <AppModal :show="!!sourcesModal" title="BOQ material sources" max-width="2xl" @close="sourcesModal = null">
            <div v-if="sourcesModal" class="space-y-3">
                <p class="text-sm text-slate-700">
                    <span class="font-medium">{{ sourcesModal.material_name }}</span>
                    <span v-if="sourcesModal.unit" class="text-slate-500"> · {{ sourcesModal.unit }}</span>
                </p>
                <div class="overflow-x-auto rounded-md border border-line">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">BOQ</th>
                                <th class="px-3 py-2 font-medium">Item</th>
                                <th class="px-3 py-2 font-medium">BOQ qty</th>
                                <th class="px-3 py-2 font-medium">Per unit</th>
                                <th class="px-3 py-2 font-medium">Calc qty</th>
                                <th class="px-3 py-2 font-medium">Rate</th>
                                <th class="px-3 py-2 font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(src, idx) in sourcesModal.sources" :key="idx" class="border-t border-line">
                                <td class="px-3 py-2">{{ src.boq_number }} v{{ src.boq_version }}</td>
                                <td class="px-3 py-2">
                                    <div>{{ src.item_code }} {{ src.item_name }}</div>
                                    <div class="text-slate-400">{{ src.boq_line_uid }}</div>
                                </td>
                                <td class="px-3 py-2 tabular">{{ formatNumber(src.boq_quantity, 4) }}</td>
                                <td class="px-3 py-2 tabular">{{ formatNumber(src.per_unit_quantity, 4) }}</td>
                                <td class="px-3 py-2 tabular">{{ formatNumber(src.calculated_quantity, 4) }}</td>
                                <td class="px-3 py-2 tabular">{{ formatMoney(src.rate) }}</td>
                                <td class="px-3 py-2 tabular">{{ formatMoney(src.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="sourcesModal = null">Close</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :show="!!confirming"
            :title="{ approve: 'Approve this budget?', new: 'Start a new budget version?', delete: 'Delete this line?' }[confirming?.action] ?? ''"
            :message="{ approve: 'The approved budget is locked. Any previously approved version is marked superseded.', new: 'A draft copy of the approved budget is created for editing. Pending BOQ material changes are synced when present.', delete: null }[confirming?.action] ?? null"
            :confirm-label="{ approve: 'Approve', new: 'Create draft', delete: 'Delete' }[confirming?.action] ?? 'Confirm'"
            :danger="confirming?.action === 'delete'"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
    </ProjectLayout>
</template>
