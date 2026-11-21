<script setup>
import FilterBar from '@/Components/Data/FilterBar.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ImportTemplateDownload from '@/Components/Imports/ImportTemplateDownload.vue';
import UniversalImportModal from '@/Components/Imports/UniversalImportModal.vue';
import PlanningNav from '@/Components/Planning/PlanningNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import CopyValueButton from '@/Components/UI/CopyValueButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatPercent, formatQty } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    tasks: { type: Array, required: true },
    summary: { type: Object, default: () => ({}) },
    filtered: { type: Boolean, default: false },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const showImport = ref(false);
const byId = computed(() => Object.fromEntries(props.tasks.map((t) => [t.id, t])));

const heads = computed(() => props.tasks.filter((t) => t.is_head).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id));

/** Group: Head → activities with Sr. restarting per head. Ungrouped leaf top-level with qty shown under synthetic group. */
const scheduleSections = computed(() => {
    const sections = [];
    const childIds = new Set();

    heads.value.forEach((head) => {
        const children = props.tasks
            .filter((t) => t.parent_id === head.id)
            .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
        children.forEach((c) => childIds.add(c.id));

        const activities = children.length
            ? children
            : (Number(head.planned_qty) > 0 || head.unit_id ? [head] : []);

        sections.push({
            head,
            activities: activities.map((a, i) => ({ ...a, sr: i + 1 })),
        });
    });

    const orphans = props.tasks.filter((t) => !t.is_head && !childIds.has(t.id) && t.parent_id && !byId.value[t.parent_id]);
    if (orphans.length) {
        sections.push({
            head: { id: 0, wbs_code: '—', name: 'Ungrouped' },
            activities: orphans.sort((a, b) => a.sort_order - b.sort_order).map((a, i) => ({ ...a, sr: i + 1 })),
        });
    }

    return sections;
});

const filterSelects = computed(() => [
    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...props.options.statuses] },
    { key: 'head_id', options: [{ value: 'all', label: 'All heads' }, ...(props.options.heads ?? [])] },
    { key: 'assigned_to', options: [{ value: 'all', label: 'Anyone' }, ...props.options.members] },
]);

const drawer = ref(null); // { task | null, mode: 'head'|'activity' }
const form = useForm({
    as_head: false,
    parent_id: null,
    wbs_code: '',
    name: '',
    description: '',
    assigned_to: null,
    priority: 'medium',
    planned_start: null,
    planned_finish: null,
    unit_id: null,
    planned_qty: null,
    daily_target_qty: null,
    hold_quantity: null,
    milestone_id: null,
});
const task = computed(() => (drawer.value?.task ? byId.value[drawer.value.task.id] ?? drawer.value.task : null));
const editable = computed(() => (task.value ? props.can.update : props.can.create));
const isHeadForm = computed(() => !!form.as_head || (!!task.value && task.value.is_head && !task.value.parent_id && !Number(task.value.planned_qty)));

const parentOptions = computed(() => (props.options.heads ?? []).filter((h) => !task.value || h.value !== task.value.id));

function openHead(t = null) {
    form.clearErrors();
    form.as_head = true;
    form.parent_id = null;
    form.wbs_code = t?.wbs_code ?? '';
    form.name = t?.name ?? '';
    form.description = t?.description ?? '';
    form.assigned_to = null;
    form.priority = 'medium';
    form.planned_start = null;
    form.planned_finish = null;
    form.unit_id = null;
    form.planned_qty = null;
    form.daily_target_qty = null;
    form.hold_quantity = null;
    form.milestone_id = null;
    drawer.value = { task: t, mode: 'head' };
    if (!t) {
        suggestWbs();
    }
}

function openActivity(t = null, headId = null) {
    form.clearErrors();
    const source = t ?? {};
    form.as_head = false;
    form.parent_id = source.parent_id ?? headId ?? null;
    form.wbs_code = source.wbs_code ?? '';
    form.name = source.name ?? '';
    form.description = source.description ?? '';
    form.assigned_to = source.assigned_to ?? null;
    form.priority = source.priority ?? 'medium';
    form.planned_start = source.planned_start ?? null;
    form.planned_finish = source.planned_finish ?? null;
    form.unit_id = source.unit_id ?? null;
    form.planned_qty = source.planned_qty ?? null;
    form.daily_target_qty = source.daily_target_qty ?? null;
    form.hold_quantity = source.hold_quantity ?? '0';
    form.milestone_id = source.milestone_id ?? null;
    // Edit: do not overwrite an existing Total Qty. New activity: allow one Nos seed.
    plannedQtyNosSeeded.value = !! t;
    drawer.value = { task: t, mode: 'activity' };
    loadMapsFromTask(t);
    if (!t) {
        suggestWbs();
    }
}

async function suggestWbs() {
    try {
        const { data } = await axios.get(route('projects.planning.tasks.suggest-wbs', props.project.id), {
            params: { parent_id: form.as_head ? undefined : form.parent_id || undefined },
        });
        form.wbs_code = data.wbs_code;
    } catch {
        // Server assigns when empty.
    }
}
watch(
    () => [form.parent_id, form.as_head],
    () => drawer.value && !drawer.value.task && suggestWbs(),
);

