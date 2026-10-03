<script setup>
import CellDecimal from '@/Components/Boq/CellDecimal.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FileUpload from '@/Components/Form/FileUpload.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { boqLine, sum } from '@/lib/boqCalc';
import { formatDateTime, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    sections: { type: Array, required: true },
    items: { type: Array, required: true },
    units: { type: Array, required: true },
    analyses: { type: Array, required: true },
    versions: { type: Array, required: true },
    approval: { type: Object, default: null },
    can: { type: Object, required: true },
});

const COST_FIELDS = ['material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate', 'margin_percent'];
const editable = computed(() => props.can.update);
const costs = computed(() => props.can.view_costs);

// ---- Rows (local editable copy; the server recalculates everything on save) -------------------
let tempId = 0;
function toRow(item) {
    const row = {
        _key: item.id ? `i${item.id}` : `n${++tempId}`,
        id: item.id ?? null,
        boq_section_id: item.boq_section_id,
        item_code: item.item_code ?? '',
        name: item.name ?? '',
        description: item.description ?? '',
        hsn_sac: item.hsn_sac ?? '',
        unit_id: item.unit_id ?? null,
        quantity: item.quantity ?? null,
        client_rate: item.client_rate ?? null,
        rate_analysis_id: item.rate_analysis_id ?? null,
    };
    if (costs.value) {
        COST_FIELDS.forEach((f) => (row[f] = item[f] ?? null));
        // Client rate equal to the margin-based selling rate is shown as "auto".
        if (item.id && item.client_rate === item.selling_rate) {
            row.client_rate = null;
        }
    }

    return row;
}

const rows = ref([]);
const deletedIds = ref([]);
const baseline = ref('');
const errors = ref({});
const payloadKeys = ref([]);
const serialise = () => JSON.stringify(rows.value);

function reset() {
    rows.value = props.items.map(toRow);
    deletedIds.value = [];
    errors.value = {};
    baseline.value = serialise();
}
// Section edits reload the page props too; only reset local edits when the lines really changed.
watch(() => JSON.stringify(props.items), reset, { immediate: true });

const dirty = computed(() => deletedIds.value.length > 0 || serialise() !== baseline.value);
const calc = computed(() => Object.fromEntries(rows.value.map((r) => [r._key, boqLine(r)])));
const totals = computed(() => ({
    cost: sum(rows.value.map((r) => calc.value[r._key].cost_amount)),
    client: sum(rows.value.map((r) => calc.value[r._key].client_amount)),
}));

// ---- Sections ----------------------------------------------------------------------------------
const bySort = (a, b) => a.sort_order - b.sort_order || a.id - b.id;
const topSections = computed(() => props.sections.filter((s) => !s.parent_id).sort(bySort));
const childrenOf = (id) => props.sections.filter((s) => s.parent_id === id).sort(bySort);
const rowsIn = (sectionId) => rows.value.filter((r) => r.boq_section_id === sectionId);
const sectionLabel = (s) => [s.code, s.name].filter(Boolean).join(' ');
const sectionOptions = computed(() =>
    topSections.value.flatMap((top) => [
        { value: top.id, label: sectionLabel(top) },
        ...childrenOf(top.id).map((c) => ({ value: c.id, label: `${sectionLabel(top)} / ${sectionLabel(c)}` })),
    ]),
);
const orderedSectionIds = computed(() => sectionOptions.value.map((o) => o.value));
const sectionTotal = (sectionId, field) => {
    const ids = [sectionId, ...childrenOf(sectionId).map((c) => c.id)];

    return sum(rows.value.filter((r) => ids.includes(r.boq_section_id)).map((r) => calc.value[r._key][field]));
};
const unitLabel = (id) => props.units.find((u) => u.value === id)?.label ?? '—';
const unitOptions = computed(() => props.units.map((u) => ({ value: u.value, label: u.label, description: u.name })));

// ---- Row operations ----------------------------------------------------------------------------
const editingRow = ref(null);
const isDesktop = ref(true);
const updateViewport = () => (isDesktop.value = window.matchMedia('(min-width: 768px)').matches);

function addRow(sectionId) {
    const row = toRow({ boq_section_id: sectionId, quantity: null, unit_id: null });
    rows.value.push(row);
    if (!isDesktop.value) {
        editingRow.value = row;
    }
}

function removeRow(row) {
    if (row.id) {
        deletedIds.value.push(row.id);
    }
    rows.value = rows.value.filter((r) => r !== row);
    if (editingRow.value === row) {
        editingRow.value = null;
    }
}

