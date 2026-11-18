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
            payload.hold_quantity = null;
            payload.planned_start = null;
            payload.planned_finish = null;
            delete payload.maps;
        } else {
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
function blankMap() {
    return {
        id: null,
        boq_item_id: null,
        tracking_basis: 'nos',
        mapping_method: 'conversion_based',
        planning_qty: '',
        allocated_boq_qty: '',
        is_primary: true,
        remarks: '',
    };
}
function loadMapsFromTask(t) {
    mapForm.clearErrors();
    mapForm.maps = (t?.boq_maps?.length
        ? t.boq_maps.map((m) => ({
            id: m.id,
            boq_item_id: m.boq_item_id,
            tracking_basis: m.tracking_basis || 'boq_quantity',
            mapping_method: m.mapping_method || 'conversion_based',
            planning_qty: m.planning_qty || '',
            allocated_boq_qty: m.allocated_boq_qty || '',
            is_primary: !!m.is_primary,
            remarks: m.remarks || '',
        }))
        : []);
}
function onMapBasisChange(row) {
    const item = (props.options.boq_items || []).find((i) => i.value === row.boq_item_id);
    if (!item) return;
    if (row.tracking_basis === 'nos' && !item.effective_nos && !item.measurement_nos) {
        row.tracking_basis = 'boq_quantity';
    }
    row.planning_qty = '';
    row.allocated_boq_qty = '';
}

function boqItemFor(row) {
    return (props.options.boq_items || []).find((i) => i.value === row.boq_item_id) || null;
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
    if (row.tracking_basis === 'nos') {
        return `${(qty * factor).toFixed(4)} ${item.unit || 'Cum'}`;
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
                            <DecimalInput v-model="form.planned_qty" label="Total qty" :decimals="4" :error="form.errors.planned_qty" />
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
                        Link BOQ while creating or editing this activity. One Save creates the activity and mapping together.
                        Total Nos = NOB × Nos / Building. Partial allocation and split across activities are supported.
                    </p>
                    <p v-if="mapForm.errors.maps || form.errors.maps" class="text-xs text-red-600">{{ mapForm.errors.maps || form.errors.maps }}</p>
                    <div v-for="(row, index) in mapForm.maps" :key="index" class="space-y-2 rounded-md border border-slate-100 bg-slate-50/70 p-3">
                        <div class="flex items-start justify-between gap-2">
                            <SearchSelect
                                v-model="row.boq_item_id"
                                label="BOQ item"
                                placeholder="Select BOQ item…"
                                :options="options.boq_items || []"
                                class="flex-1"
                                :error="mapForm.errors[`maps.${index}.boq_item_id`] || form.errors[`maps.${index}.boq_item_id`]"
                                @update:model-value="onMapBasisChange(row)"
                            />
                            <button type="button" class="mt-6 text-xs text-rose-600" @click="mapForm.maps.splice(index, 1)">Remove</button>
                        </div>
                        <template v-if="boqItemFor(row)">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-600">
                                <dt>BOQ</dt><dd class="text-right">{{ boqItemFor(row)?.boq_label || '—' }}</dd>
                                <dt title="Number of Buildings">NOB</dt><dd class="text-right tabular">{{ boqItemFor(row)?.measurement_nob ?? 1 }}</dd>
                                <dt>Nos / Building</dt><dd class="text-right tabular">{{ boqItemFor(row)?.nos_per_building ?? '—' }}</dd>
                                <dt title="NOB × Nos / Building">Total Nos</dt><dd class="text-right tabular font-medium text-slate-800">{{ boqItemFor(row)?.effective_nos ?? boqItemFor(row)?.measurement_nos ?? '—' }}</dd>
                                <dt>BOQ Qty</dt><dd class="text-right tabular">{{ boqItemFor(row)?.quantity }} {{ boqItemFor(row)?.unit }}</dd>
                                <dt>Available Nos</dt><dd class="text-right tabular">{{ boqItemFor(row)?.allocation?.available_nos ?? '—' }}</dd>
                                <dt>Available Qty</dt><dd class="text-right tabular">{{ boqItemFor(row)?.allocation?.available_boq_qty }} {{ boqItemFor(row)?.unit }}</dd>
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
                    <div v-if="!mapForm.maps.length" class="text-xs text-slate-500">No BOQ linked — activity can be saved without a mapping.</div>
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
