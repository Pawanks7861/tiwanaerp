<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import ApprovedDrawingPicker from '@/Components/Boq/ApprovedDrawingPicker.vue';
import CellDecimal from '@/Components/Boq/CellDecimal.vue';
import UniversalFileViewer from '@/Components/Files/UniversalFileViewer.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ImportTemplateDownload from '@/Components/Imports/ImportTemplateDownload.vue';
import UniversalImportModal from '@/Components/Imports/UniversalImportModal.vue';
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
import { formatDate, formatDateTime, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
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
    drawings: { type: Array, default: () => [] },
    approved_drawings: { type: Array, default: () => [] },
    has_newer_approved_revision: { type: Boolean, default: false },
    source_import: { type: Object, default: null },
    source_imports: { type: Array, default: () => [] },
    import_history: { type: Array, default: () => [] },
});

const COST_FIELDS = ['material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate', 'margin_percent'];
const DEFAULT_SIMPLE_MATERIALS = [
    { material_name: 'Cement', quantity: '', unit: 'Bag', rate: '' },
    { material_name: 'Sand', quantity: '', unit: 'Cum', rate: '' },
    { material_name: 'Aggregate', quantity: '', unit: 'Cum', rate: '' },
];
const page = usePage();
const advanced = computed(() => page.props.features?.advanced_boq_calculations === true);
const editable = computed(() => props.can.update);
const viewingDrawing = ref(null);
const showDrawings = ref(false);
const drawingsForm = useForm({ drawing_revision_ids: [] });
const drawingFieldErrors = computed(() => Object.fromEntries(
    Object.entries(drawingsForm.errors).filter(([key]) => key.startsWith('drawing_revision_ids.')),
));
const viewableDrawings = computed(() => props.drawings.filter((drawing) => drawing.download_url || drawing.file_url));

function openDrawings() {
    drawingsForm.reset();
    drawingsForm.clearErrors();
    drawingsForm.drawing_revision_ids = props.drawings.map((drawing) => drawing.id);
    showDrawings.value = true;
}

function saveDrawings() {
    drawingsForm.put(route('projects.boqs.drawings.sync', [props.project.id, props.boq.id]), {
        preserveScroll: true,
        onSuccess: () => (showDrawings.value = false),
    });
}
const costs = computed(() => props.can.view_costs);
/** Full cost-build columns/editor — only when Advanced BOQ Calculations is enabled. */
const detailedCosts = computed(() => costs.value && advanced.value);
const showSimpleMaterials = computed(() => costs.value && !advanced.value);

let tempId = 0;

function blankSimpleMaterial(overrides = {}) {
    return {
        _key: `m${++tempId}`,
        material_id: null,
        material_name: '',
        quantity: '',
        unit: '',
        rate: '',
        ...overrides,
    };
}

function mapSimpleMaterials(item) {
    if (item.simple_materials_initialized) {
        return (item.simple_materials || []).map((m) => blankSimpleMaterial({
            material_id: m.material_id ?? null,
            material_name: m.material_name ?? '',
            quantity: m.quantity ?? '',
            unit: m.unit ?? '',
            rate: m.rate ?? '',
        }));
    }

    return DEFAULT_SIMPLE_MATERIALS.map((m) => blankSimpleMaterial({ ...m }));
}

function materialAmount(row) {
    const qty = Number(row.quantity);
    const rate = Number(row.rate);
    if (!Number.isFinite(qty) || !Number.isFinite(rate)) return 0;
    return qty * rate;
}

function materialTotalQty(line, boqQty) {
    const qty = Number(line.quantity);
    const total = Number(boqQty);
    if (!Number.isFinite(qty) || !Number.isFinite(total)) return 0;
    return qty * total;
}

function materialTotalCost(line, boqQty) {
    return materialTotalQty(line, boqQty) * (Number.isFinite(Number(line.rate)) ? Number(line.rate) : 0);
}