function move(row, direction) {
    const siblings = rowsIn(row.boq_section_id);
    const target = siblings[siblings.indexOf(row) + direction];
    if (!target) {
        return;
    }
    const list = [...rows.value];
    const a = list.indexOf(row);
    const b = list.indexOf(target);
    [list[a], list[b]] = [list[b], list[a]];
    rows.value = list;
}

function applyAnalysis(row, id) {
    row.rate_analysis_id = id;
    const analysis = props.analyses.find((a) => a.id === id);
    if (!analysis) {
        return;
    }
    Object.assign(row, {
        material_rate: analysis.snapshot.material_rate,
        labour_rate: analysis.snapshot.labour_rate,
        equipment_rate: analysis.snapshot.equipment_rate,
        subcontract_rate: analysis.snapshot.subcontract_rate,
        margin_percent: analysis.snapshot.margin_percent,
        client_rate: analysis.snapshot.client_rate,
    });
    if (!row.unit_id) {
        row.unit_id = analysis.unit_id;
    }
}
const analysisOptions = computed(() => props.analyses.map((a) => ({ value: a.id, label: `${a.code} ${a.name}`, description: `Unit rate ${formatRate(a.unit_rate)}` })));

// ---- Save --------------------------------------------------------------------------------------
const saving = ref(false);
function save() {
    const ordered = orderedSectionIds.value.flatMap((id) => rowsIn(id));
    payloadKeys.value = ordered.map((r) => r._key);
    const payload = {
        rows: ordered.map((r, index) => {
            const { _key, ...data } = r;
            if (!costs.value) {
                delete data.rate_analysis_id;
            }

            return { ...data, sort_order: index + 1 };
        }),
        deleted_ids: deletedIds.value,
    };

    saving.value = true;
    router.put(route('projects.boqs.items.save', [props.project.id, props.boq.id]), payload, {
        preserveScroll: true,
        onSuccess: () => reset(),
        onError: (e) => (errors.value = e),
        onFinish: () => (saving.value = false),
    });
}

const cellError = (row, field) => {
    const index = payloadKeys.value.indexOf(row._key);

    return index >= 0 ? errors.value[`rows.${index}.${field}`] : undefined;
};
const rowHasError = (row) => {
    const index = payloadKeys.value.indexOf(row._key);

    return index >= 0 && Object.keys(errors.value).some((k) => k.startsWith(`rows.${index}.`));
};
const errorSummary = computed(() => {
    const list = [];
    Object.entries(errors.value).forEach(([key, message]) => {
        const m = /^rows\.(\d+)\./.exec(key);
        if (m) {
            const row = rows.value.find((r) => r._key === payloadKeys.value[Number(m[1])]);
            list.push(`${row?.item_code || row?.name || `Line ${Number(m[1]) + 1}`}: ${message}`);
        } else if (!['boq', 'section'].includes(key)) {
            list.push(message);
        }
    });

    return list.slice(0, 12);
});

// ---- Unsaved changes guard ---------------------------------------------------------------------
let removeGuard = null;
const onBeforeUnload = (e) => {
    if (dirty.value) {
        e.preventDefault();
        e.returnValue = '';
    }
};
onMounted(() => {
    updateViewport();
    window.addEventListener('resize', updateViewport);
    window.addEventListener('beforeunload', onBeforeUnload);
    removeGuard = router.on('before', (event) => {
        if (dirty.value && event.detail.visit.method === 'get' && !window.confirm('You have unsaved BOQ changes. Leave without saving?')) {
            return false;
        }
    });
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', updateViewport);
    window.removeEventListener('beforeunload', onBeforeUnload);
    removeGuard?.();
});

// ---- Header / workflow actions -----------------------------------------------------------------
const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'The BOQ is locked while it is being approved.', label: 'Submit', danger: false, run: () => router.post(route('projects.boqs.submit', [props.project.id, props.boq.id]), {}, opts()) },
    revise: { title: 'Create a revision?', message: 'A new draft version is created from this approved BOQ. Line identities are kept; the approved version stays current until the revision is approved.', label: 'Create revision', danger: false, run: () => router.post(route('projects.boqs.revise', [props.project.id, props.boq.id]), {}, opts()) },
    delete: { title: 'Delete this BOQ?', message: 'This draft and all its lines will be removed.', label: 'Delete', danger: true, run: () => router.delete(route('projects.boqs.destroy', [props.project.id, props.boq.id]), opts()) },
};
const opts = () => ({ preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) });
function confirmAction() {
    processing.value = true;
    ACTIONS[confirming.value].run();
}

