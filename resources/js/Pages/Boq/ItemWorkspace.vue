<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import UniversalFileViewer from '@/Components/Files/UniversalFileViewer.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppTabs from '@/Components/UI/AppTabs.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    item: { type: Object, required: true },
    measurement: { type: Object, required: true },
    location_breakdown: { type: Array, default: () => [] },
    formula_types: { type: Array, required: true },
    circle_constant_modes: { type: Array, default: () => [] },
    quantity_sources: { type: Array, required: true },
    statuses: { type: Array, required: true },
    drawing_revisions: { type: Array, default: () => [] },
    cost_build_up: { type: Object, default: null },
    cost_variance: { type: Object, default: null },
    cost_component_types: { type: Array, default: () => [] },
    cost_rate_sources: { type: Array, default: () => [] },
    cost_calculation_bases: { type: Array, default: () => [] },
    cost_masters: { type: Object, default: null },
    material_norms: { type: Object, default: null },
    material_options: { type: Array, default: () => [] },
    item_procurement: { type: Array, default: null },
    bbs_workspace: { type: Object, default: null },
    rate_comparison: { type: Object, default: null },
    rate_comparison_gst_modes: { type: Array, default: () => [] },
    vendor_options: { type: Array, default: () => [] },
    planning_map: { type: Object, default: null },
    planning_task_options: { type: Array, default: () => [] },
    planning_mapping_methods: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    advanced_boq_calculations: { type: Boolean, default: false },
});

const advanced = computed(() => props.advanced_boq_calculations === true);

const tab = ref('details');

const tabs = computed(() => [
    { key: 'details', label: 'Details' },
    // Workbook-driven: measurement rows + material/labour cost components (not legacy Materials / Labour Rate).
    advanced.value && { key: 'measurements', label: 'Measurement & Costing' },
    advanced.value && props.can.view_costs && { key: 'cost', label: 'Cost Summary' },
    advanced.value && props.can.view_material_requirements && { key: 'materials', label: 'Material Norms' },
    advanced.value && props.can.view_bbs && { key: 'bbs', label: 'BBS / Steel' },
    advanced.value && props.can.view_costs && { key: 'rate_comparison', label: 'Rate Comparison' },
    advanced.value && { key: 'planning_map', label: 'Planning Mapping' },
    props.can.view_material_requirements && { key: 'procurement', label: 'Procurement' },
].filter(Boolean).map((t) => ({ ...t, href: undefined })));

const showDrawingViewer = ref(false);
const rejectBbsReason = ref('');

const sourceForm = useForm({
    quantity_source: props.item.quantity_source,
    takeoff_reference: props.item.takeoff_reference || '',
});

watch(() => props.item.quantity_source, (v) => { sourceForm.quantity_source = v; });

function blankLine() {
    return {
        location: '',
        description: '',
        formula_type: 'volume',
        nos: '1',
        length: '',
        width: '',
        height_depth: '',
        diameter: '',
        radius: '',
        manual_quantity: '',
        addition_quantity: '0',
        deduction_quantity: '0',
        unit_id: props.item.unit?.id ?? null,
        drawing_revision_id: null,
        remarks: '',
        sort_order: 0,
    };
}

const measureForm = useForm({
    title: props.measurement.title,
    drawing_revision_id: props.measurement.drawing_revision_id,
    circle_constant_mode: props.measurement.circle_constant_mode || 'mathematical_pi',
    circle_constant_value: props.measurement.circle_constant_value || '',
    lines: props.measurement.lines.length
        ? props.measurement.lines.map((l) => ({ ...l }))
        : [blankLine()],
});

watch(() => props.measurement, (m) => {
    measureForm.title = m.title;
    measureForm.drawing_revision_id = m.drawing_revision_id;
    measureForm.circle_constant_mode = m.circle_constant_mode || 'mathematical_pi';
    measureForm.circle_constant_value = m.circle_constant_value || '';
    measureForm.lines = m.lines.length ? m.lines.map((l) => ({ ...l })) : [blankLine()];
    measureForm.clearErrors();
}, { deep: true });

const rejectForm = useForm({ reason: '' });

function blankComponent() {
    return {
        id: null,
        component_type: 'material',
        source_id: null,
        description: '',
        calculation_basis: 'quantity_rate',
        consumption_qty: '1',
        consumption_unit: '',
        rate: '0',
        base_rate: '',
        factor: '',
        factor_reference: '',
        percent_value: '',
        amount_per_boq_unit: '',
        rate_library_id: null,
        wastage_percent: '0',
        rate_source: 'manual',
        derivation_trace: '',
    };
}

function basisKind(basis) {
    if (['percent_of_material_subtotal', 'percent_of_labour_subtotal', 'percent_of_subtotal'].includes(basis)) return 'percent';
    if (['base_rate_multiply_factor', 'base_rate_divide_factor'].includes(basis)) return 'derived';
    if (basis === 'fixed_per_boq_unit') return 'fixed';
    return 'quantity';
}

const costForm = useForm({
    components: props.cost_build_up?.components?.length
        ? props.cost_build_up.components.map((c) => ({ ...c }))
        : [],
});

watch(() => props.cost_build_up, (buildUp) => {
    if (!buildUp) return;
    costForm.components = buildUp.components?.length
        ? buildUp.components.map((c) => ({ ...c }))
        : [];
    costForm.clearErrors();
}, { deep: true });

const costSummary = computed(() => props.cost_build_up?.summary || null);
const costCategoryTotals = computed(() => {
    const t = props.cost_build_up?.category_totals;
    if (t) {
        return t;
    }
    const s = props.cost_build_up?.subtotals || {};
    return {
        material: s.material || '0',
        labour: s.labour || '0',
        equipment: s.equipment || '0',
        subcontract: s.subcontract || '0',
        other: s.other || '0',
        total: props.cost_build_up?.summary?.cost_rate || '0',
    };
});
const costCategoryCards = computed(() => ([
    { key: 'material', label: 'Material Cost (A)', value: costCategoryTotals.value.material },
    { key: 'labour', label: 'Labour Cost (B)', value: costCategoryTotals.value.labour },
    { key: 'equipment', label: 'Equipment (optional)', value: costCategoryTotals.value.equipment },
    { key: 'subcontract', label: 'Subcontract (optional)', value: costCategoryTotals.value.subcontract },
    { key: 'other', label: 'Other (optional)', value: costCategoryTotals.value.other },
]));
const costLocked = computed(() => !!props.cost_build_up?.locked || !props.can.manage_cost);

function blankNorm() {
    return {
        id: null,
        material_id: null,
        consumption_qty: '1',
        consumption_unit: '',
        wastage_percent: '0',
        notes: '',
    };
}

const normForm = useForm({
    norms: props.material_norms?.norms?.length
        ? props.material_norms.norms.map((n) => ({
            id: n.id,
            material_id: n.material_id,
            consumption_qty: n.consumption_qty,
            consumption_unit: n.consumption_unit,
            wastage_percent: n.wastage_percent,
            notes: n.notes || '',
            base_requirement: n.base_requirement,
            wastage_qty: n.wastage_qty,
            required_qty: n.required_qty,
        }))
        : [],
});

watch(() => props.material_norms, (payload) => {
    if (!payload) return;
    normForm.norms = payload.norms?.length
        ? payload.norms.map((n) => ({
            id: n.id,
            material_id: n.material_id,
            consumption_qty: n.consumption_qty,
            consumption_unit: n.consumption_unit,
            wastage_percent: n.wastage_percent,
            notes: n.notes || '',
            base_requirement: n.base_requirement,
            wastage_qty: n.wastage_qty,
            required_qty: n.required_qty,
        }))
        : [];
    normForm.clearErrors();
}, { deep: true });

const normsLocked = computed(() => !!props.material_norms?.locked || !props.can.manage_material_norms);

watch(tab, (next) => {
    if (next === 'cost' && advanced.value && props.can.view_costs && !props.cost_build_up) {
        router.get(
            route('projects.boqs.items.workspace', [props.project.id, props.boq.id, props.item.id]),
            { cost: 1 },
            { preserveState: true, preserveScroll: true, only: ['cost_build_up', 'cost_variance', 'cost_masters', 'cost_component_types', 'cost_rate_sources', 'cost_calculation_bases', 'can', 'item'] },
        );
    }
    if (next === 'materials' && advanced.value && props.can.view_material_requirements && !props.material_norms) {
        router.get(
            route('projects.boqs.items.workspace', [props.project.id, props.boq.id, props.item.id]),
            { materials: 1 },
            { preserveState: true, preserveScroll: true, only: ['material_norms', 'material_options', 'can', 'item'] },
        );
    }
    if (next === 'procurement' && props.can.view_material_requirements && !props.item_procurement) {
        router.get(
            route('projects.boqs.items.workspace', [props.project.id, props.boq.id, props.item.id]),
            { procurement: 1 },
            { preserveState: true, preserveScroll: true, only: ['item_procurement', 'can'] },
        );
    }
    if (next === 'bbs' && advanced.value && props.can.view_bbs && !props.bbs_workspace) {
        router.get(
            route('projects.boqs.items.workspace', [props.project.id, props.boq.id, props.item.id]),
            { bbs: 1 },
            { preserveState: true, preserveScroll: true, only: ['bbs_workspace', 'material_options', 'drawing_revisions', 'can', 'item'] },
        );
    }
    if (next === 'rate_comparison' && advanced.value && props.can.view_costs && !props.rate_comparison) {
        router.get(
            route('projects.boqs.items.workspace', [props.project.id, props.boq.id, props.item.id]),
            { rate_comparison: 1 },
            { preserveState: true, preserveScroll: true, only: ['rate_comparison', 'rate_comparison_gst_modes', 'vendor_options', 'can', 'item'] },
        );
    }
    if (next === 'planning_map' && advanced.value && !props.planning_map) {
        router.get(
            route('projects.boqs.items.workspace', [props.project.id, props.boq.id, props.item.id]),
            { planning_map: 1 },
            { preserveState: true, preserveScroll: true, only: ['planning_map', 'planning_task_options', 'planning_mapping_methods', 'can', 'item'] },
        );
    }
});

function blankBbsLine() {
    return {
        structure_location: '',
        bar_mark: '',
        description: '',
        diameter_mm: 16,
        number_of_members: 1,
        bars_per_member: 1,
        count_mode: 'manual',
        distribution_length: '',
        start_cover: '',
        end_cover: '',
        spacing: '',
        include_end_bar: true,
        faces_layers_multiplier: 1,
        bar_count_rounding: '',
        shape_code: 'straight',
        a_length: '',
        b_length: '',
        c_length: '',
        d_length: '',
        e_length: '',
        hook_length: '0',
        bend_deduction: '0',
        lap_mode: 'manual',
        lap_diameter_factor: '',
        lap_length: '',
        lap_count_per_bar: '',
        remarks: '',
    };
}

