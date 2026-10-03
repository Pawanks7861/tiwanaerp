<script setup>
import PlanningNav from '@/Components/Planning/PlanningNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import Gantt from 'frappe-gantt';
import '../../../../node_modules/frappe-gantt/dist/frappe-gantt.css';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    dataUrl: { type: String, required: true },
});

const el = ref(null);
const loading = ref(true);
const error = ref(null);
const tasks = ref([]);
const unscheduled = ref(0);
const mode = ref('Week');
let chart = null;

// frappe-gantt writes names and popup content with innerHTML.
const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get(props.dataUrl);
        tasks.value = data.tasks;
        unscheduled.value = data.unscheduled;
    } catch {
        error.value = 'The schedule could not be loaded.';
    } finally {
        loading.value = false;
    }
    await nextTick();
    render();
}

function render() {
    if (!el.value || !tasks.value.length) {
        return;
    }
    const rows = tasks.value.map((t) => ({ ...t, name: escapeHtml(t.name), dependencies: [...t.dependencies] }));
    el.value.innerHTML = '';
    chart = new Gantt(el.value, rows, {
        readonly: true,
        view_mode: mode.value,
        view_mode_select: false,
        today_button: true,
        infinite_padding: false,
        container_height: Math.min(640, 90 + rows.length * 48),
        popup: (ctx) => {
            const t = ctx.task;
            ctx.set_title(t.name);
            ctx.set_subtitle(escapeHtml(`${t.status_label}${t.assignee ? ` · ${t.assignee}` : ''}`));
            const links = (t.links ?? []).map((l) => `${l.type}${l.lag_days ? ` ${l.lag_days > 0 ? '+' : ''}${l.lag_days}d` : ''}`).join(', ');
            ctx.set_details(
                escapeHtml(`${formatDate(t.start)} – ${formatDate(t.end)} · Progress ${Math.round(Number(t.progress) || 0)}%`) +
                    (links ? `<br>${escapeHtml(`Depends on (${links})`)}` : ''),
            );
        },
    });
}

function setMode(next) {
    mode.value = next;
    chart?.change_view_mode(next);
}

onMounted(load);
onBeforeUnmount(() => {
    chart = null;
});
</script>

<template>
    <ProjectLayout :project="project" active="planning" title="Gantt">
        <PlanningNav :project-id="project.id" active="gantt" />

        <AppCard :padded="false">
            <template #header>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Schedule</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Read-only view of planned dates and dependencies.
                        <template v-if="unscheduled">{{ unscheduled }} task(s) without planned dates are not shown.</template>
                    </p>
                </div>
            </template>
            <template #actions>
                <div class="inline-flex rounded-lg border border-line p-0.5">
                    <button
                        v-for="m in ['Day', 'Week', 'Month']"
                        :key="m"
                        type="button"
                        class="rounded-md px-2.5 py-1 text-xs font-medium"
                        :class="mode === m ? 'bg-slate-100 text-slate-900' : 'text-slate-500'"
                        @click="setMode(m)"
                    >{{ m }}</button>
                </div>
            </template>

            <div v-if="loading" class="px-6 py-12 text-center text-sm text-slate-500">Loading schedule…</div>
            <EmptyState v-else-if="error" icon="warning" title="Schedule unavailable" :description="error">
                <AppButton size="sm" variant="secondary" @click="load">Retry</AppButton>
            </EmptyState>
            <EmptyState
                v-else-if="!tasks.length"
                icon="calendar"
                title="Nothing scheduled yet"
                description="Give tasks planned start and finish dates to see them on the chart."
            >
                <Link :href="route('projects.planning.tasks.index', project.id)" class="text-sm font-medium text-brand-700 hover:underline">Go to tasks</Link>
            </EmptyState>
            <div v-show="!loading && !error && tasks.length" class="gantt-host overflow-x-auto">
                <div ref="el" />
            </div>

            <div v-if="tasks.length" class="flex flex-wrap gap-3 border-t border-line px-4 py-2 text-[11px] text-slate-500">
                <span class="flex items-center gap-1"><i class="legend bg-slate-300" /> Not started</span>
                <span class="flex items-center gap-1"><i class="legend bg-brand-400" /> In progress</span>
                <span class="flex items-center gap-1"><i class="legend bg-red-400" /> Delayed</span>
                <span class="flex items-center gap-1"><i class="legend bg-amber-300" /> On hold</span>
                <span class="flex items-center gap-1"><i class="legend bg-emerald-400" /> Completed</span>
            </div>
        </AppCard>
    </ProjectLayout>
</template>

<style>
.gantt-host .gantt-status-not_started { --g-bar-color: #e2e8f0; --g-progress-color: #94a3b8; }
.gantt-host .gantt-status-in_progress { --g-bar-color: #bfdbfe; --g-progress-color: #3b82f6; }
.gantt-host .gantt-status-delayed { --g-bar-color: #fecaca; --g-progress-color: #ef4444; }
.gantt-host .gantt-status-on_hold { --g-bar-color: #fde68a; --g-progress-color: #d97706; }
.gantt-host .gantt-status-completed { --g-bar-color: #a7f3d0; --g-progress-color: #10b981; }
.legend { display: inline-block; width: 0.75rem; height: 0.5rem; border-radius: 2px; }
</style>