function save() {
    const url = task.value
        ? route('projects.planning.tasks.update', [props.project.id, task.value.id])
        : route('projects.planning.tasks.store', props.project.id);
    form.transform((data) => {
        const payload = { ...data };
        if (payload.as_head) {
            payload.parent_id = null;
            payload.unit_id = null;
            payload.planned_qty = null;
            payload.daily_target_qty = null;
            payload.hold_quantity = '0';
            payload.planned_start = null;
            payload.planned_finish = null;
            delete payload.maps;
        } else {
            // Cleared DecimalInput is null — persist as 0 so the server does not keep the old hold.
            payload.hold_quantity =
                payload.hold_quantity === null || payload.hold_quantity === '' ? '0' : payload.hold_quantity;
            // Same-step Activity + optional BOQ mapping (no prior Activity id required).
            payload.maps = (mapForm.maps || [])
                .filter((m) => m.boq_item_id)
                .map((m, i) => ({
                    id: m.id || null,
                    boq_item_id: m.boq_item_id,
                    tracking_basis: m.tracking_basis || 'boq_quantity',
                    mapping_method: m.mapping_method || 'conversion_based',
                    planning_qty: m.planning_qty,
                    allocated_boq_qty: m.tracking_basis === 'boq_quantity' ? m.planning_qty : m.allocated_boq_qty,
                    is_primary: i === 0 || !!m.is_primary,
                    remarks: m.remarks || null,
                }));
        }
        delete payload.boq_item_id;

        return payload;
    })[task.value ? 'put' : 'post'](url, {
        preserveScroll: true,
        onSuccess: () => (drawer.value = null),
        onError: (errors) => {
            // Surface nested maps.* validation on the mapping form too.
            Object.keys(errors || {}).forEach((key) => {
                if (key === 'maps' || key.startsWith('maps.')) {
                    mapForm.setError(key, errors[key]);
                }
            });
        },
    });
}

const statusProcessing = ref(false);
function changeStatus(status) {
    statusProcessing.value = true;
    router.patch(route('projects.planning.tasks.status', [props.project.id, task.value.id]), { status }, {
        preserveScroll: true,
        onFinish: () => (statusProcessing.value = false),
    });
}
const statusLabel = (value) => props.options.statuses.find((s) => s.value === value)?.label ?? value;

const mapForm = useForm({ maps: [] });
const selectedBoqItem = computed(() => {
    const id = mapForm.maps[0]?.boq_item_id;
    return (props.options.boq_items || []).find((i) => i.value === id) || null;
});
const activeMapRows = computed(() => (mapForm.maps || []).filter((m) => m.boq_item_id));
const hasBoqMappings = computed(() => activeMapRows.value.length > 0);
const hasNosMapping = computed(() => activeMapRows.value.some((row) => row.tracking_basis === 'nos'));
/** Total Qty locked only for BOQ-qty mappings; Nos seeds once then stays manually editable. */
const totalQtyDerivedFromBoqQty = computed(() => hasBoqMappings.value && ! hasNosMapping.value);
/** Prevents re-writing Total Qty after the first Nos seed in this drawer session. */
const plannedQtyNosSeeded = ref(false);
const activityUnitLabel = computed(() => {
    const selected = (props.options.units || []).find((u) => u.value === form.unit_id);

    return selected?.label || null;
});
const totalQtyFromMappings = computed(() => {
    if (! hasBoqMappings.value) {
        return null;
    }
    let total = 0;
    for (const row of activeMapRows.value) {
        const eq = hasNosMapping.value ? mappingEquivalentNos(row) : mappingEquivalentBoqQty(row);
        if (eq == null || ! Number.isFinite(eq)) {
            return null;
        }
        total += eq;
    }

    return Number(total.toFixed(4));
});
const mappingTotalBreakdown = computed(() => activeMapRows.value.map((row) => {
    const item = boqItemFor(row);
    const eq = hasNosMapping.value ? mappingEquivalentNos(row) : mappingEquivalentBoqQty(row);
    const unit = hasNosMapping.value ? 'Nos' : (item?.unit || '');

    return {
        label: item?.name || item?.label || 'BOQ item',
        qty: eq != null && Number.isFinite(eq) ? eq.toFixed(4) : '—',
        unit,
    };
}));

/** Own-activity mapped items (edit) merged into the unlinked catalog from the server. */
const drawerBoqExtras = ref([]);

function blankMap() {
    return {
        id: null,
        boq_item_id: null,
        tracking_basis: 'boq_quantity',
        mapping_method: 'conversion_based',
        planning_qty: '',
        allocated_boq_qty: '',
        is_primary: true,
        remarks: '',
    };
}
function loadMapsFromTask(t) {
    mapForm.clearErrors();
    drawerBoqExtras.value = (t?.boq_maps || [])
        .map((m) => m.item_option)
        .filter((opt) => opt?.value != null);
    mapForm.maps = (t?.boq_maps?.length
        ? t.boq_maps.map((m) => ({
            id: m.id,
            boq_item_id: m.boq_item_id,
            tracking_basis: m.tracking_basis || 'boq_quantity',
            mapping_method: m.mapping_method || 'conversion_based',
            planning_qty: m.planning_qty || '',
            allocated_boq_qty: m.allocated_boq_qty || '',
            // Snapshots of the saved allocation so preview can restore remaining capacity while editing.
            _saved_planning_qty: m.tracking_basis === 'nos' ? Number(m.planning_qty || 0) : Number(m.equivalent_nos || 0),
            _saved_allocated_boq_qty: Number(m.allocated_boq_qty || 0),
            is_primary: !!m.is_primary,
            remarks: m.remarks || '',
        }))
        : []);
    syncPlannedQtyFromMaps();
}

