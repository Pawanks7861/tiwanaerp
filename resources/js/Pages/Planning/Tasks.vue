<script setup>
import FilterBar from '@/Components/Data/FilterBar.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import PlanningNav from '@/Components/Planning/PlanningNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney, formatPercent, formatQty } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    tasks: { type: Array, required: true },
    filtered: { type: Boolean, default: false },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

// ---- Tree --------------------------------------------------------------------------------------
const view = ref('tree');
const collapsed = reactive(new Set());
const byId = computed(() => Object.fromEntries(props.tasks.map((t) => [t.id, t])));
const childrenMap = computed(() => {
    const map = {};
    props.tasks.forEach((t) => {
        const parent = t.parent_id && byId.value[t.parent_id] ? t.parent_id : 0;
        (map[parent] ??= []).push(t);
    });

    return map;
});
const visibleRows = computed(() => {
    if (view.value === 'list') {
        return props.tasks.map((t) => ({ task: t, depth: 0 }));
    }
    const out = [];
    const walk = (parentId, depth) => {
        (childrenMap.value[parentId] ?? []).forEach((t) => {
            out.push({ task: t, depth });
            if (!collapsed.has(t.id)) {
                walk(t.id, depth + 1);
            }
        });
    };
    walk(0, 0);

    return out;
});
const hasChildren = (id) => (childrenMap.value[id] ?? []).length > 0;
const toggle = (id) => (collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id));

const filterSelects = computed(() => [
    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...props.options.statuses] },
    { key: 'assigned_to', options: [{ value: 'all', label: 'Anyone' }, ...props.options.members] },
    { key: 'milestone_id', options: [{ value: 'all', label: 'All milestones' }, ...props.options.milestones] },
]);

// ---- Drawer form -------------------------------------------------------------------------------
const drawer = ref(null); // { task | null }
const form = useForm({
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
    boq_item_id: null,
    milestone_id: null,
    budget_amount: null,
});
const task = computed(() => (drawer.value?.task ? byId.value[drawer.value.task.id] ?? drawer.value.task : null));
const editable = computed(() => (task.value ? props.can.update : props.can.create));

function descendantsOf(id) {
    const ids = new Set([id]);
    let added = true;
    while (added) {
        added = false;
        props.tasks.forEach((t) => {
            if (t.parent_id && ids.has(t.parent_id) && !ids.has(t.id)) {
                ids.add(t.id);
                added = true;
            }
        });
    }

    return ids;
}
const parentOptions = computed(() => {
    const excluded = task.value ? descendantsOf(task.value.id) : new Set();

    return props.tasks.filter((t) => !excluded.has(t.id)).map((t) => ({ value: t.id, label: `${t.wbs_code} ${t.name}` }));
});

function openTask(t = null, parentId = null) {
    form.clearErrors();
    const source = t ?? { parent_id: parentId, priority: 'medium' };
    Object.keys(form.data()).forEach((key) => (form[key] = source[key] ?? (key === 'priority' ? 'medium' : key === 'wbs_code' || key === 'name' || key === 'description' ? '' : null)));
    dependencyForm.reset();
    dependencyForm.clearErrors();
    drawer.value = { task: t };
    if (!t) {
        suggestWbs();
    }
}

async function suggestWbs() {
    try {
        const { data } = await axios.get(route('projects.planning.tasks.suggest-wbs', props.project.id), { params: { parent_id: form.parent_id || undefined } });
        form.wbs_code = data.wbs_code;
    } catch {
        // Suggestion is a convenience only; the server assigns a code when left empty.
    }
}
watch(
    () => form.parent_id,
    () => drawer.value && !drawer.value.task && suggestWbs(),
);

function pickBoqItem(id) {
    form.boq_item_id = id;
    const item = props.options.boqItems.find((i) => i.value === id);
    if (item) {
        form.unit_id ||= item.unit_id;
        form.planned_qty ||= item.quantity;
        form.name ||= item.label;
    }
}

const duration = computed(() => {
    if (!form.planned_start || !form.planned_finish) {
        return null;
    }
    const days = Math.round((new Date(form.planned_finish) - new Date(form.planned_start)) / 86400000) + 1;

    return days > 0 ? days : null;
});

function save() {
    const url = task.value ? route('projects.planning.tasks.update', [props.project.id, task.value.id]) : route('projects.planning.tasks.store', props.project.id);
    form.transform((data) => {
        const payload = { ...data };
        if (!props.can.view_costs) {
            delete payload.budget_amount;
        }

        return payload;
    })[task.value ? 'put' : 'post'](url, {
        preserveScroll: true,
        onSuccess: () => (drawer.value = null),
    });
}