const approvalAction = ref(null);
const approvalForm = useForm({ comments: '' });
const APPROVAL = {
    approve: { title: 'Approve BOQ', route: 'approvals.approve', button: 'Approve', variant: 'primary', required: false },
    sendBack: { title: 'Send back for changes', route: 'approvals.send-back', button: 'Send back', variant: 'secondary', required: true },
    reject: { title: 'Reject BOQ', route: 'approvals.reject', button: 'Reject', variant: 'danger', required: true },
    cancel: { title: 'Withdraw approval request', route: 'approvals.cancel', button: 'Withdraw', variant: 'danger', required: false },
};
function openApproval(action) {
    approvalForm.reset();
    approvalForm.clearErrors();
    approvalAction.value = action;
}
function submitApproval() {
    approvalForm.post(route(APPROVAL[approvalAction.value].route, props.approval.id), {
        preserveScroll: true,
        onSuccess: () => (approvalAction.value = null),
    });
}

const showTitle = ref(false);
const titleForm = useForm({ title: props.boq.title });
function saveTitle() {
    titleForm.put(route('projects.boqs.update', [props.project.id, props.boq.id]), { preserveScroll: true, onSuccess: () => (showTitle.value = false) });
}

// Sections
const sectionModal = ref(null);
const sectionForm = useForm({ parent_id: null, code: '', name: '', discipline: '' });
const deletingSection = ref(null);
function openSection(section = null, parentId = null) {
    sectionForm.clearErrors();
    sectionForm.parent_id = section ? section.parent_id : parentId;
    sectionForm.code = section?.code ?? '';
    sectionForm.name = section?.name ?? '';
    sectionForm.discipline = section?.discipline ?? '';
    sectionModal.value = { section };
}
function saveSection() {
    const section = sectionModal.value.section;
    const url = section
        ? route('projects.boqs.sections.update', [props.project.id, props.boq.id, section.id])
        : route('projects.boqs.sections.store', [props.project.id, props.boq.id]);
    sectionForm.transform((d) => ({ ...d, code: d.code || null, discipline: d.discipline || null }))[section ? 'put' : 'post'](url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (sectionModal.value = null),
    });
}
function deleteSection() {
    processing.value = true;
    router.delete(route('projects.boqs.sections.destroy', [props.project.id, props.boq.id, deletingSection.value.id]), {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => ((processing.value = false), (deletingSection.value = null)),
    });
}
const parentOptions = computed(() => topSections.value.filter((s) => s.id !== sectionModal.value?.section?.id).map((s) => ({ value: s.id, label: sectionLabel(s) })));

// Import
const showImport = ref(false);
const importForm = useForm({ file: null });
const importErrors = computed(() =>
    Object.entries(importForm.errors)
        .filter(([key]) => key.startsWith('file_rows.'))
        .sort(([a], [b]) => Number(a.split('.')[1]) - Number(b.split('.')[1]))
        .map(([, message]) => message),
);
function runImport() {
    importForm.post(route('projects.boqs.import', [props.project.id, props.boq.id]), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => ((showImport.value = false), importForm.reset()),
    });
}

const lockedReason = computed(() => {
    if (props.can.update) {
        return null;
    }
    return {
        submitted: 'Submitted for approval. Lines are locked until the approval is completed, sent back or withdrawn.',
        approved: props.boq.is_current ? 'Approved and current. To change it, create a revision.' : 'Approved.',
        revised: 'Superseded by a later revision. This version is kept for history.',
    }[props.boq.status] ?? null;
});
const colCount = computed(() => 8 + (costs.value ? 7 : 0));
</script>