function boqCatalog() {
    const byId = new Map();
    for (const item of [...(props.options.boq_items || []), ...drawerBoqExtras.value]) {
        if (item?.value != null) {
            byId.set(item.value, item);
        }
    }

    return [...byId.values()];
}

function boqItemFor(row) {
    return boqCatalog().find((i) => i.value === row.boq_item_id) || null;
}

/** Per-row options: unlinked + own extras, excluding items selected on other mapping rows. */
function boqOptionsForRow(index) {
    const taken = new Set(
        (mapForm.maps || [])
            .map((m, i) => (i !== index && m.boq_item_id ? m.boq_item_id : null))
            .filter((id) => id != null),
    );

    return boqCatalog().filter((item) => ! taken.has(item.value) || mapForm.maps[index]?.boq_item_id === item.value);
}

function editableAvailableQty(row) {
    const item = boqItemFor(row);
    if (! item?.allocation) {
        return null;
    }
    const base = Number(item.allocation.available_boq_qty ?? 0);
    const saved = row.id ? Number(row._saved_allocated_boq_qty || 0) : 0;
    const value = base + saved;

    return Number.isFinite(value) ? Number(value.toFixed(4)) : null;
}

function editableAvailableNos(row) {
    const item = boqItemFor(row);
    if (! item?.allocation || item.allocation.available_nos == null) {
        return null;
    }
    const base = Number(item.allocation.available_nos ?? 0);
    const saved = row.id ? Number(row._saved_planning_qty || 0) : 0;
    const value = base + saved;

    return Number.isFinite(value) ? Number(value.toFixed(4)) : null;
}

function displayAvailableQty(row) {
    const qty = editableAvailableQty(row);
    const item = boqItemFor(row);
    if (qty == null) {
        return item?.allocation?.available_boq_qty ?? '—';
    }

    return qty.toFixed(4);
}

function displayAvailableNos(row) {
    const nos = editableAvailableNos(row);
    if (nos == null) {
        return boqItemFor(row)?.allocation?.available_nos ?? '—';
    }

    return nos.toFixed(4);
}

function mappingEquivalentBoqQty(row) {
    const item = boqItemFor(row);
    if (! item || ! row.planning_qty) {
        return null;
    }
    const qty = Number(row.planning_qty);
    if (! Number.isFinite(qty) || qty <= 0) {
        return null;
    }

    if (row.tracking_basis === 'boq_quantity') {
        return Number(qty.toFixed(4));
    }

    const factor = Number(item.allocation?.conversion_factor ?? 0);
    if (! Number.isFinite(factor) || factor <= 0) {
        return null;
    }
    const remNos = editableAvailableNos(row);
    const remBoq = editableAvailableQty(row);
    const sameQty = (a, b) => Number.isFinite(a) && Number.isFinite(b) && a.toFixed(4) === b.toFixed(4);
    if (remNos != null && remBoq != null && sameQty(qty, remNos)) {
        return Number(remBoq.toFixed(4));
    }

    return Number((qty * factor).toFixed(4));
}

function mappingEquivalentNos(row) {
    const item = boqItemFor(row);
    if (! item || ! row.planning_qty) {
        return null;
    }
    const qty = Number(row.planning_qty);
    if (! Number.isFinite(qty) || qty <= 0) {
        return null;
    }
    if (row.tracking_basis === 'nos') {
        return Number(qty.toFixed(4));
    }
    const factor = Number(item.allocation?.conversion_factor ?? 0);
    if (! Number.isFinite(factor) || factor <= 0) {
        return null;
    }
    const remNos = editableAvailableNos(row);
    const remBoq = editableAvailableQty(row);
    const sameQty = (a, b) => Number.isFinite(a) && Number.isFinite(b) && a.toFixed(4) === b.toFixed(4);
    if (remNos != null && remBoq != null && sameQty(qty, remBoq)) {
        return Number(remNos.toFixed(4));
    }

    return Number((qty / factor).toFixed(4));
}

function autofillAllocation(row) {
    const item = boqItemFor(row);
    if (! item) {
        return;
    }
    if (row.tracking_basis === 'nos' && ! nosMappingAvailable(item)) {
        row.tracking_basis = 'boq_quantity';
    }

    // Exclusive mapping: default to full BOQ Qty / Total Nos (item cannot be used elsewhere).
    if (row.tracking_basis === 'nos') {
        const totalNos = Number(item.effective_nos ?? item.measurement_nos ?? 0);
        row.planning_qty = Number.isFinite(totalNos) && totalNos > 0 ? totalNos.toFixed(4) : '';
        row.allocated_boq_qty = '';
    } else {
        const qty = Number(item.quantity ?? item.allocation?.boq_total_qty ?? 0);
        row.planning_qty = Number.isFinite(qty) && qty > 0 ? qty.toFixed(4) : '';
        row.allocated_boq_qty = row.planning_qty;
    }
}

function applyActivityUnitFromBoq(item) {
    if (! item?.unit_id) {
        return true;
    }
    if (! form.unit_id) {
        form.unit_id = item.unit_id;

        return true;
    }
    const selected = (props.options.units || []).find((u) => u.value === form.unit_id);
    const activityUnit = selected?.label || null;
    if (! activityUnit || ! item.unit) {
        return true;
    }
    const same = String(activityUnit).toLowerCase() === String(item.unit).toLowerCase();
    const nosPlanning = String(activityUnit).toLowerCase() === 'nos' && String(item.unit).toLowerCase() !== 'nos';
    if (! same && ! nosPlanning) {
        mapForm.setError(
            'maps',
            `This BOQ item uses ${item.unit} while this Planning Activity uses ${activityUnit}. BOQ items mapped to one activity must use a compatible unit.`,
        );

        return false;
    }

    return true;
}