function blankAdjustment() {
    return { diameter_mm: null, material_id: null, adjustment_qty_kg: '', reason: '', reference: '' };
}

const bbsForm = useForm({
    title: '',
    description: '',
    drawing_revision_id: null,
    wastage_percent: '0',
    unit_weight_method: 'formula_d2_162',
    steel_profile_id: null,
    bar_count_rounding: 'ceil',
    lines: [blankBbsLine()],
    maps: [],
    adjustments: [],
});

const selectedRecipeId = ref(null);

const bbsLocked = computed(() => {
    const st = props.bbs_workspace?.bbs?.status;
    return !props.can.manage_bbs || st === 'submitted' || st === 'approved';
});

watch(() => props.bbs_workspace, (ws) => {
    if (!ws?.bbs) return;
    const b = ws.bbs;
    bbsForm.title = b.title || '';
    bbsForm.description = b.description || '';
    bbsForm.drawing_revision_id = b.drawing_revision_id;
    bbsForm.wastage_percent = b.wastage_percent || '0';
    bbsForm.unit_weight_method = b.unit_weight_method || 'formula_d2_162';
    bbsForm.steel_profile_id = b.steel_profile_id ?? null;
    bbsForm.bar_count_rounding = b.bar_count_rounding || 'ceil';
    bbsForm.lines = (b.lines || []).length
        ? b.lines.map((l) => ({
            structure_location: l.structure_location || '',
            bar_mark: l.bar_mark || '',
            description: l.description || '',
            diameter_mm: l.diameter_mm,
            number_of_members: l.number_of_members,
            bars_per_member: l.bars_per_member,
            count_mode: l.count_mode || 'manual',
            distribution_length: l.distribution_length ?? '',
            start_cover: l.start_cover ?? '',
            end_cover: l.end_cover ?? '',
            spacing: l.spacing ?? '',
            include_end_bar: l.include_end_bar ?? true,
            faces_layers_multiplier: l.faces_layers_multiplier ?? 1,
            bar_count_rounding: l.bar_count_rounding ?? '',
            shape_code: l.shape_code,
            a_length: l.a_length ?? '',
            b_length: l.b_length ?? '',
            c_length: l.c_length ?? '',
            d_length: l.d_length ?? '',
            e_length: l.e_length ?? '',
            hook_length: l.hook_length ?? '0',
            bend_deduction: l.bend_deduction ?? '0',
            lap_mode: l.lap_mode || 'manual',
            lap_diameter_factor: l.lap_diameter_factor ?? '',
            lap_length: l.lap_length ?? '',
            lap_count_per_bar: l.lap_count_per_bar ?? '',
            remarks: l.remarks || '',
            // display-only from server
            total_bars: l.total_bars,
            cutting_length: l.cutting_length,
            total_length: l.total_length,
            unit_weight: l.unit_weight,
            theoretical_weight: l.theoretical_weight,
        }))
        : [blankBbsLine()];
    bbsForm.adjustments = (b.adjustments || []).length
        ? b.adjustments.map((a) => ({
            diameter_mm: a.diameter_mm,
            material_id: a.material_id,
            adjustment_qty_kg: a.adjustment_qty_kg,
            reason: a.reason,
            reference: a.reference || '',
        }))
        : [];
    bbsForm.maps = (b.diameter_summary || []).map((d) => ({
        diameter_mm: d.diameter_mm,
        material_id: d.material_id ?? null,
    }));
    bbsForm.clearErrors();
}, { deep: true, immediate: true });

function shapeDims(code) {
    if (code === 'straight') return { a: true };
    if (code === 'l_bar' || code === 'u_bar' || code === 'stirrup') return { a: true, b: true };
    if (code === 'cranked') return { a: true, b: true, c: true, d: true };
    return { a: true, b: true, c: true, d: true, e: true };
}

function addBbsLine() {
    bbsForm.lines.push(blankBbsLine());
}

function duplicateBbsLine(index) {
    const src = bbsForm.lines[index];
    bbsForm.lines.splice(index + 1, 0, {
        ...blankBbsLine(),
        diameter_mm: src.diameter_mm,
        shape_code: src.shape_code,
        a_length: src.a_length,
        b_length: src.b_length,
        c_length: src.c_length,
        d_length: src.d_length,
        e_length: src.e_length,
        hook_length: src.hook_length,
        bend_deduction: src.bend_deduction,
        bars_per_member: src.bars_per_member,
    });
}

function removeBbsLine(index) {
    if (bbsForm.lines.length === 1) {
        bbsForm.lines = [blankBbsLine()];
        return;
    }
    bbsForm.lines.splice(index, 1);
}

function moveBbsLine(index, dir) {
    const next = index + dir;
    if (next < 0 || next >= bbsForm.lines.length) return;
    const tmp = bbsForm.lines[index];
    bbsForm.lines[index] = bbsForm.lines[next];
    bbsForm.lines[next] = tmp;
}

function saveBbs() {
    const id = props.bbs_workspace?.bbs?.id;
    if (!id) return;
    bbsForm.put(route('projects.boqs.items.bbs.save', [props.project.id, props.boq.id, props.item.id, id]), {
        preserveScroll: true,
    });
}

function saveBbsMaps() {
    const id = props.bbs_workspace?.bbs?.id;
    if (!id) return;
    router.put(route('projects.boqs.items.bbs.maps', [props.project.id, props.boq.id, props.item.id, id]), {
        maps: bbsForm.maps,
    }, { preserveScroll: true });
}

function enableBbs() {
    router.post(route('projects.boqs.items.bbs.enable', [props.project.id, props.boq.id, props.item.id]));
}

function submitBbs() {
    const id = props.bbs_workspace?.bbs?.id;
    if (!id) return;
    router.post(route('projects.boqs.items.bbs.submit', [props.project.id, props.boq.id, props.item.id, id]));
}

function approveBbs() {
    const id = props.bbs_workspace?.bbs?.id;
    if (!id) return;
    router.post(route('projects.boqs.items.bbs.approve', [props.project.id, props.boq.id, props.item.id, id]));
}

function rejectBbs() {
    const id = props.bbs_workspace?.bbs?.id;
    if (!id || !rejectBbsReason.value.trim()) return;
    router.post(route('projects.boqs.items.bbs.reject', [props.project.id, props.boq.id, props.item.id, id]), {
        reason: rejectBbsReason.value,
    });
}

function importBbs(e) {
    const file = e.target.files?.[0];
    const id = props.bbs_workspace?.bbs?.id;
    if (!file || !id) return;
    const fd = new FormData();
    fd.append('file', file);
    router.post(route('projects.boqs.items.bbs.import', [props.project.id, props.boq.id, props.item.id, id]), fd, {
        forceFormData: true,
        onFinish: () => { e.target.value = ''; },
    });
}

function addLine() {
    measureForm.lines.push({ ...blankLine(), sort_order: measureForm.lines.length });
}

function duplicateLine(index) {
    const copy = { ...measureForm.lines[index], id: undefined };
    measureForm.lines.splice(index + 1, 0, copy);
}

function removeLine(index) {
    if (measureForm.lines.length === 1) {
        measureForm.lines = [blankLine()];
        return;
    }
    measureForm.lines.splice(index, 1);
}

function addComponent() {
    costForm.components.push(blankComponent());
}

function duplicateComponent(index) {
    const copy = { ...costForm.components[index], id: null };
    costForm.components.splice(index + 1, 0, copy);
}

function removeComponent(index) {
    costForm.components.splice(index, 1);
}

function moveComponent(index, dir) {
    const target = index + dir;
    if (target < 0 || target >= costForm.components.length) return;
    const rows = [...costForm.components];
    const [row] = rows.splice(index, 1);
    rows.splice(target, 0, row);
    costForm.components = rows;
}

function masterOptions(type) {
    if (!props.cost_masters) return [];
    if (type === 'material') return props.cost_masters.materials || [];
    if (type === 'labour') return props.cost_masters.labour_trades || [];
    if (type === 'equipment') return props.cost_masters.equipment_types || [];
    return [];
}

function rateLibraryOptions(row) {
    const all = props.cost_masters?.rate_library || [];
    if (!row?.component_type) return all;
    return all.filter((e) => e.entry_type === row.component_type || e.entry_type === 'other');
}

function onTypeChange(row) {
    row.source_id = null;
    row.description = '';
    row.consumption_unit = '';
    row.rate_source = 'manual';
}

async function onSourceChange(row) {
    const options = masterOptions(row.component_type);
    const selected = options.find((o) => o.value === row.source_id);
    if (!selected) return;
    row.description = selected.label;
    if (selected.unit) row.consumption_unit = selected.unit;
    if (row.rate_source === 'manual' && selected.rate) {
        row.rate = selected.rate;
        if (row.component_type === 'material') row.rate_source = 'material_master';
        if (row.component_type === 'labour') row.rate_source = 'labour_master';
        if (row.component_type === 'equipment') row.rate_source = 'equipment_master';
    }
}