function materialTotals(row) {
    const lines = row.simple_materials || [];
    const perUnit = lines.reduce((sum, line) => sum + materialAmount(line), 0);
    const qty = Number(row.quantity);
    const estimated = Number.isFinite(qty) ? perUnit * qty : 0;
    const aggregated = new Map();
    lines.forEach((line) => {
        const name = String(line.material_name || '').trim();
        const unit = String(line.unit || '').trim();
        if (!name || !unit) return;
        const key = `${name.toLowerCase()}|${unit.toLowerCase()}`;
        const per = Number(line.quantity);
        if (!Number.isFinite(per)) return;
        const current = aggregated.get(key) || { material_name: name, unit, per_unit_quantity: 0 };
        current.per_unit_quantity += per;
        aggregated.set(key, current);
    });
    const quantitySummary = [...aggregated.values()].map((group) => ({
        ...group,
        total_quantity: Number.isFinite(qty) ? group.per_unit_quantity * qty : 0,
    }));

    return { perUnit, estimated, quantitySummary };
}

const attachmentsOpen = ref(false);

function markMaterialsDirty(row) {
    row.simple_materials_dirty = true;
}

function addSimpleMaterial(row) {
    row.simple_materials.push(blankSimpleMaterial());
    markMaterialsDirty(row);
}

function removeSimpleMaterial(row, index) {
    row.simple_materials.splice(index, 1);
    markMaterialsDirty(row);
}