function onBoqItemSelected(row) {
    mapForm.clearErrors('maps');
    const item = boqItemFor(row);
    if (! item) {
        row.planning_qty = '';
        row.allocated_boq_qty = '';
        syncPlannedQtyFromMaps();

        return;
    }
    if (! applyActivityUnitFromBoq(item)) {
        row.boq_item_id = null;
        row.planning_qty = '';
        row.allocated_boq_qty = '';
        syncPlannedQtyFromMaps();

        return;
    }
    const availableQty = editableAvailableQty(row);
    const availableNos = editableAvailableNos(row);
    if ((row.tracking_basis === 'nos' && (availableNos == null || availableNos <= 0))
        || (row.tracking_basis !== 'nos' && (availableQty == null || availableQty <= 0))) {
        mapForm.setError('maps', 'This BOQ item is fully allocated.');
    }
    autofillAllocation(row);
    syncPlannedQtyFromMaps();
}

function onMapBasisChange(row) {
    const item = boqItemFor(row);
    if (! item) {
        return;
    }
    if (row.tracking_basis === 'nos' && ! nosMappingAvailable(item)) {
        row.tracking_basis = 'boq_quantity';
    }
    // Selecting Nos allows one fresh Total Qty seed from Allocated Nos.
    if (row.tracking_basis === 'nos') {
        plannedQtyNosSeeded.value = false;
    }
    autofillAllocation(row);
    syncPlannedQtyFromMaps();
}

function removeMapRow(index) {
    mapForm.maps.splice(index, 1);
    if (! hasNosMapping.value) {
        plannedQtyNosSeeded.value = false;
    }
    syncPlannedQtyFromMaps();
}

function nosUnitId() {
    return (props.options.units || []).find((u) => String(u.label || '').toLowerCase() === 'nos')?.value ?? null;
}

/**
 * Map by Nos: seed Total Qty with Allocated Nos once, switch Unit to Nos, then leave Total Qty alone.
 * Editing Total Qty must not affect BOQ allocation rows.
 */
function seedTotalQtyFromNosOnce() {
    if (plannedQtyNosSeeded.value) {
        return;
    }
    const nosRows = activeMapRows.value.filter((row) => row.tracking_basis === 'nos');
    if (! nosRows.length) {
        return;
    }
    let total = 0;
    for (const row of nosRows) {
        const n = Number(row.planning_qty);
        if (Number.isFinite(n) && n > 0) {
            total += n;
        }
    }
    if (total <= 0) {
        return;
    }
    form.planned_qty = total.toFixed(4);
    const nosId = nosUnitId();
    if (nosId) {
        form.unit_id = nosId;
    }
    plannedQtyNosSeeded.value = true;
}

function syncPlannedQtyFromMaps() {
    if (! hasBoqMappings.value) {
        return;
    }
    // Nos: one-time Total Qty = Allocated Nos; stay editable; no further coupling.
    if (hasNosMapping.value) {
        seedTotalQtyFromNosOnce();

        return;
    }
    // BOQ Quantity mappings: keep derived Activity Total Qty preview.
    const total = totalQtyFromMappings.value;
    if (total != null) {
        form.planned_qty = total.toFixed(4);
    }
}

watch(
    () => (mapForm.maps || []).map((m) => `${m.boq_item_id}|${m.tracking_basis}|${m.planning_qty}`).join(';'),
    () => syncPlannedQtyFromMaps(),
);

/** Authoritative item text as shown in the BOQ item selector (label), else name. */
function boqItemCopyText(item) {
    if (!item) {
        return '';
    }

    return String(item.label || item.name || '').trim();
}

function qtyWithUnit(qty, unit) {
    if (qty == null || qty === '') {
        return '';
    }
    const q = String(qty).trim();
    const u = unit ? String(unit).trim() : '';

    return u ? `${q} ${u}` : q;
}

function boqQtyCopyText(item) {
    return qtyWithUnit(item?.quantity, item?.unit);
}

function availableQtyCopyText(item) {
    return qtyWithUnit(item?.allocation?.available_boq_qty, item?.unit);
}

function availableNosCopyText(item) {
    const nos = item?.allocation?.available_nos;
    if (nos == null || nos === '') {
        return '';
    }

    return String(nos).trim();
}

function itemPlusQtyCopyText(item) {
    const name = boqItemCopyText(item);
    const qty = boqQtyCopyText(item);
    if (!name || !qty) {
        return name || qty;
    }

    return `${name} — ${qty}`;
}

function boqDetailsCopyText(item) {
    if (!item) {
        return '';
    }
    const lines = [
        `BOQ Item: ${boqItemCopyText(item)}`,
        `BOQ: ${item.boq_label || '—'}`,
        `Qty: ${boqQtyCopyText(item) || '—'}`,
    ];

    return lines.join('\n');
}