// Status
const statusProcessing = ref(false);
function changeStatus(status) {
    statusProcessing.value = true;
    router.patch(route('projects.planning.tasks.status', [props.project.id, task.value.id]), { status }, {
        preserveScroll: true,
        onFinish: () => (statusProcessing.value = false),
    });
}
const statusLabel = (value) => props.options.statuses.find((s) => s.value === value)?.label ?? value;

// Dependencies
const dependencyForm = useForm({ predecessor_id: null, type: 'FS', lag_days: 0 });
const predecessorOptions = computed(() => {
    if (!task.value) {
        return [];
    }
    const existing = new Set(task.value.predecessors.map((p) => p.predecessor_id));

    return props.tasks.filter((t) => t.id !== task.value.id && !existing.has(t.id)).map((t) => ({ value: t.id, label: `${t.wbs_code} ${t.name}` }));
});
function addDependency() {
    dependencyForm.post(route('projects.planning.tasks.dependencies.store', [props.project.id, task.value.id]), {
        preserveScroll: true,
        onSuccess: () => dependencyForm.reset(),
    });
}
function removeDependency(dependency) {
    router.delete(route('projects.planning.tasks.dependencies.destroy', [props.project.id, task.value.id, dependency.id]), { preserveScroll: true });
}

// Delete
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
</script>

