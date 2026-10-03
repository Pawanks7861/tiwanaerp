<script setup>
import Pagination from '@/Components/Data/Pagination.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatQty } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    tasks: { type: Object, required: true },
    kpis: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    assignees: { type: Array, required: true },
    milestones: { type: Array, required: true },
    can: { type: Object, required: true },
});

const state = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    assignee: props.filters.assignee ?? '',
    milestone: props.filters.milestone ?? '',
    wbs: props.filters.wbs ?? '',
    delayed: !!props.filters.delayed,
});

function apply() {
    const query = Object.fromEntries(Object.entries({ ...state, delayed: state.delayed ? 1 : '' }).filter(([, v]) => v !== '' && v !== null));
    router.get(route('projects.progress', props.project.id), query, { preserveState: true, preserveScroll: true, replace: true });
}

let timer = null;
watch(() => [state.search, state.wbs], () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 350);
});

const barColor = (row) => (row.status === 'completed' ? 'bg-emerald-500' : row.delay_days > 0 || row.status === 'delayed' ? 'bg-red-500' : 'bg-brand-500');
const delayLabel = (row) => {
    if (row.delay_days === null || row.delay_days === undefined) {
        return null;
    }
    if (row.status === 'completed') {
        return row.delay_days > 0 ? `Finished ${row.delay_days} d late` : row.delay_days < 0 ? `Finished ${-row.delay_days} d early` : 'On time';
    }

    return row.delay_days > 0 ? `${row.delay_days} d overdue` : null;
};
const selectClass = 'min-h-11 rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200';
</script>