function nosMappingAvailable(item) {
    return !!(item?.effective_nos || item?.measurement_nos || item?.allocation?.nos_mapping_available);
}
function previewEquivalent(row) {
    const item = (props.options.boq_items || []).find((i) => i.value === row.boq_item_id);
    if (!item?.allocation?.conversion_factor || !row.planning_qty) return null;
    const factor = Number(item.allocation.conversion_factor);
    const qty = Number(row.planning_qty);
    if (!Number.isFinite(factor) || !Number.isFinite(qty) || factor <= 0) return null;

    const alloc = item.allocation;
    // available_* deducts all saved maps; restore this row's saved claim while editing.
    const remNos = Number(alloc.available_nos ?? 0) + (row.id ? Number(row._saved_planning_qty || 0) : 0);
    const remBoq = Number(alloc.available_boq_qty ?? 0) + (row.id ? Number(row._saved_allocated_boq_qty || 0) : 0);

    const sameQty = (a, b) => Number.isFinite(a) && Number.isFinite(b) && a.toFixed(4) === b.toFixed(4);

    if (row.tracking_basis === 'nos') {
        // Full remaining Nos → exact remaining Cum (matches server snap).
        if (alloc.available_nos != null && sameQty(qty, remNos)) {
            return `${remBoq.toFixed(4)} ${item.unit || 'Cum'}`;
        }
        return `${(qty * factor).toFixed(4)} ${item.unit || 'Cum'}`;
    }

    // Full remaining Cum → exact remaining Nos.
    if (alloc.available_nos != null && sameQty(qty, remBoq)) {
        return `${remNos.toFixed(4)} Nos`;
    }
    return `${(qty / factor).toFixed(4)} Nos`;
}
const deleting = ref(null);
const deleteProcessing = ref(false);
function destroy() {
    deleteProcessing.value = true;
    router.delete(route('projects.planning.tasks.destroy', [props.project.id, deleting.value.id]), {
        preserveScroll: true,
        onSuccess: () => (drawer.value = null),
        onFinish: () => ((deleteProcessing.value = false), (deleting.value = null)),
    });
}

const summaryCards = computed(() => [
    { label: 'Heads', value: props.summary.heads ?? 0 },
    { label: 'Activities', value: props.summary.activities ?? 0 },
    { label: 'Not started', value: props.summary.not_started ?? 0 },
    { label: 'In progress', value: props.summary.in_progress ?? 0 },
    { label: 'On hold', value: props.summary.on_hold ?? 0 },
    { label: 'Completed', value: props.summary.completed ?? 0 },
    { label: 'Delayed', value: props.summary.delayed ?? 0 },
]);
</script>