<template>
    <ProjectLayout :project="project" active="boq" :title="`${boq.boq_number} v${boq.version}`">
        <div class="space-y-4">
            <!-- Header -->
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.boqs.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">BOQs</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-900">{{ boq.title }}</h2>
                            <button v-if="can.update" type="button" class="text-slate-400 hover:text-slate-600" aria-label="Edit title" @click="(titleForm.title = boq.title), (showTitle = true)">
                                <Icon name="pencil" :size="16" />
                            </button>
                            <StatusBadge :status="boq.status" :label="boq.status_label" />
                            <span v-if="boq.is_current" class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 uppercase">Current</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            <span class="font-mono">{{ boq.boq_number }}</span> · version {{ boq.version }}
                            <template v-if="boq.approved_at"> · approved {{ formatDateTime(boq.approved_at) }}<template v-if="boq.approved_by"> by {{ boq.approved_by }}</template></template>
                        </p>
                        <div v-if="versions.length > 1" class="mt-2 flex flex-wrap gap-1">
                            <Link
                                v-for="v in versions"
                                :key="v.id"
                                :href="route('projects.boqs.show', [project.id, v.id])"
                                class="rounded-md border px-2 py-0.5 text-xs tabular"
                                :class="v.id === boq.id ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-line text-slate-600 hover:bg-slate-50'"
                            >v{{ v.version }}<span v-if="v.is_current"> ✓</span></Link>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 lg:items-end">
                        <div class="flex gap-6 text-right">
                            <div v-if="costs">
                                <p class="text-xs text-slate-500 uppercase">Cost</p>
                                <p class="text-base font-semibold text-slate-900 tabular">{{ formatMoney(totals.cost) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500 uppercase">Client value</p>
                                <p class="text-base font-semibold text-slate-900 tabular">{{ formatMoney(totals.client) }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 lg:justify-end">
                            <template v-if="editable && dirty">
                                <AppButton size="sm" variant="secondary" @click="reset">Discard</AppButton>
                                <AppButton size="sm" :loading="saving" @click="save">Save changes</AppButton>
                            </template>
                            <AppButton v-if="can.submit && !dirty" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                            <template v-if="approval?.can_act">
                                <AppButton size="sm" @click="openApproval('approve')">Approve</AppButton>
                                <AppButton size="sm" variant="secondary" @click="openApproval('sendBack')">Send back</AppButton>
                                <AppButton size="sm" variant="ghost" class="text-red-600" @click="openApproval('reject')">Reject</AppButton>
                            </template>
                            <AppButton v-if="approval?.can_cancel" size="sm" variant="secondary" @click="openApproval('cancel')">Withdraw</AppButton>
                            <AppButton v-if="can.revise" size="sm" variant="secondary" @click="confirming = 'revise'">Create revision</AppButton>
                            <AppButton v-if="can.import" size="sm" variant="secondary" @click="(importForm.reset(), importForm.clearErrors(), (showImport = true))">Import</AppButton>
                            <a v-if="can.export" :href="route('projects.boqs.export', [project.id, boq.id])" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                                <Icon name="download" :size="14" /> Export
                            </a>
                            <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete BOQ" @click="confirming = 'delete'" />
                        </div>
                    </div>
                </div>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>
                </div>
                <div v-else-if="lockedReason" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ lockedReason }}
                </div>
            </AppCard>

            <div v-if="errorSummary.length" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">Nothing was saved. Please fix these lines:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
                    <li v-for="(message, i) in errorSummary" :key="i">{{ message }}</li>
                </ul>
            </div>

            <!-- Sections and lines -->
            <AppCard :padded="false">
                <template #header>
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Sections & items</h2>
                        <p class="mt-0.5 text-xs text-slate-500">{{ rows.length }} line(s) · amounts are recalculated by the server when you save</p>
                    </div>
                </template>
                <template v-if="editable" #actions>
                    <AppButton size="sm" variant="secondary" icon="plus" @click="openSection()">Section</AppButton>
                </template>

                <EmptyState
                    v-if="!topSections.length"
                    icon="document"
                    title="No sections yet"
                    :description="editable ? 'Add a section (e.g. Civil Works), then add items to it, or import an Excel file.' : 'This BOQ has no lines.'"
                />

                <!-- Desktop grid -->
                <div v-else class="hidden max-h-[70vh] overflow-auto md:block">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 z-10 bg-slate-100 text-[11px] tracking-wide text-slate-600 uppercase shadow-sm">
                            <tr>
                                <th class="w-8 px-2 py-2 text-left">#</th>
                                <th class="px-2 py-2 text-left">Code</th>
                                <th class="min-w-[16rem] px-2 py-2 text-left">Item</th>
                                <th class="px-2 py-2 text-left">Unit</th>
                                <th class="px-2 py-2 text-right">Qty</th>
                                <template v-if="costs">
                                    <th class="px-2 py-2 text-right">Material</th>
                                    <th class="px-2 py-2 text-right">Labour</th>
                                    <th class="px-2 py-2 text-right">Equipment</th>
                                    <th class="px-2 py-2 text-right">Subcontract</th>
                                    <th class="px-2 py-2 text-right">Cost rate</th>
                                    <th class="px-2 py-2 text-right">Cost amt</th>
                                    <th class="px-2 py-2 text-right">Margin %</th>
                                </template>
                                <th class="px-2 py-2 text-right">Client rate</th>
                                <th class="px-2 py-2 text-right">Amount</th>
                                <th class="px-2 py-2"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="top in topSections" :key="top.id">
                                <template v-for="section in [top, ...childrenOf(top.id)]" :key="section.id">
                                    <tr :class="section.parent_id ? 'bg-slate-50' : 'bg-brand-50/60'">
                                        <td :colspan="colCount - 2" class="px-2 py-2" :class="section.parent_id ? 'pl-6' : ''">
                                            <span class="font-semibold text-slate-800">{{ sectionLabel(section) }}</span>
                                            <span v-if="section.discipline" class="ml-2 text-[11px] text-slate-500">{{ section.discipline }}</span>
                                        </td>
                                        <td class="px-2 py-2 text-right font-semibold text-slate-800 tabular">{{ section.parent_id ? '' : formatMoney(sectionTotal(section.id, 'client_amount')) }}</td>
                                        <td class="px-2 py-2 text-right whitespace-nowrap">
                                            <template v-if="editable">
                                                <button type="button" class="rounded px-1.5 py-0.5 text-brand-700 hover:bg-white" @click="addRow(section.id)">+ Item</button>
                                                <button v-if="!section.parent_id" type="button" class="rounded px-1.5 py-0.5 text-slate-600 hover:bg-white" @click="openSection(null, section.id)">+ Sub</button>
                                                <button type="button" class="rounded p-1 text-slate-400 hover:bg-white hover:text-slate-600" aria-label="Edit section" @click="openSection(section)"><Icon name="pencil" :size="14" /></button>
                                                <button type="button" class="rounded p-1 text-slate-400 hover:bg-white hover:text-red-600" aria-label="Delete section" @click="deletingSection = section"><Icon name="trash" :size="14" /></button>
                                            </template>
                                        </td>
                                    </tr>
                                    <tr v-for="(row, i) in rowsIn(section.id)" :key="row._key" class="border-b border-line align-top" :class="rowHasError(row) ? 'bg-red-50/50' : 'hover:bg-slate-50/60'">
                                        <td class="px-2 py-1.5 text-slate-400 tabular">{{ i + 1 }}</td>
                                        <template v-if="editable">
                                            <td class="px-1 py-1"><input v-model="row.item_code" maxlength="30" class="w-20 rounded border-slate-200 px-1.5 py-1 text-xs" /></td>
                                            <td class="px-1 py-1">
                                                <input v-model="row.name" maxlength="255" placeholder="Item description" class="w-full rounded px-1.5 py-1 text-xs" :class="cellError(row, 'name') ? 'border-red-400 bg-red-50' : 'border-slate-200'" />
                                                <div v-if="row.rate_analysis_id" class="mt-0.5 text-[10px] text-brand-700">From rate analysis {{ analyses.find((a) => a.id === row.rate_analysis_id)?.code ?? '' }}</div>
                                            </td>
                                            <td class="px-1 py-1">
                                                <select v-model="row.unit_id" class="w-20 rounded py-1 pr-6 pl-1.5 text-xs" :class="cellError(row, 'unit_id') ? 'border-red-400 bg-red-50' : 'border-slate-200'">
                                                    <option :value="null">—</option>
                                                    <option v-for="u in units" :key="u.value" :value="u.value">{{ u.label }}</option>
                                                </select>
                                            </td>
                                            <td class="px-1 py-1"><CellDecimal v-model="row.quantity" :error="!!cellError(row, 'quantity')" /></td>
                                            <template v-if="costs">
                                                <td class="px-1 py-1"><CellDecimal v-model="row.material_rate" :error="!!cellError(row, 'material_rate')" /></td>
                                                <td class="px-1 py-1"><CellDecimal v-model="row.labour_rate" :error="!!cellError(row, 'labour_rate')" /></td>
                                                <td class="px-1 py-1"><CellDecimal v-model="row.equipment_rate" :error="!!cellError(row, 'equipment_rate')" /></td>
                                                <td class="px-1 py-1"><CellDecimal v-model="row.subcontract_rate" :error="!!cellError(row, 'subcontract_rate')" /></td>
                                                <td class="px-2 py-1.5 text-right text-slate-600 tabular">{{ formatRate(calc[row._key].cost_rate) }}</td>
                                                <td class="px-2 py-1.5 text-right text-slate-600 tabular">{{ formatMoney(calc[row._key].cost_amount) }}</td>
                                                <td class="px-1 py-1"><CellDecimal v-model="row.margin_percent" allow-negative :error="!!cellError(row, 'margin_percent')" /></td>
                                            </template>
                                            <td class="px-1 py-1">
                                                <CellDecimal v-model="row.client_rate" :placeholder="costs ? calc[row._key].selling_rate : null" :error="!!cellError(row, 'client_rate')" />
                                            </td>
                                        </template>
                                        <template v-else>
                                            <td class="px-2 py-1.5 font-mono text-slate-600">{{ row.item_code || '—' }}</td>
                                            <td class="px-2 py-1.5 text-slate-800">
                                                {{ row.name }}
                                                <div v-if="row.description" class="text-[11px] text-slate-500">{{ row.description }}</div>
                                            </td>
                                            <td class="px-2 py-1.5">{{ unitLabel(row.unit_id) }}</td>
                                            <td class="px-2 py-1.5 text-right tabular">{{ formatQty(row.quantity) }}</td>
                                            <template v-if="costs">
                                                <td class="px-2 py-1.5 text-right tabular">{{ formatRate(row.material_rate) }}</td>
                                                <td class="px-2 py-1.5 text-right tabular">{{ formatRate(row.labour_rate) }}</td>
                                                <td class="px-2 py-1.5 text-right tabular">{{ formatRate(row.equipment_rate) }}</td>
                                                <td class="px-2 py-1.5 text-right tabular">{{ formatRate(row.subcontract_rate) }}</td>
                                                <td class="px-2 py-1.5 text-right tabular">{{ formatRate(calc[row._key].cost_rate) }}</td>
                                                <td class="px-2 py-1.5 text-right tabular">{{ formatMoney(calc[row._key].cost_amount) }}</td>
                                                <td class="px-2 py-1.5 text-right tabular">{{ formatPercent(row.margin_percent) }}</td>
                                            </template>
                                            <td class="px-2 py-1.5 text-right tabular">{{ formatRate(calc[row._key].client_rate) }}</td>
                                        </template>
                                        <td class="px-2 py-1.5 text-right font-medium text-slate-900 tabular">{{ formatMoney(calc[row._key].client_amount) }}</td>
                                        <td class="px-1 py-1 text-right whitespace-nowrap">
                                            <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" :aria-label="editable ? 'Edit details' : 'View details'" @click="editingRow = row">
                                                <Icon :name="editable ? 'pencil' : 'info'" :size="14" />
                                            </button>
                                            <template v-if="editable">
                                                <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100" aria-label="Move up" :disabled="i === 0" @click="move(row, -1)">↑</button>
                                                <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100" aria-label="Move down" :disabled="i === rowsIn(section.id).length - 1" @click="move(row, 1)">↓</button>
                                                <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-red-600" aria-label="Remove line" @click="removeRow(row)"><Icon name="trash" :size="14" /></button>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                        <tfoot class="sticky bottom-0 bg-white shadow-[0_-1px_0_0_rgb(226_232_240)]">
                            <tr class="font-semibold text-slate-900">
                                <td :colspan="costs ? 10 : 6" class="px-2 py-2 text-right text-[11px] uppercase">Total</td>
                                <template v-if="costs">
                                    <td class="px-2 py-2 text-right tabular">{{ formatMoney(totals.cost) }}</td>
                                    <td colspan="2"></td>
                                </template>
                                <td class="px-2 py-2 text-right tabular">{{ formatMoney(totals.client) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Mobile cards -->
                <div v-if="topSections.length" class="divide-y divide-line md:hidden">
                    <template v-for="top in topSections" :key="top.id">
                        <section v-for="section in [top, ...childrenOf(top.id)]" :key="section.id">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5" :class="section.parent_id ? 'bg-slate-50 pl-6' : 'bg-brand-50/60'">
                                <span class="text-sm font-semibold text-slate-800">{{ sectionLabel(section) }}</span>
                                <div v-if="editable" class="flex shrink-0 items-center gap-1">
                                    <button type="button" class="rounded px-2 py-1 text-xs font-medium text-brand-700" @click="addRow(section.id)">+ Item</button>
                                    <button type="button" class="rounded p-1 text-slate-400" aria-label="Edit section" @click="openSection(section)"><Icon name="pencil" :size="16" /></button>
                                </div>
                            </div>
                            <button
                                v-for="row in rowsIn(section.id)"
                                :key="row._key"
                                type="button"
                                class="block w-full px-4 py-3 text-left active:bg-slate-50"
                                :class="rowHasError(row) ? 'bg-red-50' : ''"
                                @click="editingRow = row"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-900">{{ row.name || 'New item' }}</p>
                                        <p class="text-xs text-slate-500"><span v-if="row.item_code" class="font-mono">{{ row.item_code }} · </span>{{ formatQty(row.quantity) }} {{ unitLabel(row.unit_id) }} × {{ formatRate(calc[row._key].client_rate) }}</p>
                                    </div>
                                    <p class="shrink-0 text-sm font-semibold text-slate-900 tabular">{{ formatMoney(calc[row._key].client_amount) }}</p>
                                </div>
                            </button>
                        </section>
                    </template>
                    <div class="flex justify-between px-4 py-3 text-sm font-semibold">
                        <span>Total</span><span class="tabular">{{ formatMoney(totals.client) }}</span>
                    </div>
                </div>

                <!-- Sticky mobile save bar -->
                <div v-if="editable && dirty" class="sticky bottom-0 flex gap-2 border-t border-line bg-white p-3 md:hidden">
                    <AppButton variant="secondary" block @click="reset">Discard</AppButton>
                    <AppButton block :loading="saving" @click="save">Save changes</AppButton>
                </div>
            </AppCard>
        </div>

        <!-- Line details drawer -->
        <AppDrawer :show="!!editingRow" :title="editingRow?.name || 'BOQ line'" :subtitle="editable ? 'Changes are kept locally until you save the BOQ.' : null" @close="editingRow = null">
            <div v-if="editingRow" class="space-y-4">
                <template v-if="editable">
                    <FormSelect v-model="editingRow.boq_section_id" label="Section" :options="sectionOptions" required />
                    <div class="grid grid-cols-3 gap-3">
                        <FormInput v-model="editingRow.item_code" label="Code" maxlength="30" :error="cellError(editingRow, 'item_code')" />
                        <FormInput v-model="editingRow.hsn_sac" label="HSN/SAC" maxlength="10" class="col-span-2" :error="cellError(editingRow, 'hsn_sac')" />
                    </div>
                    <FormInput v-model="editingRow.name" label="Item" required maxlength="255" :error="cellError(editingRow, 'name')" />
                    <FormInput v-model="editingRow.description" label="Specification / description" multiline :rows="3" :error="cellError(editingRow, 'description')" />
                    <div class="grid grid-cols-2 gap-3">
                        <SearchSelect v-model="editingRow.unit_id" label="Unit" required :options="unitOptions" :error="cellError(editingRow, 'unit_id')" />
                        <DecimalInput v-model="editingRow.quantity" label="Quantity" required :decimals="4" :error="cellError(editingRow, 'quantity')" />
                    </div>
                    <template v-if="costs">
                        <SearchSelect
                            v-if="analyses.length"
                            :model-value="editingRow.rate_analysis_id"
                            label="Rate analysis"
                            help="Copies the analysis rates into this line (snapshot) when you save."
                            :options="analysisOptions"
                            @update:model-value="(id) => applyAnalysis(editingRow, id)"
                        />
                        <div class="grid grid-cols-2 gap-3">
                            <DecimalInput v-model="editingRow.material_rate" label="Material rate" :decimals="4" prefix="₹" :error="cellError(editingRow, 'material_rate')" />
                            <DecimalInput v-model="editingRow.labour_rate" label="Labour rate" :decimals="4" prefix="₹" :error="cellError(editingRow, 'labour_rate')" />
                            <DecimalInput v-model="editingRow.equipment_rate" label="Equipment rate" :decimals="4" prefix="₹" :error="cellError(editingRow, 'equipment_rate')" />
                            <DecimalInput v-model="editingRow.subcontract_rate" label="Subcontract rate" :decimals="4" prefix="₹" :error="cellError(editingRow, 'subcontract_rate')" />
                            <FormInput v-model="editingRow.margin_percent" label="Margin %" inputmode="decimal" :error="cellError(editingRow, 'margin_percent')" />
                            <DecimalInput
                                v-model="editingRow.client_rate"
                                label="Client rate"
                                :decimals="4"
                                prefix="₹"
                                :help="`Leave empty to use cost + margin (${formatRate(calc[editingRow._key].selling_rate)})`"
                                :error="cellError(editingRow, 'client_rate')"
                            />
                        </div>
                    </template>
                    <DecimalInput v-else v-model="editingRow.client_rate" label="Client rate" :decimals="4" prefix="₹" :error="cellError(editingRow, 'client_rate')" />
                </template>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg bg-slate-50 p-3 text-sm">
                    <template v-if="!editable">
                        <dt class="text-slate-500">Code</dt><dd class="text-right font-mono">{{ editingRow.item_code || '—' }}</dd>
                        <dt class="text-slate-500">HSN/SAC</dt><dd class="text-right">{{ editingRow.hsn_sac || '—' }}</dd>
                        <dt class="text-slate-500">Quantity</dt><dd class="text-right tabular">{{ formatQty(editingRow.quantity) }} {{ unitLabel(editingRow.unit_id) }}</dd>
                    </template>
                    <template v-if="costs">
                        <dt class="text-slate-500">Cost rate</dt><dd class="text-right tabular">{{ formatRate(calc[editingRow._key].cost_rate) }}</dd>
                        <dt class="text-slate-500">Cost amount</dt><dd class="text-right tabular">{{ formatMoney(calc[editingRow._key].cost_amount) }}</dd>
                        <dt class="text-slate-500">Selling rate</dt><dd class="text-right tabular">{{ formatRate(calc[editingRow._key].selling_rate) }}</dd>
                    </template>
                    <dt class="text-slate-500">Client rate</dt><dd class="text-right tabular">{{ formatRate(calc[editingRow._key].client_rate) }}</dd>
                    <dt class="font-medium text-slate-700">Client amount</dt><dd class="text-right font-semibold tabular">{{ formatMoney(calc[editingRow._key].client_amount) }}</dd>
                </dl>
                <p v-if="!editable && editingRow.description" class="text-sm whitespace-pre-line text-slate-600">{{ editingRow.description }}</p>
            </div>
            <template #footer>
                <AppButton v-if="editable" variant="ghost" class="mr-auto text-red-600" icon="trash" @click="removeRow(editingRow)">Remove</AppButton>
                <AppButton variant="secondary" @click="editingRow = null">Done</AppButton>
            </template>
        </AppDrawer>

        <!-- Section modal -->
        <AppModal :show="!!sectionModal" :title="sectionModal?.section ? 'Edit section' : sectionForm.parent_id ? 'Add subsection' : 'Add section'" @close="sectionModal = null">
            <form id="section-form" class="grid gap-4 sm:grid-cols-3" @submit.prevent="saveSection">
                <FormSelect v-model="sectionForm.parent_id" label="Parent section" placeholder="— Top level —" :options="parentOptions" class="sm:col-span-3" :error="sectionForm.errors.parent_id" />
                <FormInput v-model="sectionForm.code" label="Code" maxlength="30" :error="sectionForm.errors.code" />
                <FormInput v-model="sectionForm.name" label="Name" required maxlength="200" class="sm:col-span-2" :error="sectionForm.errors.name" />
                <FormInput v-model="sectionForm.discipline" label="Discipline" maxlength="50" class="sm:col-span-3" placeholder="e.g. Civil, MEP" :error="sectionForm.errors.discipline" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="sectionModal = null">Cancel</AppButton>
                <AppButton type="submit" form="section-form" :loading="sectionForm.processing">Save</AppButton>
            </template>
        </AppModal>

        <!-- Import modal -->
        <AppModal :show="showImport" title="Import lines from Excel" max-width="2xl" @close="showImport = false">
            <div class="space-y-3">
                <p class="text-sm text-slate-600">
                    Lines are appended to this draft. Every row is checked first; if any row has a problem nothing is imported.
                    <a :href="route('projects.boqs.template', project.id)" class="font-medium text-brand-700 hover:underline">Download the sample template</a>.
                </p>
                <p v-if="dirty" class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">Save or discard your unsaved changes first: the page reloads after importing.</p>
                <FileUpload accept=".xlsx,.xls,.csv" :max-mb="10" :error="importForm.errors.file" @select="(f) => (importForm.file = f)" />
                <p v-if="importForm.file" class="text-xs text-slate-600">Selected: {{ importForm.file.name }}</p>
                <ul v-if="importErrors.length" class="max-h-56 list-disc overflow-y-auto rounded-lg border border-red-200 bg-red-50 py-2 pr-3 pl-7 text-xs text-red-800">
                    <li v-for="(message, i) in importErrors" :key="i">{{ message }}</li>
                </ul>
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="showImport = false">Cancel</AppButton>
                <AppButton :disabled="!importForm.file || dirty" :loading="importForm.processing" @click="runImport">Import</AppButton>
            </template>
        </AppModal>

        <AppModal :show="showTitle" title="Edit BOQ title" @close="showTitle = false">
            <form id="title-form" @submit.prevent="saveTitle">
                <FormInput v-model="titleForm.title" label="Title" required maxlength="200" :error="titleForm.errors.title" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="showTitle = false">Cancel</AppButton>
                <AppButton type="submit" form="title-form" :loading="titleForm.processing">Save</AppButton>
            </template>
        </AppModal>

        <AppModal :show="!!approvalAction" :title="approvalAction ? APPROVAL[approvalAction].title : ''" @close="approvalAction = null">
            <FormInput
                v-model="approvalForm.comments"
                :label="approvalAction && APPROVAL[approvalAction].required ? 'Reason' : 'Comments (optional)'"
                multiline
                :rows="3"
                :required="approvalAction ? APPROVAL[approvalAction].required : false"
                :error="approvalForm.errors.comments || approvalForm.errors.approval"
            />
            <template #footer>
                <AppButton variant="secondary" @click="approvalAction = null">Cancel</AppButton>
                <AppButton :variant="approvalAction ? APPROVAL[approvalAction].variant : 'primary'" :loading="approvalForm.processing" @click="submitApproval">
                    {{ approvalAction ? APPROVAL[approvalAction].button : '' }}
                </AppButton>
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
        <ConfirmDialog
            :show="!!deletingSection"
            :title="`Delete section ${deletingSection?.name ?? ''}?`"
            message="Only empty sections can be deleted."
            confirm-label="Delete section"
            :processing="processing"
            @close="deletingSection = null"
            @confirm="deleteSection"
        />
    </ProjectLayout>
</template>