async function applyRateSource(row) {
    const sourceId = row.rate_source === 'rate_library' ? (row.rate_library_id || row.source_id) : row.source_id;
    if (!sourceId || row.rate_source === 'manual') return;
    try {
        const url = route('projects.boqs.items.cost-build-up.suggest-rate', [props.project.id, props.boq.id, props.item.id]);
        const params = new URLSearchParams({
            component_type: row.component_type,
            rate_source: row.rate_source,
            source_id: String(sourceId),
        });
        if (row.rate_source === 'rate_library' && row.rate_library_id) {
            params.set('rate_library_id', String(row.rate_library_id));
        }
        const res = await fetch(`${url}?${params.toString()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data.rate != null) row.rate = data.rate;
        if (data.unit) row.consumption_unit = data.unit;
        if (data.description) row.description = data.description;
    } catch {
        // Keep manual values if suggestion fails.
    }
}

function saveSource() {
    sourceForm.put(route('projects.boqs.items.quantity-source', [props.project.id, props.boq.id, props.item.id]), {
        preserveScroll: true,
    });
}

function saveMeasurements() {
    measureForm.transform((data) => ({
        ...data,
        lines: data.lines.map((line, i) => ({ ...line, sort_order: i })),
    })).put(route('projects.boqs.items.measurements.save', [props.project.id, props.boq.id, props.item.id]), {
        preserveScroll: true,
    });
}

function saveCost() {
    costForm.put(route('projects.boqs.items.cost-build-up.save', [props.project.id, props.boq.id, props.item.id]), {
        preserveScroll: true,
    });
}

function blankRateQuote() {
    return {
        id: null,
        vendor_id: null,
        vendor_name: '',
        quoted_rate: '',
        gst_mode: 'inclusive',
        gst_percent: '',
        remarks: '',
    };
}

const rateCompareForm = useForm({
    quotes: props.rate_comparison?.quotes?.length
        ? props.rate_comparison.quotes.map((q) => ({
            id: q.id,
            vendor_id: q.vendor_id,
            vendor_name: q.vendor_name,
            quoted_rate: q.quoted_rate,
            gst_mode: q.gst_mode || 'inclusive',
            gst_percent: q.gst_percent || '',
            remarks: q.remarks || '',
            effective_rate: q.effective_rate,
            variance_vs_internal: q.variance_vs_internal,
            variance_vs_internal_percent: q.variance_vs_internal_percent,
            variance_vs_approved: q.variance_vs_approved,
            variance_vs_approved_percent: q.variance_vs_approved_percent,
            is_lowest: q.is_lowest,
        }))
        : [],
});

watch(() => props.rate_comparison, (payload) => {
    if (!payload) return;
    rateCompareForm.quotes = payload.quotes?.length
        ? payload.quotes.map((q) => ({
            id: q.id,
            vendor_id: q.vendor_id,
            vendor_name: q.vendor_name,
            quoted_rate: q.quoted_rate,
            gst_mode: q.gst_mode || 'inclusive',
            gst_percent: q.gst_percent || '',
            remarks: q.remarks || '',
            effective_rate: q.effective_rate,
            variance_vs_internal: q.variance_vs_internal,
            variance_vs_internal_percent: q.variance_vs_internal_percent,
            variance_vs_approved: q.variance_vs_approved,
            variance_vs_approved_percent: q.variance_vs_approved_percent,
            is_lowest: q.is_lowest,
        }))
        : [];
    rateCompareForm.clearErrors();
}, { deep: true });

const rateCompareLocked = computed(() => !!props.rate_comparison?.locked || !props.can.manage_cost);

function onRateVendor(row) {
    const selected = (props.vendor_options || []).find((o) => o.value === row.vendor_id);
    if (selected) {
        row.vendor_name = selected.label.includes(' — ')
            ? selected.label.split(' — ').slice(1).join(' — ')
            : selected.label;
    }
}

function addRateQuote() {
    rateCompareForm.quotes.push(blankRateQuote());
}

function removeRateQuote(index) {
    rateCompareForm.quotes.splice(index, 1);
}

function saveRateComparison() {
    rateCompareForm.put(route('projects.boqs.items.rate-comparison.save', [props.project.id, props.boq.id, props.item.id]), {
        preserveScroll: true,
    });
}

function blankPlanningMap() {
    const snap = props.planning_map?.boq_item;
    return {
        id: null,
        project_task_id: null,
        mapping_method: 'conversion_based',
        tracking_basis: snap?.nos_mapping_available ? 'nos' : 'boq_quantity',
        allocated_boq_qty: '',
        boq_unit_symbol: snap?.unit_symbol || props.item.unit?.symbol || '',
        planning_qty: '',
        planning_unit_symbol: snap?.nos_mapping_available ? 'Nos' : (snap?.unit_symbol || ''),
        conversion_factor: snap?.conversion_factor || '',
        remarks: '',
    };
}

const planningMapForm = useForm({
    maps: props.planning_map?.maps?.length
        ? props.planning_map.maps.map((m) => ({ ...m, conversion_factor: m.conversion_factor || '' }))
        : [],
});

watch(() => props.planning_map, (payload) => {
    if (!payload) return;
    planningMapForm.maps = payload.maps?.length
        ? payload.maps.map((m) => ({ ...m, conversion_factor: m.conversion_factor || '' }))
        : [];
    planningMapForm.clearErrors();
}, { deep: true });

const planningMapLocked = computed(() => !!props.planning_map?.locked || !props.can.manage_planning_map);

function onTrackingBasis(row) {
    const snap = props.planning_map?.boq_item;
    if (!snap) {
        return;
    }
    if (row.tracking_basis === 'nos' && !snap.nos_mapping_available) {
        row.tracking_basis = 'boq_quantity';
    }
    row.boq_unit_symbol = snap.unit_symbol || row.boq_unit_symbol;
    row.conversion_factor = snap.conversion_factor || '';
    // Manual allocation — do not auto-fill full BOQ Nos/Qty.
    row.planning_qty = '';
    row.allocated_boq_qty = '';
    row.planning_unit_symbol = row.tracking_basis === 'nos' ? 'Nos' : (snap.unit_symbol || '');
}

function onPlanningTask(row) {
    // Keep allocated qty as user-entered; do not overwrite with full task plan.
}

function addPlanningMap() {
    planningMapForm.maps.push(blankPlanningMap());
}

function removePlanningMap(index) {
    planningMapForm.maps.splice(index, 1);
}

function savePlanningMap() {
    planningMapForm.transform((data) => ({
        maps: (data.maps || []).map((m) => ({
            ...m,
            allocated_boq_qty: m.tracking_basis === 'boq_quantity' ? (m.allocated_boq_qty || m.planning_qty) : m.allocated_boq_qty,
            conversion_factor: undefined, // server-derived only
        })),
    })).put(route('projects.boqs.items.planning-map.save', [props.project.id, props.boq.id, props.item.id]), {
        preserveScroll: true,
    });
}

function addNorm() {
    normForm.norms.push(blankNorm());
}

function removeNorm(index) {
    normForm.norms.splice(index, 1);
}

function onNormMaterial(row) {
    const selected = (props.material_options || []).find((o) => o.value === row.material_id);
    if (!selected) return;
    if (selected.unit) row.consumption_unit = selected.unit;
}

function saveNorms() {
    normForm.put(route('projects.boqs.items.material-norms.save', [props.project.id, props.boq.id, props.item.id]), {
        preserveScroll: true,
    });
}

function importNormsFromCost() {
    const ids = (props.material_norms?.cost_materials || []).map((c) => c.cost_component_id).filter(Boolean);
    if (!ids.length) return;
    router.post(route('projects.boqs.items.material-norms.from-cost', [props.project.id, props.boq.id, props.item.id]), {
        cost_component_ids: ids,
    }, { preserveScroll: true });
}

function applyRecipe() {
    if (!selectedRecipeId.value) return;
    router.post(route('projects.boqs.items.material-norms.apply-recipe', [props.project.id, props.boq.id, props.item.id]), {
        recipe_id: selectedRecipeId.value,
    }, { preserveScroll: true });
}

function addAdjustment() {
    bbsForm.adjustments.push(blankAdjustment());
}

function removeAdjustment(index) {
    bbsForm.adjustments.splice(index, 1);
}

function submitSheet() {
    router.post(route('projects.boqs.items.measurements.submit', [props.project.id, props.boq.id, props.item.id, props.measurement.id]), {}, { preserveScroll: true });
}

function approveSheet() {
    router.post(route('projects.boqs.items.measurements.approve', [props.project.id, props.boq.id, props.item.id, props.measurement.id]), {}, { preserveScroll: true });
}

function rejectSheet() {
    rejectForm.post(route('projects.boqs.items.measurements.reject', [props.project.id, props.boq.id, props.item.id, props.measurement.id]), {
        preserveScroll: true,
        onSuccess: () => rejectForm.reset(),
    });
}

function showDims(type) {
    return {
        nos: ['number', 'length', 'area', 'volume', 'circle_area', 'circular_volume'].includes(type),
        length: ['length', 'area', 'volume'].includes(type),
        width: ['area', 'volume'].includes(type),
        height: ['volume', 'circular_volume'].includes(type),
        diameter: ['circle_area', 'circular_volume'].includes(type),
        radius: ['circle_area', 'circular_volume'].includes(type),
        manual: type === 'manual',
    };
}

const unitLabel = computed(() => props.item.unit?.symbol || props.item.unit?.code || '');

function confirmRefreshRates(scope) {
    if (!props.can.manage_cost || costLocked.value) return;
    const label = scope === 'labour' ? 'labour' : 'material';
    if (!window.confirm(`Refresh current ${label} rates from masters for this editable revision? This does not change prior revisions.`)) {
        return;
    }
    router.post(route('projects.boqs.items.cost-build-up.refresh-rates.apply', [props.project.id, props.boq.id, props.item.id]), {
        scope,
        confirm: 1,
    }, { preserveScroll: true });
}
</script>

<template>
    <ProjectLayout :project="project" :title="`${item.item_code || item.name} · BOQ`" active="boq">
        <div
            v-if="boq.cloned_from"
            class="mb-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950"
        >
            <p class="font-medium">{{ boq.cloned_from.label }}</p>
            <p class="mt-1 text-xs text-sky-800">
                <span v-if="boq.revision_reason">Reason: {{ boq.revision_reason }} · </span>
                <span v-if="boq.created_by_name">Created by {{ boq.created_by_name }} · </span>
                <span v-if="boq.created_at">{{ boq.created_at }}</span>
            </p>
        </div>
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link :href="route('projects.boqs.show', [project.id, boq.id])" class="text-xs font-medium text-slate-500 hover:text-slate-700">← Back to BOQ</Link>
                <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ item.item_code ? `${item.item_code} — ` : '' }}{{ item.name }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    BOQ {{ boq.boq_number }} v{{ boq.version }}
                    · Qty {{ formatQty(item.quantity) }} {{ unitLabel }}
                    · Source {{ quantity_sources.find((s) => s.value === item.quantity_source)?.label || item.quantity_source }}
                </p>
            </div>
            <StatusBadge :status="measurement.status" :label="measurement.status_label" />
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <AppCard>
                <p class="text-xs text-slate-500">BOQ Qty</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(item.quantity) }} {{ unitLabel }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Rate</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatRate(item.client_rate) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Amount</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatMoney(item.client_amount) }}</p>
            </AppCard>
            <AppCard v-if="advanced && can.view_costs">
                <p class="text-xs text-slate-500">Cost Rate</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatRate(item.cost_rate) }}</p>
            </AppCard>
            <AppCard v-if="advanced && can.view_costs">
                <p class="text-xs text-slate-500">Estimated Cost</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatMoney(item.cost_amount) }}</p>
            </AppCard>
            <AppCard v-if="advanced && can.view_costs && costSummary">
                <p class="text-xs text-slate-500">Margin</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatMoney(costSummary.estimated_margin) }}</p>
            </AppCard>
            <AppCard v-if="advanced && can.view_costs && costSummary">
                <p class="text-xs text-slate-500">Margin %</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ costSummary.margin_percent != null ? `${formatQty(costSummary.margin_percent)}%` : '—' }}</p>
            </AppCard>
        </div>

        <div class="mb-4">
            <AppTabs v-model="tab" :items="tabs.filter((t) => !t.disabled)" />
        </div>

        <AppCard v-if="tab === 'details'" title="Item details">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Section</dt><dd class="font-medium">{{ item.section?.name || '—' }}</dd></div>
                <div><dt class="text-slate-500">HSN/SAC</dt><dd class="font-medium">{{ item.hsn_sac || '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Description</dt><dd class="font-medium whitespace-pre-wrap">{{ item.description || '—' }}</dd></div>
            </dl>

            <form v-if="can.set_source" class="mt-6 grid gap-3 border-t border-line pt-4 sm:grid-cols-3" @submit.prevent="saveSource">
                <FormSelect v-model="sourceForm.quantity_source" label="Quantity source" :options="quantity_sources" />
                <FormInput
                    v-if="sourceForm.quantity_source === 'takeoff'"
                    v-model="sourceForm.takeoff_reference"
                    label="Takeoff reference"
                    help="Reserved for Tiwana Takeoff linkage."
                />
                <div class="flex items-end">
                    <AppButton type="submit" :loading="sourceForm.processing">Save source</AppButton>
                </div>
            </form>
            <p v-if="sourceForm.quantity_source === 'measurement'" class="mt-3 text-sm text-slate-600">
                BOQ quantity is driven by the approved Measurement Sheet. Grid quantity edits are ignored for this item.
            </p>
        </AppCard>

        <div v-else-if="tab === 'measurements'" class="space-y-4">
            <AppCard title="Workbook-driven Measurement &amp; Costing">
                <p class="text-sm text-slate-600">
                    Enter Nos / L / W / H measurement lines here. Material consumption and labour rates belong in
                    <button type="button" class="font-medium text-brand-700 hover:underline" @click="tab = 'cost'">Cost Summary</button>
                    as cost components — not the legacy Materials drawer or Labour Rate column.
                    Steel quantities come from <button type="button" class="font-medium text-brand-700 hover:underline" @click="tab = 'bbs'">BBS / Steel</button>.
                </p>
            </AppCard>
            <AppCard title="Measurement sheet">
                <form class="space-y-4" @submit.prevent="saveMeasurements">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <FormInput v-model="measureForm.title" label="Sheet title" :disabled="!can.edit" />
                        <FormSelect
                            v-model="measureForm.drawing_revision_id"
                            label="Drawing revision (optional)"
                            :options="[{ value: null, label: '— None —' }, ...drawing_revisions.map((d) => ({ value: d.id, label: d.label }))]"
                            :disabled="!can.edit"
                        />
                        <FormSelect
                            v-model="measureForm.circle_constant_mode"
                            label="Circle constant"
                            :options="circle_constant_modes"
                            :disabled="!can.edit"
                        />
                        <FormInput
                            v-if="measureForm.circle_constant_mode === 'custom'"
                            v-model="measureForm.circle_constant_value"
                            label="Custom constant"
                            :disabled="!can.edit"
                        />
                    </div>
                    <p v-if="measurement.calculation_profile_snapshot?.circle_constant_value" class="text-xs text-slate-500">
                        Snapshot: {{ measurement.calculation_profile_snapshot.circle_constant_mode }}
                        · coefficient {{ measurement.calculation_profile_snapshot.circle_constant_value }}
                        <span v-if="measurement.calculation_profile_snapshot.formula_version">
                            · {{ measurement.calculation_profile_snapshot.formula_version }}
                        </span>
                    </p>

                    <div class="overflow-x-auto rounded-xl border border-line">
                        <table class="min-w-[1100px] w-full text-left text-xs">
                            <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                                <tr>
                                    <th class="px-2 py-2">Location</th>
                                    <th class="px-2 py-2">Description</th>
                                    <th class="px-2 py-2">Formula</th>
                                    <th class="px-2 py-2">Nos</th>
                                    <th class="px-2 py-2">L</th>
                                    <th class="px-2 py-2">W</th>
                                    <th class="px-2 py-2">H/D</th>
                                    <th class="px-2 py-2">Ø / r</th>
                                    <th class="px-2 py-2">Manual</th>
                                    <th class="px-2 py-2">Add</th>
                                    <th class="px-2 py-2">Deduct</th>
                                    <th class="px-2 py-2 text-right">Base</th>
                                    <th class="px-2 py-2 text-right">Qty</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(line, index) in measureForm.lines" :key="index" class="border-t border-line align-top">
                                    <td class="px-1 py-1"><input v-model="line.location" class="w-28 rounded border-slate-200 px-1.5 py-1" :disabled="!can.edit" /></td>
                                    <td class="px-1 py-1"><input v-model="line.description" class="w-36 rounded border-slate-200 px-1.5 py-1" :disabled="!can.edit" /></td>
                                    <td class="px-1 py-1">
                                        <select v-model="line.formula_type" class="w-36 rounded border-slate-200 py-1 text-xs" :disabled="!can.edit">
                                            <option v-for="f in formula_types" :key="f.value" :value="f.value">{{ f.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1"><input v-model="line.nos" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit || !showDims(line.formula_type).nos" /></td>
                                    <td class="px-1 py-1"><input v-model="line.length" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit || !showDims(line.formula_type).length" /></td>
                                    <td class="px-1 py-1"><input v-model="line.width" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit || !showDims(line.formula_type).width" /></td>
                                    <td class="px-1 py-1"><input v-model="line.height_depth" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit || !showDims(line.formula_type).height" /></td>
                                    <td class="px-1 py-1">
                                        <input v-model="line.diameter" placeholder="Ø" class="mb-1 w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit || !showDims(line.formula_type).diameter" />
                                        <input v-model="line.radius" placeholder="r" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit || !showDims(line.formula_type).radius" />
                                    </td>
                                    <td class="px-1 py-1"><input v-model="line.manual_quantity" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit || !showDims(line.formula_type).manual" /></td>
                                    <td class="px-1 py-1"><input v-model="line.addition_quantity" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit" /></td>
                                    <td class="px-1 py-1"><input v-model="line.deduction_quantity" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="!can.edit" /></td>
                                    <td class="px-2 py-2 text-right tabular text-slate-600">{{ line.calculated_base ? formatQty(line.calculated_base) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-medium tabular">{{ line.calculated_quantity ? formatQty(line.calculated_quantity) : '—' }}</td>
                                    <td class="px-1 py-1 whitespace-nowrap">
                                        <button v-if="can.edit" type="button" class="text-slate-500 hover:text-slate-800" @click="duplicateLine(index)">Dup</button>
                                        <button v-if="can.edit" type="button" class="ml-1 text-rose-600" @click="removeLine(index)">Del</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="Object.keys(measureForm.errors).length" class="text-sm text-rose-700">
                        {{ Object.values(measureForm.errors)[0] }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton v-if="can.edit" type="button" variant="secondary" @click="addLine">Add row</AppButton>
                        <AppButton v-if="can.edit" type="submit" :loading="measureForm.processing">Save draft</AppButton>
                        <AppButton v-if="can.submit" type="button" variant="secondary" @click="submitSheet">Submit</AppButton>
                        <AppButton v-if="can.approve" type="button" @click="approveSheet">Approve</AppButton>
                        <span class="ml-auto text-sm font-semibold text-slate-800">Sheet total: {{ formatQty(measurement.total_quantity) }}</span>
                    </div>
                </form>

                <form v-if="can.reject" class="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4" @submit.prevent="rejectSheet">
                    <FormInput v-model="rejectForm.reason" label="Rejection reason" class="min-w-[16rem] flex-1" />
                    <AppButton type="submit" variant="danger" :loading="rejectForm.processing">Reject</AppButton>
                </form>
            </AppCard>

            <AppCard v-if="location_breakdown.length" title="Quantity by location">
                <ul class="divide-y divide-line text-sm">
                    <li v-for="row in location_breakdown" :key="row.location" class="flex justify-between py-2">
                        <span>{{ row.location }} <span class="text-slate-500">({{ row.lines }} line{{ row.lines === 1 ? '' : 's' }})</span></span>
                        <span class="font-medium tabular">{{ formatQty(row.quantity) }}</span>
                    </li>
                </ul>
            </AppCard>
        </div>

        <div v-else-if="tab === 'cost' && can.view_costs" class="space-y-4">
            <AppCard title="Cost Build-Up">
                <p v-if="costLocked" class="mb-3 text-sm text-amber-800">
                    {{ cost_build_up?.locked ? 'BOQ is approved — cost build-up is locked.' : 'You can view costs but cannot edit them.' }}
                </p>
                <p v-else class="mb-3 text-sm text-slate-600">Estimated cost per BOQ unit. Does not post stock, ledger, or procurement.</p>
                <div v-if="!costLocked && can.manage_cost" class="mb-3 flex flex-wrap gap-2">
                    <AppButton type="button" variant="secondary" @click="confirmRefreshRates('material')">Refresh material rates</AppButton>
                    <AppButton type="button" variant="secondary" @click="confirmRefreshRates('labour')">Refresh labour rates</AppButton>
                </div>

                <div v-if="!cost_build_up" class="py-8 text-center text-sm text-slate-500">Loading cost build-up…</div>

                <form v-else class="space-y-4" @submit.prevent="saveCost">
                    <div class="overflow-x-auto rounded-xl border border-line">
                        <table class="min-w-[1280px] w-full text-left text-xs">
                            <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                                <tr>
                                    <th class="px-2 py-2">Type</th>
                                    <th class="px-2 py-2">Basis</th>
                                    <th class="px-2 py-2">Component</th>
                                    <th class="px-2 py-2">Consumption</th>
                                    <th class="px-2 py-2">Unit</th>
                                    <th class="px-2 py-2">Rate</th>
                                    <th class="px-2 py-2">Wastage %</th>
                                    <th class="px-2 py-2 text-right">Cost/BOQ Unit</th>
                                    <th class="px-2 py-2">Rate Source</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in costForm.components" :key="index" class="border-t border-line align-top">
                                    <td class="px-1 py-1">
                                        <select v-model="row.component_type" class="w-28 rounded border-slate-200 py-1 text-xs" :disabled="costLocked" @change="onTypeChange(row)">
                                            <option v-for="t in cost_component_types" :key="t.value" :value="t.value">{{ t.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1">
                                        <select v-model="row.calculation_basis" class="w-36 rounded border-slate-200 py-1 text-xs" :disabled="costLocked">
                                            <option v-for="b in cost_calculation_bases" :key="b.value" :value="b.value">{{ b.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1">
                                        <select
                                            v-if="row.rate_source === 'rate_library'"
                                            v-model="row.rate_library_id"
                                            class="mb-1 w-44 rounded border-slate-200 py-1 text-xs"
                                            :disabled="costLocked"
                                            @change="applyRateSource(row)"
                                        >
                                            <option :value="null">— Rate library —</option>
                                            <option v-for="opt in rateLibraryOptions(row)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                        <select
                                            v-else-if="['material', 'labour', 'equipment'].includes(row.component_type)"
                                            v-model="row.source_id"
                                            class="mb-1 w-44 rounded border-slate-200 py-1 text-xs"
                                            :disabled="costLocked"
                                            @change="onSourceChange(row)"
                                        >
                                            <option :value="null">— Select —</option>
                                            <option v-for="opt in masterOptions(row.component_type)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                        <input v-model="row.description" class="w-44 rounded border-slate-200 px-1.5 py-1" :disabled="costLocked" placeholder="Description" />
                                        <div v-if="basisKind(row.calculation_basis) === 'derived'" class="mt-1 flex gap-1">
                                            <input v-model="row.base_rate" placeholder="Base" class="w-16 rounded border-slate-200 px-1 py-0.5 tabular" :disabled="costLocked" />
                                            <input v-model="row.factor" placeholder="Factor" class="w-16 rounded border-slate-200 px-1 py-0.5 tabular" :disabled="costLocked" />
                                            <input v-model="row.factor_reference" placeholder="Ref" class="w-16 rounded border-slate-200 px-1 py-0.5" :disabled="costLocked" />
                                        </div>
                                        <div v-else-if="basisKind(row.calculation_basis) === 'percent'" class="mt-1">
                                            <input v-model="row.percent_value" placeholder="%" class="w-20 rounded border-slate-200 px-1 py-0.5 tabular" :disabled="costLocked" />
                                        </div>
                                        <div v-else-if="basisKind(row.calculation_basis) === 'fixed'" class="mt-1">
                                            <input v-model="row.amount_per_boq_unit" placeholder="Fixed amt" class="w-24 rounded border-slate-200 px-1 py-0.5 tabular" :disabled="costLocked" />
                                        </div>
                                        <p v-if="row.derivation_trace" class="mt-1 text-[10px] text-slate-500">{{ row.derivation_trace }}</p>
                                    </td>
                                    <td class="px-1 py-1"><input v-model="row.consumption_qty" class="w-20 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="costLocked || basisKind(row.calculation_basis) === 'percent'" /></td>
                                    <td class="px-1 py-1"><input v-model="row.consumption_unit" class="w-16 rounded border-slate-200 px-1.5 py-1" :disabled="costLocked" /></td>
                                    <td class="px-1 py-1"><input v-model="row.rate" class="w-24 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="costLocked || basisKind(row.calculation_basis) !== 'quantity'" /></td>
                                    <td class="px-1 py-1"><input v-model="row.wastage_percent" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="costLocked" /></td>
                                    <td class="px-2 py-2 text-right tabular font-medium">{{ row.amount_per_boq_unit ? formatRate(row.amount_per_boq_unit) : '—' }}</td>
                                    <td class="px-1 py-1">
                                        <select v-model="row.rate_source" class="w-36 rounded border-slate-200 py-1 text-xs" :disabled="costLocked" @change="applyRateSource(row)">
                                            <option v-for="s in cost_rate_sources" :key="s.value" :value="s.value">{{ s.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1 whitespace-nowrap">
                                        <button v-if="!costLocked" type="button" class="text-slate-500 hover:text-slate-800" @click="moveComponent(index, -1)">↑</button>
                                        <button v-if="!costLocked" type="button" class="ml-1 text-slate-500 hover:text-slate-800" @click="moveComponent(index, 1)">↓</button>
                                        <button v-if="!costLocked" type="button" class="ml-1 text-slate-500 hover:text-slate-800" @click="duplicateComponent(index)">Dup</button>
                                        <button v-if="!costLocked" type="button" class="ml-1 text-rose-600" @click="removeComponent(index)">Del</button>
                                    </td>
                                </tr>
                                <tr v-if="!costForm.components.length">
                                    <td colspan="10" class="px-3 py-6 text-center text-slate-500">No cost components yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="Object.keys(costForm.errors).length" class="text-sm text-rose-700">
                        {{ Object.values(costForm.errors)[0] }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton v-if="!costLocked" type="button" variant="secondary" @click="addComponent">Add component</AppButton>
                        <AppButton v-if="!costLocked" type="submit" :loading="costForm.processing">Save</AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard v-if="cost_variance" title="Estimated vs Actual">
                <p class="mb-3 text-xs text-slate-500">{{ cost_variance.formula_note }}</p>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div>
                        <p class="text-[11px] uppercase text-slate-500">BOQ Qty</p>
                        <p class="mt-0.5 font-medium tabular">{{ formatQty(cost_variance.boq_qty) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase text-slate-500">Cost Rate</p>
                        <p class="mt-0.5 font-medium tabular">{{ formatRate(cost_variance.cost_rate) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase text-slate-500">Estimated</p>
                        <p class="mt-0.5 font-semibold tabular">{{ formatMoney(cost_variance.estimated_cost) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase text-slate-500">Actual</p>
                        <p class="mt-0.5 font-semibold tabular">{{ formatMoney(cost_variance.actual_cost) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase text-slate-500">Variance</p>
                        <p
                            class="mt-0.5 font-semibold tabular"
                            :class="cost_variance.status === 'over' ? 'text-rose-700' : (cost_variance.status === 'near' ? 'text-amber-700' : 'text-emerald-700')"
                        >
                            {{ formatMoney(cost_variance.variance) }}
                            <span v-if="cost_variance.variance_percent != null" class="block text-xs font-normal">{{ cost_variance.variance_percent }}%</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase text-slate-500">Utilization</p>
                        <p class="mt-0.5 font-medium tabular">{{ cost_variance.utilization_percent != null ? `${cost_variance.utilization_percent}%` : '—' }}</p>
                    </div>
                </div>
                <div class="mt-4 overflow-x-auto rounded-xl border border-line">
                    <table class="min-w-[520px] w-full text-left text-xs">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                            <tr>
                                <th class="px-2 py-2">Cost Head</th>
                                <th class="px-2 py-2 text-right">Estimated</th>
                                <th class="px-2 py-2 text-right">Actual</th>
                                <th class="px-2 py-2 text-right">Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in cost_variance.heads" :key="h.cost_head" class="border-t border-line">
                                <td class="px-2 py-2">{{ h.label }}</td>
                                <td class="px-2 py-2 text-right tabular">{{ formatMoney(h.estimated) }}</td>
                                <td class="px-2 py-2 text-right tabular">{{ formatMoney(h.actual) }}</td>
                                <td class="px-2 py-2 text-right tabular">{{ formatMoney(h.variance) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    Project report:
                    <Link :href="route('reports.project', [project.id, 'estimated-vs-actual-cost'])" class="font-medium text-brand-700 hover:underline">
                        Estimated vs Actual Cost →
                    </Link>
                </p>
            </AppCard>

            <div v-if="cost_build_up" class="space-y-3" data-testid="cost-category-summary">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div
                        v-for="card in costCategoryCards"
                        :key="card.key"
                        class="rounded-xl border border-line bg-slate-50/80 px-3 py-3"
                        :data-testid="`cost-category-${card.key}`"
                    >
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ card.label }}</p>
                        <p class="mt-1 text-lg font-semibold tabular text-slate-900">{{ formatMoney(card.value || '0') }}</p>
                    </div>
                </div>
                <div class="rounded-xl border border-line bg-white px-3 py-3 sm:flex sm:items-end sm:justify-between" data-testid="cost-category-total">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Total Cost Rate</p>
                        <p class="mt-1 text-xl font-semibold tabular text-slate-900">
                            {{ formatMoney(costCategoryTotals.total || '0') }}
                            <span class="text-sm font-medium text-slate-500">/ {{ unitLabel || 'unit' }}</span>
                        </p>
                    </div>
                    <dl v-if="costSummary" class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:mt-0 sm:text-right">
                        <dt class="text-slate-500">Selling Rate</dt>
                        <dd class="font-medium tabular">{{ formatMoney(costSummary.selling_rate) }}</dd>
                        <dt class="text-slate-500">Margin / Unit</dt>
                        <dd class="font-medium tabular">{{ formatMoney(costSummary.margin_per_unit) }}</dd>
                        <dt class="text-slate-500">Margin %</dt>
                        <dd class="font-medium tabular">{{ costSummary.margin_percent != null ? `${formatQty(costSummary.margin_percent)}%` : '—' }}</dd>
                        <dt class="text-slate-500">Estimated Cost</dt>
                        <dd class="font-semibold tabular">{{ formatMoney(costSummary.estimated_cost) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div v-else-if="tab === 'materials' && can.view_material_requirements" class="space-y-4">
            <AppCard title="Material Norms (planning)">
                <p class="mb-3 text-sm text-slate-600">
                    Planning / MR consumption norms per BOQ unit — not the retired standalone Materials entry.
                    Costing materials are cost components under Cost Summary. BOQ Qty:
                    <span class="font-semibold tabular">{{ formatQty(item.quantity) }} {{ unitLabel }}</span>.
                    Does not post stock, MR, PO, or project cost.
                </p>
                <p v-if="normsLocked" class="mb-3 text-sm text-amber-800">
                    {{ material_norms?.locked ? 'BOQ is locked — material norms are immutable.' : 'You can view norms but cannot edit them.' }}
                </p>

                <div v-if="!material_norms" class="py-8 text-center text-sm text-slate-500">Loading material norms…</div>

                <form v-else class="space-y-4" @submit.prevent="saveNorms">
                    <div v-if="!normsLocked && material_norms?.recipes?.length" class="flex flex-wrap items-end gap-2 rounded-lg border border-line bg-slate-50 p-3">
                        <div class="min-w-[14rem] flex-1">
                            <label class="text-[11px] font-medium uppercase text-slate-500">Apply active recipe</label>
                            <select v-model="selectedRecipeId" class="mt-1 w-full rounded-lg border-slate-200 text-sm">
                                <option :value="null">— Select recipe —</option>
                                <option v-for="r in material_norms.recipes" :key="r.id" :value="r.id">{{ r.code }} v{{ r.version }} — {{ r.name }}</option>
                            </select>
                        </div>
                        <AppButton type="button" variant="secondary" :disabled="!selectedRecipeId" @click="applyRecipe">Apply recipe</AppButton>
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-line">
                        <table class="min-w-[980px] w-full text-left text-xs">
                            <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                                <tr>
                                    <th class="px-2 py-2">Material</th>
                                    <th class="px-2 py-2">Consumption / BOQ Unit</th>
                                    <th class="px-2 py-2">Unit</th>
                                    <th class="px-2 py-2">Wastage %</th>
                                    <th class="px-2 py-2 text-right">Base Req</th>
                                    <th class="px-2 py-2 text-right">Wastage Qty</th>
                                    <th class="px-2 py-2 text-right">Required Qty</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in normForm.norms" :key="index" class="border-t border-line align-top">
                                    <td class="px-1 py-1">
                                        <select v-model="row.material_id" class="w-56 rounded border-slate-200 py-1 text-xs" :disabled="normsLocked" @change="onNormMaterial(row)">
                                            <option :value="null">— Select —</option>
                                            <option v-for="opt in material_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1"><input v-model="row.consumption_qty" class="w-24 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="normsLocked" /></td>
                                    <td class="px-1 py-1"><input v-model="row.consumption_unit" class="w-16 rounded border-slate-200 px-1.5 py-1" :disabled="normsLocked" /></td>
                                    <td class="px-1 py-1"><input v-model="row.wastage_percent" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="normsLocked" /></td>
                                    <td class="px-2 py-2 text-right tabular">{{ row.base_requirement ? formatQty(row.base_requirement) : '—' }}</td>
                                    <td class="px-2 py-2 text-right tabular">{{ row.wastage_qty ? formatQty(row.wastage_qty) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-medium tabular">{{ row.required_qty ? formatQty(row.required_qty) : '—' }}</td>
                                    <td class="px-1 py-1">
                                        <button v-if="!normsLocked" type="button" class="text-rose-600" @click="removeNorm(index)">Del</button>
                                    </td>
                                </tr>
                                <tr v-if="!normForm.norms.length">
                                    <td colspan="8" class="px-3 py-6 text-center text-slate-500">No material norms yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="Object.keys(normForm.errors).length" class="text-sm text-rose-700">{{ Object.values(normForm.errors)[0] }}</p>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="!normsLocked" type="button" variant="secondary" @click="addNorm">Add material</AppButton>
                        <AppButton
                            v-if="!normsLocked && material_norms?.cost_materials?.length"
                            type="button"
                            variant="secondary"
                            @click="importNormsFromCost"
                        >
                            Use Cost Build-Up materials
                        </AppButton>
                        <AppButton v-if="!normsLocked" type="submit" :loading="normForm.processing">Save</AppButton>
                        <Link
                            :href="route('projects.material-requirement.index', project.id)"
                            class="ml-auto self-center text-sm font-medium text-brand-700 hover:underline"
                        >
                            Project Material Requirement →
                        </Link>
                    </div>
                </form>
            </AppCard>
        </div>

        <div v-else-if="tab === 'bbs' && can.view_bbs" class="space-y-4">
            <div v-if="!bbs_workspace" class="py-8 text-center text-sm text-slate-500">Loading BBS…</div>

            <AppCard v-else-if="!bbs_workspace.enabled || !bbs_workspace.bbs" title="Bar Bending Schedule">
                <p class="text-sm text-slate-600">BBS is not enabled for this BOQ item.</p>
                <p class="mt-1 text-xs text-slate-500">Enable only for reinforcement / steel work items.</p>
                <AppButton v-if="can.enable_bbs" class="mt-4" type="button" @click="enableBbs">Enable BBS</AppButton>
            </AppCard>

            <template v-else>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-900">Bar Bending Schedule</h2>
                            <StatusBadge :status="bbs_workspace.bbs.status" />
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ bbs_workspace.formula_note }}</p>
                        <p v-if="bbs_workspace.bbs.status === 'approved'" class="mt-1 text-xs font-medium text-emerald-700">
                            Steel requirement source: Approved BBS (Material Norm for mapped steel ignored).
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AppButton
                            v-if="bbs_workspace.bbs.drawing_viewer"
                            type="button"
                            variant="secondary"
                            @click="showDrawingViewer = true"
                        >
                            View Drawing
                        </AppButton>
                        <a
                            :href="route('projects.boqs.items.bbs.template', [project.id, boq.id, item.id])"
                            class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >Download BBS Template</a>
                        <a
                            :href="route('projects.boqs.items.bbs.export', [project.id, boq.id, item.id, bbs_workspace.bbs.id])"
                            class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >Export XLSX</a>
                        <label v-if="!bbsLocked" class="inline-flex cursor-pointer items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            Import BBS
                            <input type="file" class="hidden" accept=".xlsx,.xls,.csv" @change="importBbs" />
                        </label>
                    </div>
                </div>

                <form class="space-y-4" @submit.prevent="saveBbs">
                    <AppCard>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <FormInput v-model="bbsForm.title" label="Title" :disabled="bbsLocked" />
                            <FormSelect
                                v-model="bbsForm.drawing_revision_id"
                                label="Drawing revision"
                                :disabled="bbsLocked"
                                :options="[{ value: null, label: '— None —' }, ...drawing_revisions.map((d) => ({ value: d.id, label: d.label }))]"
                            />
                            <FormInput v-model="bbsForm.wastage_percent" label="Wastage %" :disabled="bbsLocked" />
                            <FormInput v-model="bbsForm.description" label="Description" :disabled="bbsLocked" />
                            <FormSelect
                                v-if="bbs_workspace.unit_weight_methods?.length"
                                v-model="bbsForm.unit_weight_method"
                                label="Unit weight method"
                                :disabled="bbsLocked"
                                :options="bbs_workspace.unit_weight_methods"
                            />
                            <FormSelect
                                v-if="bbsForm.unit_weight_method === 'profile' && bbs_workspace.steel_profiles?.length"
                                v-model="bbsForm.steel_profile_id"
                                label="Steel profile"
                                :disabled="bbsLocked"
                                :options="[{ value: null, label: '— Select —' }, ...bbs_workspace.steel_profiles]"
                            />
                            <FormSelect
                                v-if="bbs_workspace.bar_count_rounding?.length"
                                v-model="bbsForm.bar_count_rounding"
                                label="Bar count rounding"
                                :disabled="bbsLocked"
                                :options="bbs_workspace.bar_count_rounding"
                            />
                        </div>
                    </AppCard>

                    <AppCard v-if="can.manage_bbs_adjustments || bbsForm.adjustments.length" title="Weight adjustments (kg)">
                        <p class="mb-2 text-xs text-slate-500">Applied to theoretical weight before wastage. Requires reason.</p>
                        <div class="overflow-x-auto rounded-xl border border-line">
                            <table class="min-w-[720px] w-full text-left text-xs">
                                <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                                    <tr>
                                        <th class="px-2 py-2">Dia mm</th>
                                        <th class="px-2 py-2">Material</th>
                                        <th class="px-2 py-2">Qty kg</th>
                                        <th class="px-2 py-2">Reason</th>
                                        <th class="px-2 py-2">Reference</th>
                                        <th v-if="!bbsLocked && can.manage_bbs_adjustments" class="px-2 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(adj, ai) in bbsForm.adjustments" :key="ai" class="border-t border-line">
                                        <td class="px-1 py-1"><input v-model.number="adj.diameter_mm" type="number" class="w-16 rounded border-line" :disabled="bbsLocked || !can.manage_bbs_adjustments" /></td>
                                        <td class="px-1 py-1">
                                            <select v-model="adj.material_id" class="w-40 rounded border-line text-xs" :disabled="bbsLocked || !can.manage_bbs_adjustments">
                                                <option :value="null">—</option>
                                                <option v-for="opt in material_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                            </select>
                                        </td>
                                        <td class="px-1 py-1"><input v-model="adj.adjustment_qty_kg" class="w-20 rounded border-line tabular" :disabled="bbsLocked || !can.manage_bbs_adjustments" /></td>
                                        <td class="px-1 py-1"><input v-model="adj.reason" class="w-40 rounded border-line" :disabled="bbsLocked || !can.manage_bbs_adjustments" /></td>
                                        <td class="px-1 py-1"><input v-model="adj.reference" class="w-28 rounded border-line" :disabled="bbsLocked || !can.manage_bbs_adjustments" /></td>
                                        <td v-if="!bbsLocked && can.manage_bbs_adjustments" class="px-1 py-1"><button type="button" class="text-rose-600" @click="removeAdjustment(ai)">Del</button></td>
                                    </tr>
                                    <tr v-if="!bbsForm.adjustments.length">
                                        <td colspan="6" class="px-3 py-4 text-center text-slate-500">No adjustments.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <AppButton v-if="!bbsLocked && can.manage_bbs_adjustments" type="button" variant="secondary" class="mt-2" @click="addAdjustment">Add adjustment</AppButton>
                    </AppCard>

                    <AppCard title="BBS lines (dimensions in meters)">
                        <div class="overflow-x-auto rounded-xl border border-line">
                            <table class="min-w-[1600px] w-full text-left text-[11px]">
                                <thead class="bg-slate-50 uppercase text-slate-500">
                                    <tr>
                                        <th class="px-1.5 py-2">Location</th>
                                        <th class="px-1.5 py-2">Mark</th>
                                        <th class="px-1.5 py-2">Shape</th>
                                        <th class="px-1.5 py-2">Dia</th>
                                        <th class="px-1.5 py-2">Members</th>
                                        <th class="px-1.5 py-2">Bars/M</th>
                                        <th class="px-1.5 py-2 text-right">Tot Bars</th>
                                        <th class="px-1.5 py-2">A</th>
                                        <th class="px-1.5 py-2">B</th>
                                        <th class="px-1.5 py-2">C</th>
                                        <th class="px-1.5 py-2">D</th>
                                        <th class="px-1.5 py-2">Hook</th>
                                        <th class="px-1.5 py-2">Bend</th>
                                        <th class="px-1.5 py-2 text-right">Cut L</th>
                                        <th class="px-1.5 py-2 text-right">Tot L</th>
                                        <th class="px-1.5 py-2 text-right">UW</th>
                                        <th class="px-1.5 py-2 text-right">Wt kg</th>
                                        <th class="px-1.5 py-2">Remarks</th>
                                        <th v-if="!bbsLocked" class="px-1.5 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="(line, idx) in bbsForm.lines" :key="idx">
                                    <tr class="border-t border-line align-top">
                                        <td class="px-1 py-1"><input v-model="line.structure_location" class="w-24 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1"><input v-model="line.bar_mark" class="w-16 rounded border-line text-xs" :disabled="bbsLocked" required /></td>
                                        <td class="px-1 py-1">
                                            <select v-model="line.shape_code" class="w-28 rounded border-line text-xs" :disabled="bbsLocked">
                                                <option v-for="s in (bbs_workspace.shape_codes || [])" :key="s.value" :value="s.value">{{ s.label }}</option>
                                            </select>
                                        </td>
                                        <td class="px-1 py-1"><input v-model.number="line.diameter_mm" type="number" min="1" class="w-12 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1"><input v-model.number="line.number_of_members" type="number" min="0" class="w-14 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1"><input v-model.number="line.bars_per_member" type="number" min="0" class="w-14 rounded border-line text-xs" :disabled="bbsLocked || line.count_mode === 'spacing_based'" /></td>
                                        <td class="px-1 py-1 text-right tabular text-slate-500">{{ line.total_bars ?? '—' }}</td>
                                        <td class="px-1 py-1"><input v-if="shapeDims(line.shape_code).a" v-model="line.a_length" class="w-14 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1"><input v-if="shapeDims(line.shape_code).b" v-model="line.b_length" class="w-14 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1"><input v-if="shapeDims(line.shape_code).c" v-model="line.c_length" class="w-14 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1"><input v-if="shapeDims(line.shape_code).d" v-model="line.d_length" class="w-14 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1"><input v-model="line.hook_length" class="w-14 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1"><input v-model="line.bend_deduction" class="w-14 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td class="px-1 py-1 text-right tabular text-slate-500">{{ line.cutting_length ?? '—' }}</td>
                                        <td class="px-1 py-1 text-right tabular text-slate-500">{{ line.total_length ?? '—' }}</td>
                                        <td class="px-1 py-1 text-right tabular text-slate-500">{{ line.unit_weight ?? '—' }}</td>
                                        <td class="px-1 py-1 text-right tabular text-slate-500">{{ line.theoretical_weight ?? '—' }}</td>
                                        <td class="px-1 py-1"><input v-model="line.remarks" class="w-24 rounded border-line text-xs" :disabled="bbsLocked" /></td>
                                        <td v-if="!bbsLocked" class="whitespace-nowrap px-1 py-1">
                                            <button type="button" class="text-brand-700" @click="duplicateBbsLine(idx)">Dup</button>
                                            <button type="button" class="ml-1 text-slate-500" @click="moveBbsLine(idx, -1)">↑</button>
                                            <button type="button" class="ml-1 text-slate-500" @click="moveBbsLine(idx, 1)">↓</button>
                                            <button type="button" class="ml-1 text-rose-600" @click="removeBbsLine(idx)">Del</button>
                                        </td>
                                    </tr>
                                    <tr class="border-t border-dashed border-line bg-slate-50/60">
                                        <td :colspan="bbsLocked ? 18 : 19" class="px-2 py-2">
                                            <div class="flex flex-wrap items-end gap-2 text-[10px]">
                                                <label class="flex flex-col gap-0.5">
                                                    <span class="uppercase text-slate-500">Count</span>
                                                    <select v-model="line.count_mode" class="rounded border-line py-0.5" :disabled="bbsLocked">
                                                        <option v-for="m in (bbs_workspace.count_modes || [])" :key="m.value" :value="m.value">{{ m.label }}</option>
                                                    </select>
                                                </label>
                                                <template v-if="line.count_mode === 'spacing_based'">
                                                    <input v-model="line.distribution_length" placeholder="Dist L (m)" class="w-20 rounded border-line px-1 py-0.5" :disabled="bbsLocked" />
                                                    <input v-model="line.start_cover" placeholder="Start cov" class="w-16 rounded border-line px-1 py-0.5" :disabled="bbsLocked" />
                                                    <input v-model="line.end_cover" placeholder="End cov" class="w-16 rounded border-line px-1 py-0.5" :disabled="bbsLocked" />
                                                    <input v-model="line.spacing" placeholder="Spacing" class="w-16 rounded border-line px-1 py-0.5" :disabled="bbsLocked" />
                                                    <input v-model.number="line.faces_layers_multiplier" type="number" min="1" placeholder="Faces" class="w-14 rounded border-line px-1 py-0.5" :disabled="bbsLocked" />
                                                </template>
                                                <label class="flex flex-col gap-0.5">
                                                    <span class="uppercase text-slate-500">Lap</span>
                                                    <select v-model="line.lap_mode" class="rounded border-line py-0.5" :disabled="bbsLocked">
                                                        <option v-for="m in (bbs_workspace.lap_modes || [])" :key="m.value" :value="m.value">{{ m.label }}</option>
                                                    </select>
                                                </label>
                                                <input v-if="line.lap_mode === 'diameter_factor'" v-model="line.lap_diameter_factor" placeholder="Lap factor" class="w-16 rounded border-line px-1 py-0.5" :disabled="bbsLocked" />
                                                <input v-else v-model="line.lap_length" placeholder="Lap L (m)" class="w-16 rounded border-line px-1 py-0.5" :disabled="bbsLocked" />
                                                <input v-model="line.lap_count_per_bar" placeholder="Laps/bar" class="w-16 rounded border-line px-1 py-0.5" :disabled="bbsLocked" />
                                            </div>
                                        </td>
                                    </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="!bbsLocked" class="mt-3 flex flex-wrap gap-2">
                            <AppButton type="button" variant="secondary" @click="addBbsLine">Add Row</AppButton>
                            <AppButton type="submit" :loading="bbsForm.processing">Save BBS</AppButton>
                            <AppButton v-if="can.submit_bbs" type="button" @click="submitBbs">Submit</AppButton>
                        </div>
                        <div v-if="bbs_workspace.bbs.status === 'submitted'" class="mt-3 flex flex-wrap items-end gap-2">
                            <AppButton v-if="can.approve_bbs" type="button" @click="approveBbs">Approve</AppButton>
                            <div v-if="can.reject_bbs" class="flex gap-2">
                                <FormInput v-model="rejectBbsReason" label="Reject reason" />
                                <AppButton type="button" variant="secondary" class="self-end" @click="rejectBbs">Reject</AppButton>
                            </div>
                        </div>
                    </AppCard>
                </form>

                <div class="grid gap-4 lg:grid-cols-3">
                    <AppCard title="Summary" class="lg:col-span-1">
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-slate-500">Total bars</dt><dd class="tabular font-medium">{{ bbs_workspace.bbs.total_bars }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Total length</dt><dd class="tabular">{{ formatQty(bbs_workspace.bbs.total_length) }} m</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Theoretical</dt><dd class="tabular">{{ formatQty(bbs_workspace.bbs.total_theoretical_weight) }} kg<br><span class="text-xs text-slate-400">({{ formatQty(bbs_workspace.bbs.total_theoretical_weight_mt) }} MT)</span></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Wastage {{ formatQty(bbs_workspace.bbs.wastage_percent) }}%</dt><dd class="tabular">{{ formatQty(bbs_workspace.bbs.wastage_weight) }} kg</dd></div>
                            <div class="flex justify-between border-t border-line pt-2"><dt class="font-medium">Required steel</dt><dd class="tabular font-semibold">{{ formatQty(bbs_workspace.bbs.required_weight) }} kg<br><span class="text-xs font-normal text-slate-400">({{ formatQty(bbs_workspace.bbs.required_weight_mt) }} MT)</span></dd></div>
                        </dl>
                    </AppCard>

                    <AppCard title="Weight by diameter → material" class="lg:col-span-2">
                        <div class="overflow-x-auto rounded-xl border border-line">
                            <table class="min-w-[640px] w-full text-left text-xs">
                                <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                                    <tr>
                                        <th class="px-2 py-2">Dia</th>
                                        <th class="px-2 py-2 text-right">Length m</th>
                                        <th class="px-2 py-2 text-right">Theo kg</th>
                                        <th class="px-2 py-2 text-right">Waste kg</th>
                                        <th class="px-2 py-2 text-right">Required kg</th>
                                        <th class="px-2 py-2">Material</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(d, i) in (bbs_workspace.bbs.diameter_summary || [])" :key="d.diameter_mm" class="border-t border-line">
                                        <td class="px-2 py-2">{{ d.diameter_mm }} mm</td>
                                        <td class="px-2 py-2 text-right tabular">{{ formatQty(d.total_length) }}</td>
                                        <td class="px-2 py-2 text-right tabular">{{ formatQty(d.theoretical_weight) }}</td>
                                        <td class="px-2 py-2 text-right tabular">{{ formatQty(d.wastage_weight) }}</td>
                                        <td class="px-2 py-2 text-right tabular font-medium">{{ formatQty(d.required_weight) }}</td>
                                        <td class="px-2 py-2">
                                            <select
                                                v-if="can.map_bbs_steel"
                                                :value="bbsForm.maps[i]?.material_id ?? null"
                                                class="w-full max-w-xs rounded border-line text-xs"
                                                @change="bbsForm.maps[i] = { diameter_mm: d.diameter_mm, material_id: $event.target.value ? Number($event.target.value) : null }"
                                            >
                                                <option :value="null">— Map material —</option>
                                                <option v-for="m in material_options" :key="m.value" :value="m.value">{{ m.label }}</option>
                                            </select>
                                            <span v-else>{{ d.material || d.mapping_status }}</span>
                                            <div v-if="!d.material_id" class="text-[11px] text-amber-700">Material mapping required</div>
                                        </td>
                                    </tr>
                                    <tr v-if="!(bbs_workspace.bbs.diameter_summary || []).length">
                                        <td colspan="6" class="px-3 py-4 text-center text-slate-500">Save lines to see diameter summary.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <AppButton v-if="can.map_bbs_steel" class="mt-3" type="button" @click="saveBbsMaps">Save material mapping</AppButton>
                    </AppCard>
                </div>

                <UniversalFileViewer
                    v-if="bbs_workspace.bbs.drawing_viewer"
                    :show="showDrawingViewer"
                    :source="bbs_workspace.bbs.drawing_viewer.source"
                    :file-id="bbs_workspace.bbs.drawing_viewer.id"
                    @close="showDrawingViewer = false"
                />
            </template>
        </div>

        <div v-else-if="tab === 'rate_comparison' && can.view_costs" class="space-y-4">
            <AppCard title="Rate Comparison">
                <p class="mb-3 text-sm text-slate-600">
                    Compare internal cost rate with vendor / contractor quotes. Lowest quote is highlighted — selection is never automatic.
                </p>
                <div v-if="!rate_comparison" class="py-8 text-center text-sm text-slate-500">Loading rate comparison…</div>
                <form v-else class="space-y-4" @submit.prevent="saveRateComparison">
                    <div class="grid gap-3 sm:grid-cols-3 text-sm">
                        <div class="rounded-lg border border-line bg-slate-50 px-3 py-2">
                            <div class="text-[11px] uppercase text-slate-500">Internal cost rate</div>
                            <div class="font-medium tabular">{{ formatRate(rate_comparison.internal_cost_rate) }}</div>
                        </div>
                        <div class="rounded-lg border border-line bg-slate-50 px-3 py-2">
                            <div class="text-[11px] uppercase text-slate-500">Approved BOQ rate</div>
                            <div class="font-medium tabular">{{ formatRate(rate_comparison.approved_boq_rate) }}</div>
                        </div>
                        <div class="rounded-lg border border-line bg-slate-50 px-3 py-2">
                            <div class="text-[11px] uppercase text-slate-500">Lowest quoted</div>
                            <div class="font-medium tabular">{{ rate_comparison.lowest_effective_rate ? formatRate(rate_comparison.lowest_effective_rate) : '—' }}</div>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-line">
                        <table class="min-w-[1100px] w-full text-left text-xs">
                            <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                                <tr>
                                    <th class="px-2 py-2">Vendor / Contractor</th>
                                    <th class="px-2 py-2">Quoted rate</th>
                                    <th class="px-2 py-2">GST</th>
                                    <th class="px-2 py-2 text-right">Effective</th>
                                    <th class="px-2 py-2 text-right">vs Internal</th>
                                    <th class="px-2 py-2 text-right">vs Approved</th>
                                    <th class="px-2 py-2">Remarks</th>
                                    <th v-if="!rateCompareLocked" class="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, index) in rateCompareForm.quotes"
                                    :key="index"
                                    class="border-t border-line align-top"
                                    :class="row.is_lowest ? 'bg-emerald-50/60' : ''"
                                >
                                    <td class="px-1 py-1">
                                        <select v-model="row.vendor_id" class="mb-1 w-48 rounded border-slate-200 py-1 text-xs" :disabled="rateCompareLocked" @change="onRateVendor(row)">
                                            <option :value="null">— Optional master —</option>
                                            <option v-for="opt in vendor_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                        <input v-model="row.vendor_name" class="w-48 rounded border-slate-200 px-1.5 py-1" :disabled="rateCompareLocked" placeholder="Name" required />
                                    </td>
                                    <td class="px-1 py-1"><input v-model="row.quoted_rate" class="w-24 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="rateCompareLocked" /></td>
                                    <td class="px-1 py-1">
                                        <select v-model="row.gst_mode" class="mb-1 w-28 rounded border-slate-200 py-1 text-xs" :disabled="rateCompareLocked">
                                            <option v-for="m in rate_comparison_gst_modes" :key="m.value" :value="m.value">{{ m.label }}</option>
                                        </select>
                                        <input v-model="row.gst_percent" class="w-16 rounded border-slate-200 px-1 py-0.5 tabular" :disabled="rateCompareLocked" placeholder="%" />
                                    </td>
                                    <td class="px-2 py-2 text-right tabular">
                                        {{ row.effective_rate ? formatRate(row.effective_rate) : '—' }}
                                        <span v-if="row.is_lowest" class="ml-1 text-[10px] font-semibold uppercase text-emerald-700">Lowest</span>
                                    </td>
                                    <td class="px-2 py-2 text-right tabular">
                                        {{ row.variance_vs_internal != null ? formatRate(row.variance_vs_internal) : '—' }}
                                        <span v-if="row.variance_vs_internal_percent != null" class="block text-[10px] text-slate-500">{{ row.variance_vs_internal_percent }}%</span>
                                    </td>
                                    <td class="px-2 py-2 text-right tabular">
                                        {{ row.variance_vs_approved != null ? formatRate(row.variance_vs_approved) : '—' }}
                                        <span v-if="row.variance_vs_approved_percent != null" class="block text-[10px] text-slate-500">{{ row.variance_vs_approved_percent }}%</span>
                                    </td>
                                    <td class="px-1 py-1"><input v-model="row.remarks" class="w-36 rounded border-slate-200 px-1.5 py-1" :disabled="rateCompareLocked" /></td>
                                    <td v-if="!rateCompareLocked" class="px-1 py-1">
                                        <button type="button" class="text-rose-600" @click="removeRateQuote(index)">Del</button>
                                    </td>
                                </tr>
                                <tr v-if="!rateCompareForm.quotes.length">
                                    <td colspan="8" class="px-3 py-6 text-center text-slate-500">No comparison quotes yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="!rateCompareLocked" type="button" variant="secondary" @click="addRateQuote">Add quote</AppButton>
                        <AppButton v-if="!rateCompareLocked" type="submit">Save comparison</AppButton>
                    </div>
                </form>
            </AppCard>
        </div>

        <div v-else-if="tab === 'planning_map'" class="space-y-4">
            <AppCard title="Planning Mapping">
                <p class="mb-3 text-sm text-slate-600">
                    Manually allocate part of this BOQ item to Planning activities (Nos or BOQ Quantity). Conversion is derived from BOQ measurements. Partial and split allocations are supported.
                </p>
                <div v-if="!planning_map" class="py-8 text-center text-sm text-slate-500">Loading planning mapping…</div>
                <form v-else class="space-y-4" @submit.prevent="savePlanningMap">
                    <dl v-if="planning_map.allocation" class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border border-line bg-slate-50 p-3 text-xs sm:grid-cols-4">
                        <dt class="text-slate-500">BOQ Nos</dt><dd class="text-right tabular">{{ planning_map.allocation.boq_total_nos ?? '—' }}</dd>
                        <dt class="text-slate-500">BOQ Qty</dt><dd class="text-right tabular">{{ planning_map.allocation.boq_total_qty }} {{ planning_map.allocation.boq_unit }}</dd>
                        <dt class="text-slate-500">Mapped</dt><dd class="text-right tabular">{{ planning_map.allocation.mapped_nos }} Nos / {{ planning_map.allocation.mapped_boq_qty }} {{ planning_map.allocation.boq_unit }}</dd>
                        <dt class="text-slate-500">Available</dt><dd class="text-right tabular">{{ planning_map.allocation.available_nos ?? '—' }} Nos / {{ planning_map.allocation.available_boq_qty }} {{ planning_map.allocation.boq_unit }}</dd>
                    </dl>
                    <div class="overflow-x-auto rounded-xl border border-line">
                        <table class="min-w-[980px] w-full text-left text-xs">
                            <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                                <tr>
                                    <th class="px-2 py-2">Planning activity</th>
                                    <th class="px-2 py-2">Map by</th>
                                    <th class="px-2 py-2">Method</th>
                                    <th class="px-2 py-2">Allocated</th>
                                    <th class="px-2 py-2">Equiv. BOQ</th>
                                    <th class="px-2 py-2">Conversion</th>
                                    <th class="px-2 py-2">Remarks</th>
                                    <th v-if="!planningMapLocked" class="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in planningMapForm.maps" :key="index" class="border-t border-line align-top">
                                    <td class="px-1 py-1">
                                        <select v-model="row.project_task_id" class="w-56 rounded border-slate-200 py-1 text-xs" :disabled="planningMapLocked" @change="onPlanningTask(row)">
                                            <option :value="null">— Select activity —</option>
                                            <option v-for="opt in planning_task_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1">
                                        <select v-model="row.tracking_basis" class="w-40 rounded border-slate-200 py-1 text-xs" :disabled="planningMapLocked" @change="onTrackingBasis(row)">
                                            <option v-if="planning_map?.boq_item?.nos_mapping_available" value="nos">Nos</option>
                                            <option value="boq_quantity">BOQ Quantity</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1">
                                        <select v-model="row.mapping_method" class="w-40 rounded border-slate-200 py-1 text-xs" :disabled="planningMapLocked">
                                            <option v-for="m in planning_mapping_methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1">
                                        <input v-model="row.planning_qty" class="w-24 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="planningMapLocked" :placeholder="row.tracking_basis === 'nos' ? 'Nos' : 'Qty'" />
                                        <input v-model="row.planning_unit_symbol" class="mt-1 w-16 rounded border-slate-200 px-1 py-0.5" :disabled="planningMapLocked" placeholder="Unit" />
                                    </td>
                                    <td class="px-1 py-1">
                                        <input v-model="row.allocated_boq_qty" class="w-24 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="planningMapLocked || row.tracking_basis === 'nos'" placeholder="Auto" />
                                        <input v-model="row.boq_unit_symbol" class="mt-1 w-16 rounded border-slate-200 px-1 py-0.5" :disabled="planningMapLocked" placeholder="Unit" />
                                    </td>
                                    <td class="px-1 py-1">
                                        <input v-model="row.conversion_factor" class="w-24 rounded border-slate-200 px-1.5 py-1 tabular" disabled placeholder="Derived" />
                                        <p class="mt-1 text-[10px] text-slate-500">BOQ Qty ÷ Nos</p>
                                    </td>
                                    <td class="px-1 py-1"><input v-model="row.remarks" class="w-36 rounded border-slate-200 px-1.5 py-1" :disabled="planningMapLocked" /></td>
                                    <td v-if="!planningMapLocked" class="px-1 py-1">
                                        <button type="button" class="text-rose-600" @click="removePlanningMap(index)">Del</button>
                                    </td>
                                </tr>
                                <tr v-if="!planningMapForm.maps.length">
                                    <td colspan="7" class="px-3 py-6 text-center text-slate-500">No planning maps. Planning can still exist without BOQ.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="!planningMapLocked" type="button" variant="secondary" @click="addPlanningMap">Add mapping</AppButton>
                        <AppButton v-if="!planningMapLocked" type="submit">Save mapping</AppButton>
                    </div>
                </form>
            </AppCard>
        </div>

        <div v-else-if="tab === 'procurement' && can.view_material_requirements" class="space-y-4">
            <AppCard title="Procurement">
                <p v-if="advanced" class="mb-3 text-sm text-slate-600">
                    Planned requirement vs MR / PO / GRN / Issue for this item.
                </p>
                <div v-if="!item_procurement" class="py-8 text-center text-sm text-slate-500">Loading…</div>
                <div v-else class="overflow-x-auto rounded-xl border border-line">
                    <table class="min-w-[820px] w-full text-left text-xs">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                            <tr>
                                <th class="px-2 py-2">Material</th>
                                <th class="px-2 py-2">Source</th>
                                <th class="px-2 py-2 text-right">Required / BBS</th>
                                <th class="px-2 py-2 text-right">MR</th>
                                <th class="px-2 py-2 text-right">PO</th>
                                <th class="px-2 py-2 text-right">GRN</th>
                                <th class="px-2 py-2 text-right">Issue</th>
                                <th class="px-2 py-2 text-right">Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in item_procurement" :key="`${row.material_id}-${row.diameter_mm || row.unit}`" class="border-t border-line">
                                <td class="px-2 py-2">
                                    {{ row.material }} <span class="text-slate-400">{{ row.unit }}</span>
                                    <span v-if="row.diameter_mm" class="ml-1 text-[11px] text-slate-400">{{ row.diameter_mm }} mm</span>
                                </td>
                                <td class="px-2 py-2">{{ row.requirement_source || 'Material Norm' }}</td>
                                <td class="px-2 py-2 text-right tabular">{{ formatQty(row.bbs_required_qty || row.required_qty) }}</td>
                                <td class="px-2 py-2 text-right tabular">{{ formatQty(row.mr_qty) }}</td>
                                <td class="px-2 py-2 text-right tabular">{{ formatQty(row.po_qty) }}</td>
                                <td class="px-2 py-2 text-right tabular">{{ formatQty(row.received_qty) }}</td>
                                <td class="px-2 py-2 text-right tabular">{{ formatQty(row.issued_qty) }}</td>
                                <td class="px-2 py-2 text-right font-medium tabular">{{ formatQty(row.remaining_requirement) }}</td>
                            </tr>
                            <tr v-if="!item_procurement.length">
                                <td colspan="8" class="px-3 py-6 text-center text-slate-500">{{ advanced ? 'No BBS steel or material norms on this item.' : 'No procurement activity linked to this item yet.' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>

        <AppCard v-else title="Coming in later stages">
            <p class="text-sm text-slate-600">This tab is reserved for the Advanced BOQ upgrade and will be enabled once its stage is implemented.</p>
        </AppCard>
    </ProjectLayout>
</template>