<template>
    <ProjectLayout :project="project" active="planning" title="Planning">
        <PlanningNav :project-id="project.id" active="tasks" />

        <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
            <div v-for="card in summaryCards" :key="card.label" class="rounded-lg border border-line bg-white px-3 py-2">
                <p class="text-[11px] tracking-wide text-slate-500 uppercase">{{ card.label }}</p>
                <p class="mt-0.5 text-lg font-semibold tabular text-slate-900">{{ card.value }}</p>
            </div>
        </div>

        <AppCard :padded="false">
            <template #header>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Activity schedule</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Work Area / Head → Activities. Executed qty comes from approved DPRs. BOQ is not required.
                    </p>
                </div>
            </template>
            <template #actions>
                <ImportTemplateDownload v-if="can.import" module="planning.tasks" :project-id="project.id" size="sm" />
                <AppButton v-if="can.import" size="sm" variant="secondary" @click="showImport = true">Import</AppButton>
                <AppButton v-if="can.create" size="sm" variant="secondary" icon="plus" @click="openHead()">Add Head</AppButton>
                <AppButton v-if="can.create" size="sm" icon="plus" @click="openActivity()">Add Activity</AppButton>
            </template>

            <FilterBar
                :filters="{
                    search: filters.search ?? '',
                    status: filters.status ?? 'all',
                    head_id: filters.head_id ? Number(filters.head_id) : 'all',
                    assigned_to: filters.assigned_to ? Number(filters.assigned_to) : 'all',
                    on_hold: filters.on_hold ? '1' : '',
                    delayed: filters.delayed ? '1' : '',
                }"
                :selects="filterSelects"
                placeholder="Search activity or head…"
            />

            <EmptyState
                v-if="!tasks.length"
                icon="calendar"
                :title="filtered ? 'No matching activities' : 'No planning yet'"
                :description="filtered ? 'Try different filters.' : 'Add a Planning Head (e.g. PEB SHED), then activities under it.'"
            />

            <template v-else>
                <div class="hidden max-h-[70vh] overflow-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 z-10 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase shadow-sm">
                            <tr>
                                <th class="px-3 py-2 text-left" rowspan="2">Sr.</th>
                                <th class="px-3 py-2 text-left" rowspan="2">Activity</th>
                                <th class="px-3 py-2 text-left" rowspan="2">Unit</th>
                                <th class="border-b border-slate-200 px-3 py-1.5 text-center" colspan="2">Time schedule</th>
                                <th class="px-3 py-2 text-right" rowspan="2">Total qty</th>
                                <th class="px-3 py-2 text-right" rowspan="2">Exe. qty</th>
                                <th class="px-3 py-2 text-right" rowspan="2">Hold qty</th>
                                <th class="px-3 py-2 text-right" rowspan="2">Balance qty</th>
                                <th class="px-3 py-2 text-right" rowspan="2">% complete</th>
                                <th class="px-3 py-2 text-left" rowspan="2">Remarks</th>
                            </tr>
                            <tr>
                                <th class="px-3 py-1.5 text-left font-medium normal-case">Start date</th>
                                <th class="px-3 py-1.5 text-left font-medium normal-case">End date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="section in scheduleSections" :key="section.head.id">
                                <tr class="bg-slate-100/80">
                                    <td colspan="11" class="px-3 py-2">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <button type="button" class="text-left font-semibold text-slate-900 hover:underline" @click="openHead(section.head.id ? section.head : null)">
                                                <span class="font-mono text-xs text-slate-500">{{ section.head.wbs_code }}</span>
                                                — {{ section.head.name }}
                                                <template v-if="section.head.head_progress">
                                                    <span class="ml-2 font-normal text-slate-600">
                                                        Progress: {{ formatPercent(section.head.head_progress.progress_percent) }}
                                                    </span>
                                                    <StatusBadge
                                                        class="ml-2 align-middle"
                                                        :status="section.head.head_progress.status"
                                                        :label="section.head.head_progress.status_label"
                                                    />
                                                </template>
                                            </button>
                                            <AppButton
                                                v-if="can.create && section.head.id"
                                                size="sm"
                                                variant="secondary"
                                                icon="plus"
                                                @click="openActivity(null, section.head.id)"
                                            >Add activity</AppButton>
                                        </div>
                                    </td>
                                </tr>
                                <tr
                                    v-for="a in section.activities"
                                    :key="a.id"
                                    class="cursor-pointer border-t border-line hover:bg-slate-50"
                                    @click="openActivity(a)"
                                >
                                    <td class="px-3 py-2 tabular text-slate-500">{{ a.sr }}</td>
                                    <td class="px-3 py-2 font-medium text-slate-900">
                                        {{ a.name }}
                                        <StatusBadge class="ml-1.5 align-middle" :status="a.status" :label="a.status_label" />
                                        <div v-if="a.boq_map_summary" class="mt-0.5 text-[11px] font-normal text-slate-500">{{ a.boq_map_summary }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-slate-600">{{ a.unit ?? '—' }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-slate-600">{{ a.planned_start ? formatDate(a.planned_start) : '—' }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-slate-600">{{ a.planned_finish ? formatDate(a.planned_finish) : '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(a.planned_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular text-slate-700">{{ formatQty(a.completed_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(a.hold_quantity) }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatQty(a.balance_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ formatPercent(a.completion_percent) }}</td>
                                    <td class="max-w-[12rem] truncate px-3 py-2 text-xs text-slate-500" :title="a.description">{{ a.description || '—' }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <ul class="divide-y divide-line md:hidden">
                    <template v-for="section in scheduleSections" :key="`m-${section.head.id}`">
                        <li class="bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-900">
                            {{ section.head.wbs_code }} — {{ section.head.name }}
                            <span v-if="section.head.head_progress" class="mt-0.5 block text-xs font-normal text-slate-600">
                                {{ formatPercent(section.head.head_progress.progress_percent) }} · {{ section.head.head_progress.status_label }}
                            </span>
                        </li>
                        <li
                            v-for="a in section.activities"
                            :key="a.id"
                            class="px-4 py-3 active:bg-slate-50"
                            @click="openActivity(a)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-900">{{ a.sr }}. {{ a.name }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ a.unit ?? '—' }} ·
                                        <template v-if="a.planned_start">{{ formatDate(a.planned_start) }} – {{ formatDate(a.planned_finish) }}</template>
                                        <template v-else>No schedule</template>
                                    </p>
                                    <p class="mt-0.5 text-xs tabular text-slate-600">
                                        Exe {{ formatQty(a.completed_qty) }} / {{ formatQty(a.planned_qty) }} · Bal {{ formatQty(a.balance_qty) }} · {{ formatPercent(a.completion_percent) }}
                                    </p>
                                </div>
                                <StatusBadge :status="a.status" :label="a.status_label" />
                            </div>
                        </li>
                    </template>
                </ul>
            </template>
        </AppCard>

        <AppDrawer
            :show="!!drawer"
            :title="drawer?.mode === 'head' ? (task ? `Head ${task.wbs_code}` : 'Add Planning Head') : (task ? `Activity ${task.name}` : 'Add Activity')"
            @close="drawer = null"
        >
            <form id="task-form" class="space-y-4" @submit.prevent="save">
                <fieldset :disabled="!editable" class="space-y-4">
                    <template v-if="drawer?.mode === 'head' || isHeadForm">
                        <div class="grid grid-cols-3 gap-3">
                            <FormInput v-model="form.wbs_code" label="Code" uppercase maxlength="20" :error="form.errors.wbs_code" help="e.g. A" />
                            <FormInput v-model="form.name" label="Head name" required maxlength="255" class="col-span-2" :error="form.errors.name" help="e.g. PEB SHED" />
                        </div>
                        <FormInput v-model="form.description" label="Description" multiline :rows="2" :error="form.errors.description" />
                    </template>
                    <template v-else>
                        <SearchSelect v-model="form.parent_id" label="Planning head" placeholder="Select head…" :options="parentOptions" :error="form.errors.parent_id" />
                        <div class="grid grid-cols-3 gap-3">
                            <FormInput v-model="form.wbs_code" label="Code" uppercase maxlength="20" :error="form.errors.wbs_code" help="Auto if empty" />
                            <FormInput v-model="form.name" label="Activity" required maxlength="255" class="col-span-2" :error="form.errors.name" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <FormSelect v-model="form.unit_id" label="Unit" required :options="options.units" :error="form.errors.unit_id" />
                            <div>
                                <DecimalInput
                                    v-model="form.planned_qty"
                                    label="Total qty"
                                    :decimals="4"
                                    :disabled="totalQtyDerivedFromBoqQty"
                                    :error="form.errors.planned_qty"
                                    :help="totalQtyDerivedFromBoqQty
                                        ? 'Calculated from linked BOQ allocations.'
                                        : (hasNosMapping ? 'Seeded once from Allocated Nos — editable; does not change BOQ allocation.' : undefined)"
                                />
                                <details v-if="hasBoqMappings" class="mt-1">
                                    <summary class="cursor-pointer text-[11px] text-slate-500">
                                        {{ hasNosMapping ? 'BOQ equivalents' : 'Calculated from' }}
                                        {{ activeMapRows.length }} BOQ mapping{{ activeMapRows.length === 1 ? '' : 's' }}
                                    </summary>
                                    <ul class="mt-1 space-y-0.5 text-[11px] text-slate-600">
                                        <li v-for="(line, i) in mappingTotalBreakdown" :key="i" class="flex justify-between gap-2 tabular">
                                            <span class="truncate">{{ line.label }}</span>
                                            <span>{{ line.qty }} {{ line.unit }}</span>
                                        </li>
                                        <li class="flex justify-between gap-2 border-t border-slate-200 pt-0.5 font-medium tabular text-slate-800">
                                            <span>Total</span>
                                            <span>{{ totalQtyFromMappings != null ? totalQtyFromMappings.toFixed(4) : '—' }}</span>
                                        </li>
                                    </ul>
                                </details>
                            </div>
                            <DecimalInput v-model="form.daily_target_qty" label="Daily target qty" :decimals="4" :error="form.errors.daily_target_qty" help="Used as read-only Planned Target on DPR" />
                            <FormInput v-model="form.planned_start" type="date" label="Start date" required :error="form.errors.planned_start" />
                            <FormInput v-model="form.planned_finish" type="date" label="End date" required :error="form.errors.planned_finish" />
                            <DecimalInput v-model="form.hold_quantity" label="Hold qty" :decimals="4" :error="form.errors.hold_quantity" />
                            <FormSelect v-model="form.milestone_id" label="Milestone" placeholder="None" :options="options.milestones" :error="form.errors.milestone_id" />
                        </div>
                        <FormInput v-model="form.description" label="Remarks" multiline :rows="2" :error="form.errors.description" />
                        <p class="text-[11px] text-slate-400">Executed, balance and % complete are calculated from approved DPR progress. DPR Planned Target comes from Daily target qty. BOQ is optional.</p>
                    </template>
                </fieldset>
            </form>

            <template v-if="drawer?.mode === 'activity'">
                <section v-if="task" class="mt-5 rounded-lg border border-line p-3">
                    <h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Progress (from approved DPR)</h3>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <dt class="text-slate-500">Executed</dt><dd class="text-right tabular">{{ formatQty(task.completed_qty) }} {{ task.unit ?? '' }}</dd>
                        <dt class="text-slate-500">Balance</dt><dd class="text-right tabular">{{ formatQty(task.balance_qty) }}</dd>
                        <dt class="text-slate-500">Completion</dt><dd class="text-right tabular">{{ formatPercent(task.completion_percent) }}</dd>
                        <dt class="text-slate-500">Status</dt><dd class="text-right"><StatusBadge :status="task.status" :label="task.status_label" /></dd>
                        <dt class="text-slate-500">Actual start</dt><dd class="text-right">{{ formatDate(task.actual_start) }}</dd>
                        <dt class="text-slate-500">Actual finish</dt><dd class="text-right">{{ formatDate(task.actual_finish) }}</dd>
                    </dl>
                    <div v-if="can.update_progress" class="mt-2 flex flex-wrap gap-2">
                        <AppButton
                            v-for="next in options.transitions[task.status]"
                            :key="next"
                            size="sm"
                            variant="secondary"
                            :loading="statusProcessing"
                            @click="changeStatus(next)"
                        >→ {{ statusLabel(next) }}</AppButton>
                    </div>
                </section>

                <section v-if="editable" class="mt-5 space-y-3 rounded-lg border border-line p-3">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">BOQ Mapping (Optional)</h3>
                        <AppButton size="sm" variant="secondary" @click="mapForm.maps.push(blankMap())">Add BOQ link</AppButton>
                    </div>
                    <p class="text-[11px] text-slate-500">
                        Each BOQ item can link to only one Planning Activity. Select an unlinked item to auto-fill full BOQ Qty or Total Nos (NOB × Nos / Building). Multiple different BOQ items may still map to this activity.
                    </p>
                    <p v-if="mapForm.errors.maps || form.errors.maps" class="text-xs text-red-600">{{ mapForm.errors.maps || form.errors.maps }}</p>
                    <p v-if="!boqCatalog().length" class="text-xs text-slate-500">No unlinked BOQ items available.</p>
                    <div v-for="(row, index) in mapForm.maps" :key="index" class="space-y-2 rounded-md border border-slate-100 bg-slate-50/70 p-3">
                        <div class="flex flex-wrap items-center justify-end gap-1">
                            <CopyValueButton
                                v-if="boqItemFor(row)"
                                :value="boqDetailsCopyText(boqItemFor(row))"
                                title="Copy BOQ details"
                                label="Copy BOQ Details"
                                success-message="BOQ details copied"
                            />
                            <button type="button" class="text-xs text-rose-600" @click="removeMapRow(index)">Remove</button>
                        </div>
                        <div class="flex items-start justify-between gap-2">
                            <SearchSelect
                                v-model="row.boq_item_id"
                                label="BOQ item"
                                placeholder="Select BOQ item…"
                                :options="boqOptionsForRow(index)"
                                class="flex-1"
                                :error="mapForm.errors[`maps.${index}.boq_item_id`] || form.errors[`maps.${index}.boq_item_id`]"
                                @update:model-value="onBoqItemSelected(row)"
                            />
                        </div>
                        <div v-if="boqItemFor(row)" class="flex flex-wrap items-center gap-1">
                            <CopyValueButton
                                :value="boqItemCopyText(boqItemFor(row))"
                                title="Copy BOQ item"
                                label="Copy Item"
                                success-message="BOQ item copied"
                            />
                            <CopyValueButton
                                :value="itemPlusQtyCopyText(boqItemFor(row))"
                                title="Copy BOQ item and quantity"
                                label="Copy Item + Qty"
                                success-message="BOQ item and quantity copied"
                            />
                        </div>
                        <template v-if="boqItemFor(row)">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-600">
                                <dt>BOQ</dt><dd class="text-right">{{ boqItemFor(row)?.boq_label || '—' }}</dd>
                                <dt title="Number of Buildings">NOB</dt><dd class="text-right tabular">{{ boqItemFor(row)?.measurement_nob ?? 1 }}</dd>
                                <dt>Nos / Building</dt><dd class="text-right tabular">{{ boqItemFor(row)?.nos_per_building ?? '—' }}</dd>
                                <dt title="NOB × Nos / Building">Total Nos</dt><dd class="text-right tabular font-medium text-slate-800">{{ boqItemFor(row)?.effective_nos ?? boqItemFor(row)?.measurement_nos ?? '—' }}</dd>
                                <dt>BOQ Qty</dt>
                                <dd class="flex items-center justify-end gap-1 tabular">
                                    <span>{{ boqItemFor(row)?.quantity }} {{ boqItemFor(row)?.unit }}</span>
                                    <CopyValueButton
                                        :value="boqQtyCopyText(boqItemFor(row))"
                                        title="Copy BOQ quantity"
                                        success-message="BOQ quantity copied"
                                    />
                                </dd>
                                <dt>Available Nos</dt>
                                <dd class="flex items-center justify-end gap-1 tabular">
                                    <span>{{ displayAvailableNos(row) }}</span>
                                    <CopyValueButton
                                        :value="String(displayAvailableNos(row))"
                                        title="Copy available Nos"
                                        success-message="Available Nos copied"
                                    />
                                </dd>
                                <dt>Available Qty</dt>
                                <dd class="flex items-center justify-end gap-1 tabular">
                                    <span>{{ displayAvailableQty(row) }} {{ boqItemFor(row)?.unit }}</span>
                                    <CopyValueButton
                                        :value="String(displayAvailableQty(row))"
                                        title="Copy available quantity"
                                        success-message="Available quantity copied"
                                    />
                                </dd>
                                <dt>Conversion</dt><dd class="text-right tabular">{{ boqItemFor(row)?.allocation?.conversion_factor || '—' }} {{ boqItemFor(row)?.unit ? `${boqItemFor(row).unit}/No` : '' }}</dd>
                            </dl>
                            <div class="grid grid-cols-2 gap-2">
                                <FormSelect
                                    v-model="row.tracking_basis"
                                    label="Map by"
                                    :options="(options.tracking_bases || []).filter((b) => b.value !== 'nos' || nosMappingAvailable(boqItemFor(row)))"
                                    :error="mapForm.errors[`maps.${index}.tracking_basis`] || form.errors[`maps.${index}.tracking_basis`]"
                                    @update:model-value="onMapBasisChange(row)"
                                />
                                <DecimalInput
                                    v-model="row.planning_qty"
                                    :label="row.tracking_basis === 'nos' ? 'Allocated Nos' : 'Allocated BOQ Qty'"
                                    :decimals="4"
                                    :error="mapForm.errors[`maps.${index}.planning_qty`] || form.errors[`maps.${index}.planning_qty`] || mapForm.errors[`maps.${index}.allocated_boq_qty`] || form.errors[`maps.${index}.allocated_boq_qty`]"
                                />
                            </div>
                            <p v-if="previewEquivalent(row)" class="text-[11px] text-slate-500">Equivalent: {{ previewEquivalent(row) }}</p>
                            <FormInput v-model="row.remarks" label="Remarks" maxlength="500" :error="mapForm.errors[`maps.${index}.remarks`] || form.errors[`maps.${index}.remarks`]" />
                        </template>
                    </div>
                    <div v-if="!mapForm.maps.length" class="text-xs text-slate-500">No BOQ linked — Total Qty stays manual.</div>
                </section>
            </template>

            <template #footer>
                <AppButton v-if="task && can.delete" variant="ghost" class="mr-auto text-red-600" icon="trash" @click="deleting = task">Delete</AppButton>
                <AppButton variant="secondary" @click="drawer = null">Close</AppButton>
                <AppButton v-if="editable" type="submit" form="task-form" :loading="form.processing">{{ task ? 'Save Activity' : 'Save Activity' }}</AppButton>
            </template>
        </AppDrawer>

        <ConfirmDialog
            :show="!!deleting"
            :title="`Delete ${deleting?.wbs_code ?? ''} ${deleting?.name ?? ''}?`"
            message="Heads with activities cannot be deleted until activities are removed."
            confirm-label="Delete"
            :processing="deleteProcessing"
            @close="deleting = null"
            @confirm="destroy"
        />

        <UniversalImportModal
            :show="showImport"
            module="planning.tasks"
            :project-id="project.id"
            @close="showImport = false"
            @imported="() => ((showImport = false), router.reload())"
        />
    </ProjectLayout>
</template>