<template>
    <ProjectLayout :project="project" active="planning" title="Planning">
        <PlanningNav :project-id="project.id" active="tasks" />

        <AppCard :padded="false">
            <template #header>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Work breakdown & tasks</h2>
                    <p class="mt-0.5 text-xs text-slate-500">{{ tasks.length }} task(s). Progress and actual cost will come from DPRs and costing.</p>
                </div>
            </template>
            <template #actions>
                <div class="inline-flex rounded-lg border border-line p-0.5">
                    <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium" :class="view === 'tree' ? 'bg-slate-100 text-slate-900' : 'text-slate-500'" @click="view = 'tree'">Tree</button>
                    <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium" :class="view === 'list' ? 'bg-slate-100 text-slate-900' : 'text-slate-500'" @click="view = 'list'">List</button>
                </div>
                <AppButton v-if="can.create" size="sm" icon="plus" @click="openTask()">Task</AppButton>
            </template>

            <FilterBar
                :filters="{
                    search: filters.search ?? '',
                    status: filters.status ?? 'all',
                    assigned_to: filters.assigned_to ? Number(filters.assigned_to) : 'all',
                    milestone_id: filters.milestone_id ? Number(filters.milestone_id) : 'all',
                }"
                :selects="filterSelects"
                placeholder="Search WBS or task…"
            />

            <EmptyState
                v-if="!tasks.length"
                icon="calendar"
                :title="filtered ? 'No matching tasks' : 'No tasks yet'"
                :description="filtered ? 'Try different filters.' : 'Build the work breakdown: add top-level tasks, then sub-tasks under them.'"
            />

            <template v-else>
                <!-- Desktop -->
                <div class="hidden max-h-[70vh] overflow-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 z-10 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase shadow-sm">
                            <tr>
                                <th class="px-3 py-2.5 text-left">WBS / task</th>
                                <th class="px-3 py-2.5 text-left">Status</th>
                                <th class="px-3 py-2.5 text-left">Assignee</th>
                                <th class="px-3 py-2.5 text-left">Planned</th>
                                <th class="px-3 py-2.5 text-right">Days</th>
                                <th class="px-3 py-2.5 text-right">Planned qty</th>
                                <th class="px-3 py-2.5 text-left">BOQ / milestone</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line bg-white">
                            <tr v-for="{ task: t, depth } in visibleRows" :key="t.id" class="cursor-pointer hover:bg-slate-50" @click="openTask(t)">
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5" :style="{ paddingLeft: `${depth * 1.25}rem` }">
                                        <button
                                            v-if="view === 'tree' && hasChildren(t.id)"
                                            type="button"
                                            class="rounded p-0.5 text-slate-400 hover:bg-slate-100"
                                            :aria-label="collapsed.has(t.id) ? 'Expand' : 'Collapse'"
                                            @click.stop="toggle(t.id)"
                                        >
                                            <Icon :name="collapsed.has(t.id) ? 'chevron-right' : 'chevron-down'" :size="14" />
                                        </button>
                                        <span v-else class="w-5" />
                                        <span class="font-mono text-xs text-slate-500">{{ t.wbs_code }}</span>
                                        <span class="font-medium text-slate-900" :class="hasChildren(t.id) ? 'font-semibold' : ''">{{ t.name }}</span>
                                        <span v-if="t.predecessors.length" class="text-[10px] text-slate-400" :title="t.predecessors.map((p) => `${p.wbs_code} ${p.type}`).join(', ')">⇠ {{ t.predecessors.length }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2"><StatusBadge :status="t.status" :label="t.status_label" /></td>
                                <td class="px-3 py-2 text-xs text-slate-600">{{ t.assignee ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs whitespace-nowrap text-slate-600">
                                    <template v-if="t.planned_start">{{ formatDate(t.planned_start) }} – {{ formatDate(t.planned_finish) }}</template>
                                    <span v-else class="text-slate-400">Not scheduled</span>
                                </td>
                                <td class="px-3 py-2 text-right text-xs tabular">{{ t.duration_days ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-xs tabular">{{ Number(t.planned_qty) ? `${formatQty(t.planned_qty)} ${t.unit ?? ''}` : '—' }}</td>
                                <td class="px-3 py-2 text-xs text-slate-600">
                                    <div v-if="t.boq_item" class="truncate"><span class="font-mono">{{ t.boq_item.item_code }}</span> {{ t.boq_item.name }}</div>
                                    <div v-if="t.milestone" class="text-slate-400">◆ {{ t.milestone }}</div>
                                    <span v-if="!t.boq_item && !t.milestone">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile -->
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="{ task: t, depth } in visibleRows" :key="t.id" class="px-4 py-3 active:bg-slate-50" :style="{ paddingLeft: `${1 + depth * 0.75}rem` }" @click="openTask(t)">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900"><span class="font-mono text-xs text-slate-500">{{ t.wbs_code }}</span> {{ t.name }}</p>
                                <p class="text-xs text-slate-500">
                                    <template v-if="t.planned_start">{{ formatDate(t.planned_start) }} – {{ formatDate(t.planned_finish) }}</template><template v-else>Not scheduled</template>
                                    <template v-if="t.assignee"> · {{ t.assignee }}</template>
                                </p>
                            </div>
                            <StatusBadge :status="t.status" :label="t.status_label" />
                        </div>
                    </li>
                </ul>
            </template>
        </AppCard>

        <AppDrawer :show="!!drawer" :title="task ? `${task.wbs_code} ${task.name}` : 'New task'" @close="drawer = null">
            <div class="space-y-5">
                <form id="task-form" class="space-y-4" @submit.prevent="save">
                    <fieldset :disabled="!editable" class="space-y-4">
                        <SearchSelect v-model="form.parent_id" label="Parent task" placeholder="— Top level —" :options="parentOptions" :error="form.errors.parent_id" />
                        <div class="grid grid-cols-3 gap-3">
                            <FormInput v-model="form.wbs_code" label="WBS" uppercase maxlength="20" :error="form.errors.wbs_code" help="Auto if empty" />
                            <FormInput v-model="form.name" label="Task" required maxlength="255" class="col-span-2" :error="form.errors.name" />
                        </div>
                        <FormInput v-model="form.description" label="Description" multiline :rows="2" :error="form.errors.description" />
                        <div class="grid grid-cols-2 gap-3">
                            <FormSelect v-model="form.assigned_to" label="Assignee" placeholder="Unassigned" :options="options.members" :error="form.errors.assigned_to" />
                            <FormSelect v-model="form.priority" label="Priority" :options="options.priorities" :error="form.errors.priority" />
                            <FormInput v-model="form.planned_start" type="date" label="Planned start" :error="form.errors.planned_start" />
                            <FormInput v-model="form.planned_finish" type="date" label="Planned finish" :error="form.errors.planned_finish" :help="duration ? `${duration} day(s)` : null" />
                        </div>
                        <SearchSelect
                            :model-value="form.boq_item_id"
                            label="BOQ line"
                            :options="options.boqItems"
                            :help="options.boqItems.length ? 'Lines of the current approved BOQ' : 'No approved BOQ yet'"
                            :error="form.errors.boq_item_id"
                            @update:model-value="pickBoqItem"
                        />
                        <div class="grid grid-cols-2 gap-3">
                            <FormSelect v-model="form.unit_id" label="Unit" :options="options.units" :error="form.errors.unit_id" />
                            <DecimalInput v-model="form.planned_qty" label="Planned qty" :decimals="4" :error="form.errors.planned_qty" />
                            <FormSelect v-model="form.milestone_id" label="Milestone" placeholder="None" :options="options.milestones" :error="form.errors.milestone_id" />
                            <DecimalInput v-if="can.view_costs" v-model="form.budget_amount" label="Budget" prefix="₹" :decimals="2" :error="form.errors.budget_amount" />
                        </div>
                    </fieldset>
                </form>

                <template v-if="task">
                    <section class="rounded-lg border border-line p-3">
                        <h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Status & progress</h3>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <StatusBadge :status="task.status" :label="task.status_label" />
                            <template v-if="can.update_progress">
                                <AppButton
                                    v-for="next in options.transitions[task.status]"
                                    :key="next"
                                    size="sm"
                                    variant="secondary"
                                    :loading="statusProcessing"
                                    @click="changeStatus(next)"
                                >→ {{ statusLabel(next) }}</AppButton>
                            </template>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <dt class="text-slate-500">Actual start</dt><dd class="text-right">{{ formatDate(task.actual_start) }}</dd>
                            <dt class="text-slate-500">Actual finish</dt><dd class="text-right">{{ formatDate(task.actual_finish) }}</dd>
                            <dt class="text-slate-500">Completed qty</dt><dd class="text-right tabular">{{ formatQty(task.completed_qty) }} {{ task.unit ?? '' }}</dd>
                            <dt class="text-slate-500">Progress</dt><dd class="text-right tabular">{{ formatPercent(task.progress_percent) }}</dd>
                            <template v-if="can.view_costs">
                                <dt class="text-slate-500">Actual cost</dt><dd class="text-right tabular">{{ formatMoney(task.actual_cost) }}</dd>
                            </template>
                        </dl>
                        <p class="mt-2 text-[11px] text-slate-400">Completed quantity, progress and actual cost are recorded through DPRs and costing (later phase).</p>
                    </section>

                    <section class="rounded-lg border border-line p-3">
                        <h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Predecessors</h3>
                        <ul v-if="task.predecessors.length" class="mt-2 divide-y divide-line text-sm">
                            <li v-for="p in task.predecessors" :key="p.id" class="flex items-center justify-between gap-2 py-1.5">
                                <span class="min-w-0 truncate"><span class="font-mono text-xs text-slate-500">{{ p.wbs_code }}</span> {{ p.name }}</span>
                                <span class="flex shrink-0 items-center gap-2 text-xs text-slate-600">
                                    {{ p.type }}<template v-if="p.lag_days"> {{ p.lag_days > 0 ? '+' : '' }}{{ p.lag_days }}d</template>
                                    <button v-if="can.update" type="button" class="rounded p-1 text-slate-400 hover:text-red-600" aria-label="Remove dependency" @click="removeDependency(p)"><Icon name="trash" :size="14" /></button>
                                </span>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-xs text-slate-500">No predecessors.</p>
                        <form v-if="can.update" class="mt-3 grid grid-cols-6 items-start gap-2" @submit.prevent="addDependency">
                            <SearchSelect v-model="dependencyForm.predecessor_id" :options="predecessorOptions" placeholder="Predecessor…" class="col-span-6" :error="dependencyForm.errors.predecessor_id" />
                            <select v-model="dependencyForm.type" class="col-span-3 rounded-lg border-slate-300 py-2 text-sm" aria-label="Dependency type">
                                <option v-for="t in options.dependencyTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                            <input v-model.number="dependencyForm.lag_days" type="number" min="-365" max="365" class="col-span-1 rounded-lg border-slate-300 py-2 text-sm" aria-label="Lag days" title="Lag (days)" />
                            <AppButton type="submit" size="md" variant="secondary" class="col-span-2" :disabled="!dependencyForm.predecessor_id" :loading="dependencyForm.processing">Add</AppButton>
                            <p v-if="dependencyForm.errors.lag_days" class="col-span-6 text-xs text-red-600">{{ dependencyForm.errors.lag_days }}</p>
                        </form>
                    </section>
                </template>
            </div>
            <template #footer>
                <AppButton v-if="task && can.delete" variant="ghost" class="mr-auto text-red-600" icon="trash" @click="deleting = task">Delete</AppButton>
                <AppButton v-if="task && can.create" variant="secondary" @click="openTask(null, task.id)">Add sub-task</AppButton>
                <AppButton variant="secondary" @click="drawer = null">Close</AppButton>
                <AppButton v-if="editable" type="submit" form="task-form" :loading="form.processing">{{ task ? 'Save' : 'Create' }}</AppButton>
            </template>
        </AppDrawer>

        <ConfirmDialog
            :show="!!deleting"
            :title="`Delete ${deleting?.wbs_code ?? ''} ${deleting?.name ?? ''}?`"
            message="Tasks with sub-tasks cannot be deleted. Its dependencies are removed too."
            confirm-label="Delete task"
            :processing="deleteProcessing"
            @close="deleting = null"
            @confirm="destroy"
        />
    </ProjectLayout>
</template>