// ---- Rows (local editable copy; the server recalculates everything on save) -------------------
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
        simple_materials_initialized: !!item.simple_materials_initialized,
        simple_materials_dirty: false,
        simple_materials: costs.value ? mapSimpleMaterials(item) : [],
        attachments: Array.isArray(item.attachments) ? item.attachments : [],
        line_uid: item.line_uid ?? null,
    };
    if (detailedCosts.value) {
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

function syncAttachmentsFromProps() {
    const byId = Object.fromEntries(props.items.filter((item) => item.id).map((item) => [item.id, item]));
    rows.value.forEach((row) => {
        if (row.id && byId[row.id]) {
            row.attachments = Array.isArray(byId[row.id].attachments) ? byId[row.id].attachments : [];
        }
    });
}

// Section/attachment reloads update page props. Keep local edits when dirty; always refresh attachments.
watch(() => JSON.stringify(props.items), () => {
    if (!baseline.value) {
        reset();
        return;
    }
    if (dirty.value) {
        syncAttachmentsFromProps();
        return;
    }
    const editingId = editingRow.value?.id ?? null;
    reset();
    if (editingId) {
        editingRow.value = rows.value.find((row) => row.id === editingId) ?? null;
    }
}, { immediate: true });

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
            const {
                _key,
                simple_materials,
                simple_materials_dirty,
                simple_materials_initialized,
                attachments,
                line_uid,
                ...data
            } = r;
            if (!detailedCosts.value) {
                delete data.rate_analysis_id;
                COST_FIELDS.forEach((f) => delete data[f]);
            }
            if (showSimpleMaterials.value && (simple_materials_dirty || simple_materials_initialized)) {
                data.simple_materials = (simple_materials || []).map((m) => ({
                    material_id: m.material_id || null,
                    material_name: m.material_name,
                    quantity: m.quantity === '' || m.quantity == null ? '0' : String(m.quantity),
                    unit: m.unit,
                    rate: m.rate === '' || m.rate == null ? '0' : String(m.rate),
                }));
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

const showImport = ref(false);

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
const colCount = computed(() => 8 + (detailedCosts.value ? 7 : 0));
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
                            <div v-if="detailedCosts">
                                <p class="text-xs text-slate-500 uppercase">Cost</p>
                                <p class="text-base font-semibold text-slate-900 tabular">{{ formatMoney(totals.cost) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500 uppercase">BOQ value</p>
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
                            <AppButton v-if="can.import && !dirty" size="sm" icon="folder" @click="showImport = true">Import Excel / CSV</AppButton>
                            <ImportTemplateDownload v-if="can.import" module="boq.items" :project-id="project.id" size="sm" label="Download template" />
                            <AppButton v-if="editable" size="sm" variant="secondary" @click="addRow">Add item manually</AppButton>
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

            <div v-if="has_newer_approved_revision" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                A newer approved drawing revision is available. Review whether this BOQ requires an update.
            </div>

            <AppCard
                v-if="(source_imports?.length || source_import)"
                title="Source files"
                subtitle="Original workbooks retained from successful BOQ imports."
            >
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-600 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">File</th>
                                <th class="px-3 py-2 text-left">Sheet</th>
                                <th class="px-3 py-2 text-left">Imported by</th>
                                <th class="px-3 py-2 text-left">Date</th>
                                <th class="px-3 py-2 text-right" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="file in (source_imports?.length ? source_imports : [source_import])" :key="file.id">
                                <td class="px-3 py-2 text-slate-900">
                                    {{ file.original_filename }}
                                    <span v-if="file.is_primary" class="ml-1 text-[10px] font-medium uppercase text-slate-500">latest</span>
                                </td>
                                <td class="px-3 py-2 text-slate-700">{{ file.sheet ?? '—' }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ file.uploaded_by?.name ?? '—' }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ formatDateTime(file.created_at) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <a
                                        v-if="file.download_url"
                                        :href="file.download_url"
                                        class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline"
                                    >
                                        <Icon name="download" :size="16" /> Download
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard
                v-if="import_history?.length"
                title="Import history"
                subtitle="Recent import batches for this BOQ."
                :padded="false"
            >
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-600 uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">Filename</th>
                                <th class="px-4 py-2 text-left">Sheet</th>
                                <th class="px-4 py-2 text-left">Imported by</th>
                                <th class="px-4 py-2 text-left">Date</th>
                                <th class="px-4 py-2 text-right">Rows</th>
                                <th class="px-4 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in import_history" :key="row.id">
                                <td class="px-4 py-2 text-slate-900">{{ row.original_filename }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ row.sheet ?? '—' }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ row.uploaded_by?.name ?? '—' }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ formatDateTime(row.date) }}</td>
                                <td class="px-4 py-2 text-right tabular text-slate-700">{{ row.rows ?? '—' }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ row.status_label }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard title="Approved Drawings" subtitle="Source drawings used to prepare this BOQ. Later approvals do not change these links or quantities." :padded="false">
                <template v-if="editable" #actions>
                    <AppButton size="sm" variant="secondary" @click="openDrawings">{{ drawings.length ? 'Update drawings' : 'Link drawings' }}</AppButton>
                </template>
                <EmptyState
                    v-if="!drawings.length"
                    icon="document"
                    title="No source drawings"
                    :description="editable ? 'This is a historical BOQ. Link currently approved drawings if this version should record its sources.' : 'This BOQ has no linked drawing revisions.'"
                />
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-600 uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">Drawing Number</th>
                                <th class="px-4 py-2 text-left">Drawing Title</th>
                                <th class="px-4 py-2 text-left">Revision</th>
                                <th class="px-4 py-2 text-left">Approval Status</th>
                                <th class="px-4 py-2 text-left">Approved</th>
                                <th class="px-4 py-2 text-right" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="drawing in drawings" :key="drawing.id">
                                <td class="px-4 py-2 font-mono font-medium text-slate-900">{{ drawing.drawing_number }}</td>
                                <td class="px-4 py-2 text-slate-800">{{ drawing.title }}</td>
                                <td class="px-4 py-2 tabular text-slate-700">{{ drawing.revision_code }}</td>
                                <td class="px-4 py-2">
                                    <StatusBadge :status="drawing.status" :label="drawing.status_label" />
                                    <p v-if="drawing.newer_revision" class="mt-1 text-[11px] text-amber-800">
                                        Current approved: {{ drawing.newer_revision.revision_code }}
                                    </p>
                                </td>
                                <td class="px-4 py-2 text-slate-600">
                                    <div v-if="drawing.approved_at">{{ formatDate(drawing.approved_at) }}</div>
                                    <div v-if="drawing.approved_by" class="text-slate-400">{{ drawing.approved_by }}</div>
                                </td>
                                <td class="px-4 py-2 text-right whitespace-nowrap">
                                    <button
                                        v-if="drawing.file_url"
                                        type="button"
                                        class="text-brand-700 hover:text-brand-800"
                                        @click="viewingDrawing = drawing"
                                    >View</button>
                                    <a
                                        v-if="drawing.download_url"
                                        :href="drawing.download_url"
                                        class="ml-3 text-slate-600 hover:text-slate-800"
                                    >Download</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
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
                    :description="editable ? 'Import Excel/CSV to add many lines at once, or add a section and items manually.' : 'This BOQ has no lines yet.'"
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
                                <template v-if="detailedCosts">
                                    <th class="px-2 py-2 text-right">Material</th>
                                    <th class="px-2 py-2 text-right">Labour</th>
                                    <th class="px-2 py-2 text-right">Equipment</th>
                                    <th class="px-2 py-2 text-right">Subcontract</th>
                                    <th class="px-2 py-2 text-right">Cost rate</th>
                                    <th class="px-2 py-2 text-right">Cost amt</th>
                                    <th class="px-2 py-2 text-right">Margin %</th>
                                </template>
                                <th class="px-2 py-2 text-right">Rate</th>
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
                                            <template v-if="detailedCosts">
                                                <td class="px-1 py-1"><CellDecimal v-model="row.material_rate" :error="!!cellError(row, 'material_rate')" /></td>
                                                <td class="px-1 py-1"><CellDecimal v-model="row.labour_rate" :error="!!cellError(row, 'labour_rate')" /></td>
                                                <td class="px-1 py-1"><CellDecimal v-model="row.equipment_rate" :error="!!cellError(row, 'equipment_rate')" /></td>
                                                <td class="px-1 py-1"><CellDecimal v-model="row.subcontract_rate" :error="!!cellError(row, 'subcontract_rate')" /></td>
                                                <td class="px-2 py-1.5 text-right text-slate-600 tabular">{{ formatRate(calc[row._key].cost_rate) }}</td>
                                                <td class="px-2 py-1.5 text-right text-slate-600 tabular">{{ formatMoney(calc[row._key].cost_amount) }}</td>
                                                <td class="px-1 py-1"><CellDecimal v-model="row.margin_percent" allow-negative :error="!!cellError(row, 'margin_percent')" /></td>
                                            </template>
                                            <td class="px-1 py-1">
                                                <CellDecimal v-model="row.client_rate" :placeholder="detailedCosts ? calc[row._key].selling_rate : null" :error="!!cellError(row, 'client_rate')" />
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
                                            <template v-if="detailedCosts">
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
                                <td :colspan="detailedCosts ? 10 : 6" class="px-2 py-2 text-right text-[11px] uppercase">Total</td>
                                <template v-if="detailedCosts">
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
                    <template v-if="detailedCosts">
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
                                label="Rate"
                                :decimals="4"
                                prefix="₹"
                                :help="`Leave empty to use cost + margin (${formatRate(calc[editingRow._key].selling_rate)})`"
                                :error="cellError(editingRow, 'client_rate')"
                            />
                        </div>
                    </template>
                    <DecimalInput v-else v-model="editingRow.client_rate" label="Rate" :decimals="4" prefix="₹" :error="cellError(editingRow, 'client_rate')" />
                </template>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg bg-slate-50 p-3 text-sm">
                    <template v-if="!editable">
                        <dt class="text-slate-500">Code</dt><dd class="text-right font-mono">{{ editingRow.item_code || '—' }}</dd>
                        <dt class="text-slate-500">HSN/SAC</dt><dd class="text-right">{{ editingRow.hsn_sac || '—' }}</dd>
                        <dt class="text-slate-500">Quantity</dt><dd class="text-right tabular">{{ formatQty(editingRow.quantity) }} {{ unitLabel(editingRow.unit_id) }}</dd>
                    </template>
                    <template v-if="detailedCosts">
                        <dt class="text-slate-500">Cost rate</dt><dd class="text-right tabular">{{ formatRate(calc[editingRow._key].cost_rate) }}</dd>
                        <dt class="text-slate-500">Cost amount</dt><dd class="text-right tabular">{{ formatMoney(calc[editingRow._key].cost_amount) }}</dd>
                        <dt class="text-slate-500">Selling rate</dt><dd class="text-right tabular">{{ formatRate(calc[editingRow._key].selling_rate) }}</dd>
                    </template>
                    <dt class="text-slate-500">Rate</dt><dd class="text-right tabular">{{ formatRate(calc[editingRow._key].client_rate) }}</dd>
                    <dt class="font-medium text-slate-700">Amount</dt><dd class="text-right font-semibold tabular">{{ formatMoney(calc[editingRow._key].client_amount) }}</dd>
                    <template v-if="showSimpleMaterials && editingRow.simple_materials_initialized && editingRow.simple_materials?.length">
                        <dt class="text-slate-500">Material Cost / {{ unitLabel(editingRow.unit_id) || 'unit' }}</dt>
                        <dd class="text-right tabular">{{ formatMoney(materialTotals(editingRow).perUnit) }}</dd>
                        <dt class="text-slate-500">Est. Material Cost</dt>
                        <dd class="text-right tabular">{{ formatMoney(materialTotals(editingRow).estimated) }}</dd>
                    </template>
                </dl>

                <div v-if="showSimpleMaterials" class="space-y-3 rounded-xl border border-line p-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Material</h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Enter material required for 1 {{ unitLabel(editingRow.unit_id) || 'BOQ unit' }} of this BOQ item.
                            Internal estimate only — does not change the BOQ Rate.
                        </p>
                    </div>

                    <div class="space-y-2">
                        <div
                            v-for="(mat, mi) in editingRow.simple_materials"
                            :key="mat._key"
                            class="rounded-lg border border-line p-3"
                        >
                            <input
                                v-model="mat.material_name"
                                class="mb-2 w-full rounded border-slate-200 px-2 py-1.5 text-sm font-medium"
                                :disabled="!editable"
                                placeholder="Material name"
                                @input="markMaterialsDirty(editingRow)"
                            />
                            <div class="grid grid-cols-2 gap-2">
                                <input
                                    v-model="mat.quantity"
                                    class="rounded border-slate-200 px-2 py-1.5 text-sm tabular"
                                    :disabled="!editable"
                                    :placeholder="`Qty / ${unitLabel(editingRow.unit_id) || 'unit'}`"
                                    inputmode="decimal"
                                    @input="markMaterialsDirty(editingRow)"
                                />
                                <input
                                    v-model="mat.unit"
                                    class="rounded border-slate-200 px-2 py-1.5 text-sm"
                                    :disabled="!editable"
                                    placeholder="Unit"
                                    @input="markMaterialsDirty(editingRow)"
                                />
                                <input
                                    v-model="mat.rate"
                                    class="rounded border-slate-200 px-2 py-1.5 text-sm tabular"
                                    :disabled="!editable"
                                    placeholder="Rate"
                                    inputmode="decimal"
                                    @input="markMaterialsDirty(editingRow)"
                                />
                                <div class="flex items-center justify-between rounded bg-slate-50 px-2 text-sm">
                                    <span class="text-slate-500">Cost / unit</span>
                                    <span class="font-medium tabular">{{ formatMoney(materialAmount(mat)) }}</span>
                                </div>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                                <div class="rounded bg-slate-50 px-2 py-1.5">
                                    <p class="text-[11px] text-slate-500">Total Qty</p>
                                    <p class="font-medium tabular">{{ formatQty(materialTotalQty(mat, editingRow.quantity)) }} {{ mat.unit || '' }}</p>
                                </div>
                                <div class="rounded bg-slate-50 px-2 py-1.5">
                                    <p class="text-[11px] text-slate-500">Estimated Cost</p>
                                    <p class="font-medium tabular">{{ formatMoney(materialTotalCost(mat, editingRow.quantity)) }}</p>
                                </div>
                            </div>
                            <button
                                v-if="editable"
                                type="button"
                                class="mt-2 text-xs font-medium text-red-600"
                                @click="removeSimpleMaterial(editingRow, mi)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>

                    <AppButton
                        v-if="editable"
                        type="button"
                        size="sm"
                        variant="secondary"
                        icon="plus"
                        @click="addSimpleMaterial(editingRow)"
                    >
                        Add Material
                    </AppButton>

                    <div class="rounded-lg bg-slate-50 p-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-600">Material Cost / {{ unitLabel(editingRow.unit_id) || 'unit' }}</span>
                            <span class="font-semibold tabular text-slate-900">{{ formatMoney(materialTotals(editingRow).perUnit) }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between gap-3 text-slate-600">
                            <span>Total Estimated Material Cost</span>
                            <span class="font-semibold tabular text-slate-900">{{ formatMoney(materialTotals(editingRow).estimated) }}</span>
                        </div>
                        <div v-if="materialTotals(editingRow).quantitySummary.length" class="mt-3 border-t border-slate-200 pt-2">
                            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Total Material Quantities</p>
                            <ul class="mt-1 space-y-0.5">
                                <li
                                    v-for="group in materialTotals(editingRow).quantitySummary"
                                    :key="`${group.material_name}|${group.unit}`"
                                    class="flex items-center justify-between gap-3 text-slate-700"
                                >
                                    <span>{{ group.material_name }}</span>
                                    <span class="tabular">{{ formatQty(group.total_quantity) }} {{ group.unit }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <details v-if="editingRow.id" class="rounded-xl border border-line" :open="attachmentsOpen || undefined" @toggle="attachmentsOpen = $event.target.open">
                    <summary class="cursor-pointer list-none px-3 py-2.5 text-sm font-semibold text-slate-900 marker:content-none [&::-webkit-details-marker]:hidden">
                        <span class="inline-flex items-center gap-2">
                            <Icon name="paperclip" :size="16" class="text-slate-500" />
                            Attachments ({{ editingRow.attachments?.length || 0 }})
                        </span>
                    </summary>
                    <div class="border-t border-line">
                        <AttachmentPanel
                            :attachments="editingRow.attachments || []"
                            attachable-type="boq_item"
                            :attachable-id="editingRow.id"
                            :can-upload="editable"
                            :can-delete="editable"
                            embedded
                            title="Attachments"
                            placeholder="Excel calc, PDF, drawing, photo, specification…"
                        />
                    </div>
                </details>
                <p v-else-if="editable" class="rounded-xl border border-dashed border-line px-3 py-2 text-xs text-slate-500">
                    Save the BOQ once to attach files to this new item.
                </p>

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

        <UniversalImportModal
            :show="showImport"
            module="boq.items"
            :project-id="project.id"
            :context="{ boq_id: boq.id }"
            @close="showImport = false"
            @imported="() => ((showImport = false), router.reload())"
        />

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

        <AppModal :show="showDrawings" title="Source drawings" @close="showDrawings = false">
            <ApprovedDrawingPicker
                v-model="drawingsForm.drawing_revision_ids"
                :drawings="approved_drawings"
                :error="drawingsForm.errors.drawing_revision_ids"
                :errors="drawingFieldErrors"
                empty-title="No approved drawings"
                empty-description="Approve a drawing revision for this project before linking it to the BOQ."
            />
            <template #footer>
                <AppButton variant="secondary" @click="showDrawings = false">Cancel</AppButton>
                <AppButton :loading="drawingsForm.processing" :disabled="!drawingsForm.drawing_revision_ids.length" @click="saveDrawings">Save drawings</AppButton>
            </template>
        </AppModal>

        <UniversalFileViewer
            :show="!!viewingDrawing"
            source="drawing_revision"
            :file-id="viewingDrawing?.id ?? null"
            :gallery="viewableDrawings.map((drawing) => ({ source: 'drawing_revision', id: drawing.id }))"
            @close="viewingDrawing = null"
        />
    </ProjectLayout>
</template>