<template>
    <ProjectLayout :project="project" active="progress" title="Progress">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <div class="rounded-xl border border-line bg-white p-4">
                    <p class="text-xs text-slate-500">Overall progress</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 tabular">{{ Number(kpis.overall_percent).toFixed(1) }}%</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full bg-brand-500" :style="{ width: `${Math.min(100, Number(kpis.overall_percent))}%` }" /></div>
                    <p class="mt-1 text-[11px] text-slate-400">Average of {{ kpis.leaf_tasks }} work tasks</p>
                </div>
                <div class="rounded-xl border border-line bg-white p-4">
                    <p class="text-xs text-slate-500">Completed</p>
                    <p class="mt-1 text-2xl font-semibold text-emerald-700 tabular">{{ kpis.by_status.completed ?? 0 }}</p>
                    <p class="text-[11px] text-slate-400">of {{ kpis.total }} tasks</p>
                </div>
                <div class="rounded-xl border border-line bg-white p-4">
                    <p class="text-xs text-slate-500">In progress</p>
                    <p class="mt-1 text-2xl font-semibold text-brand-700 tabular">{{ kpis.by_status.in_progress ?? 0 }}</p>
                    <p class="text-[11px] text-slate-400">{{ kpis.by_status.not_started ?? 0 }} not started</p>
                </div>
                <button type="button" class="rounded-xl border bg-white p-4 text-left hover:border-red-300" :class="state.delayed ? 'border-red-400 ring-2 ring-red-100' : 'border-line'" @click="(state.delayed = !state.delayed), apply()">
                    <p class="text-xs text-slate-500">Delayed</p>
                    <p class="mt-1 text-2xl font-semibold tabular" :class="kpis.delayed ? 'text-red-600' : 'text-slate-900'">{{ kpis.delayed }}</p>
                    <p class="text-[11px] text-slate-400">{{ state.delayed ? 'Showing delayed only' : 'Tap to filter' }}</p>
                </button>
                <div class="col-span-2 rounded-xl border border-line bg-white p-4 lg:col-span-1">
                    <p class="text-xs text-slate-500">On hold</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 tabular">{{ kpis.by_status.on_hold ?? 0 }}</p>
                </div>
            </div>

            <AppCard v-if="kpis.by_unit.length" title="Quantity by unit" subtitle="Quantities are only added up within the same unit." :padded="false">
                <ul class="grid divide-y divide-line sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4">
                    <li v-for="u in kpis.by_unit" :key="u.unit" class="px-4 py-3 text-sm">
                        <p class="font-medium text-slate-900">{{ u.unit }} <span class="text-xs font-normal text-slate-500">· {{ u.tasks }} task(s)</span></p>
                        <p class="text-slate-600 tabular">{{ formatQty(u.completed) }} / {{ formatQty(u.planned) }}</p>
                    </li>
                </ul>
            </AppCard>

            <AppCard title="Task progress" subtitle="Completed quantities come only from approved DPRs (progress ledger)." :padded="false">
                <template #actions>
                    <Link v-if="can.boq" :href="route('projects.progress.boq', project.id)" class="inline-flex min-h-9 items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
                        BOQ progress <Icon name="chevron-right" :size="14" />
                    </Link>
                </template>
                <div class="grid gap-2 border-b border-line p-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="relative sm:col-span-3 lg:col-span-2">
                        <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-slate-400" />
                        <input v-model="state.search" type="search" placeholder="Search task, WBS or BOQ item…" aria-label="Search tasks" class="block min-h-11 w-full rounded-lg border-slate-300 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200" />
                    </div>
                    <input v-model="state.wbs" type="text" placeholder="WBS starts with…" aria-label="WBS prefix" :class="selectClass" />
                    <select v-model="state.status" aria-label="Status" :class="selectClass" @change="apply">
                        <option value="">All statuses</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <select v-model="state.assignee" aria-label="Assignee" :class="selectClass" @change="apply">
                        <option value="">Anyone</option>
                        <option v-for="a in assignees" :key="a.value" :value="a.value">{{ a.label }}</option>
                    </select>
                    <select v-model="state.milestone" aria-label="Milestone" :class="selectClass" @change="apply">
                        <option value="">All milestones</option>
                        <option v-for="m in milestones" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>

                <template v-if="tasks.data.length">
                    <div class="hidden overflow-x-auto lg:block">
                        <table class="min-w-full divide-y divide-line text-sm">
                            <thead class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase">
                                <tr>
                                    <th class="px-3 py-2 text-left">WBS</th>
                                    <th class="px-3 py-2 text-left">Task</th>
                                    <th class="px-3 py-2 text-right">Planned</th>
                                    <th class="px-3 py-2 text-right">Done</th>
                                    <th class="px-3 py-2 text-right">Balance</th>
                                    <th class="w-40 px-3 py-2 text-left">Progress</th>
                                    <th class="px-3 py-2 text-left">Dates</th>
                                    <th class="px-3 py-2 text-left">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="row in tasks.data" :key="row.id" :class="row.is_parent ? 'bg-slate-50/60' : ''">
                                    <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-slate-600">{{ row.wbs_code }}</td>
                                    <td class="px-3 py-2">
                                        <div :class="row.is_parent ? 'font-semibold text-slate-900' : 'font-medium text-slate-800'">{{ row.name }}</div>
                                        <div class="text-xs text-slate-500">
                                            <template v-if="row.boq_item">BOQ {{ row.boq_item }}</template>
                                            <template v-if="row.assignee"><template v-if="row.boq_item"> · </template>{{ row.assignee }}</template>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap tabular">{{ row.planned_qty !== null ? `${formatQty(row.planned_qty)} ${row.unit ?? ''}` : '—' }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap tabular">{{ row.planned_qty !== null || Number(row.completed_qty) ? formatQty(row.completed_qty) : '—' }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap tabular">{{ row.balance_qty !== null ? formatQty(row.balance_qty) : '—' }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full" :class="barColor(row)" :style="{ width: `${Math.min(100, Number(row.progress_percent))}%` }" /></div>
                                            <span class="w-12 text-right text-xs tabular">{{ Number(row.progress_percent).toFixed(1) }}%</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-xs whitespace-nowrap text-slate-600">
                                        <div>Due {{ row.planned_finish ? formatDate(row.planned_finish) : '—' }}</div>
                                        <div v-if="row.actual_start" class="text-slate-400">Started {{ formatDate(row.actual_start) }}<template v-if="row.actual_finish"> · done {{ formatDate(row.actual_finish) }}</template></div>
                                        <div v-if="delayLabel(row)" :class="row.delay_days > 0 ? 'text-red-600' : 'text-emerald-700'">{{ delayLabel(row) }}</div>
                                    </td>
                                    <td class="px-3 py-2"><StatusBadge :status="row.status" :label="row.status_label" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <ul class="divide-y divide-line lg:hidden">
                        <li v-for="row in tasks.data" :key="row.id" class="px-4 py-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-sm" :class="row.is_parent ? 'font-semibold text-slate-900' : 'font-medium text-slate-800'"><span class="font-mono text-xs text-slate-500">{{ row.wbs_code }}</span> {{ row.name }}</p>
                                    <p v-if="row.planned_qty !== null" class="text-xs text-slate-500 tabular">{{ formatQty(row.completed_qty) }} / {{ formatQty(row.planned_qty) }} {{ row.unit }}</p>
                                </div>
                                <StatusBadge :status="row.status" :label="row.status_label" />
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full" :class="barColor(row)" :style="{ width: `${Math.min(100, Number(row.progress_percent))}%` }" /></div>
                                <span class="w-12 text-right text-xs tabular">{{ Number(row.progress_percent).toFixed(1) }}%</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Due {{ row.planned_finish ? formatDate(row.planned_finish) : '—' }}
                                <span v-if="delayLabel(row)" :class="row.delay_days > 0 ? 'text-red-600' : 'text-emerald-700'"> · {{ delayLabel(row) }}</span>
                            </p>
                        </li>
                    </ul>
                    <Pagination :paginator="tasks" />
                </template>
                <EmptyState v-else icon="check-circle" title="No tasks match" description="Plan the work under Planning, or clear the filters.">
                    <Link :href="route('projects.planning.tasks.index', project.id)" class="text-sm font-medium text-brand-700 hover:underline">Open planning</Link>
                </EmptyState>
            </AppCard>
        </div>
    </ProjectLayout>
</template>
