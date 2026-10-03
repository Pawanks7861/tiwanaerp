<script setup>
import StatCard from '@/Components/Data/StatCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatMoneyShort } from '@/lib/format';
import { usePermissions } from '@/lib/permissions';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stats: { type: Object, required: true },
    recentProjects: { type: Array, required: true },
    statusBreakdown: { type: Array, required: true },
});

const page = usePage();
const { can } = usePermissions();
const greeting = computed(() => {
    const hour = new Date().getHours();

    return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
});
const totalProjects = computed(() => props.statusBreakdown.reduce((sum, s) => sum + s.value, 0));
const barColors = {
    Planning: 'bg-brand-500',
    Active: 'bg-emerald-500',
    'On Hold': 'bg-accent-500',
    Completed: 'bg-sky-500',
    Closed: 'bg-slate-400',
    Cancelled: 'bg-red-400',
};
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 sm:text-2xl">{{ greeting }}, {{ page.props.auth.user.name.split(' ')[0] }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ page.props.company?.current?.name }} · overview of your projects</p>
            </div>
            <AppButton v-if="can('projects.create')" :href="route('projects.create')" icon="plus">New project</AppButton>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <StatCard label="Active projects" :value="stats.active" icon="building" tone="green" :href="route('projects.index', { status: 'active' })" />
            <StatCard label="In planning" :value="stats.planning" icon="calendar" tone="brand" :href="route('projects.index', { status: 'planning' })" />
            <StatCard
                v-if="stats.pending_approvals !== null"
                label="Awaiting my approval"
                :value="stats.pending_approvals"
                icon="check-circle"
                tone="amber"
                :href="route('approvals.index')"
            />
            <StatCard v-else label="On hold" :value="stats.on_hold" icon="warning" tone="orange" />
            <StatCard
                v-if="stats.contract_value !== null"
                label="Open contract value"
                :value="formatMoneyShort(stats.contract_value)"
                icon="banknotes"
                tone="slate"
            />
            <StatCard v-else label="Completed" :value="stats.completed" icon="check-circle" tone="slate" />
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <AppCard title="Open projects" class="lg:col-span-2" :padded="false">
                <template #actions>
                    <Link v-if="can('projects.view')" :href="route('projects.index')" class="text-xs font-medium text-brand-600 hover:text-brand-700">View all</Link>
                </template>
                <ul v-if="recentProjects.length" class="divide-y divide-line">
                    <li v-for="project in recentProjects" :key="project.id">
                        <Link :href="route('projects.show', project.id)" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 sm:px-5">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs text-slate-500">{{ project.code }}</span>
                                    <span class="truncate text-sm font-medium text-slate-900">{{ project.name }}</span>
                                </div>
                                <p class="mt-0.5 truncate text-xs text-slate-500">
                                    {{ project.city ?? 'No city' }}
                                    <template v-if="project.manager_name"> · PM {{ project.manager_name }}</template>
                                    <template v-if="project.expected_end_date"> · Due {{ formatDate(project.expected_end_date) }}</template>
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <StatusBadge :status="project.status" :label="project.status_label" />
                                <span v-if="project.contract_value !== null" class="text-xs text-slate-500 tabular">{{ formatMoneyShort(project.contract_value) }}</span>
                            </div>
                        </Link>
                    </li>
                </ul>
                <EmptyState
                    v-else
                    icon="building"
                    title="No open projects"
                    :description="can('projects.create') ? 'Create your first project to get started.' : 'You have not been assigned to a project yet.'"
                >
                    <AppButton v-if="can('projects.create')" :href="route('projects.create')" icon="plus" size="sm">New project</AppButton>
                </EmptyState>
            </AppCard>

            <AppCard title="Projects by status">
                <div v-if="totalProjects" class="space-y-3">
                    <div v-for="row in statusBreakdown.filter((s) => s.value > 0)" :key="row.label">
                        <div class="mb-1 flex justify-between text-sm">
                            <span class="text-slate-600">{{ row.label }}</span>
                            <span class="font-medium text-slate-900 tabular">{{ row.value }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full" :class="barColors[row.label] ?? 'bg-slate-400'" :style="{ width: `${(row.value / totalProjects) * 100}%` }" />
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-slate-500">No projects yet.</p>
            </AppCard>
        </div>
    </AppLayout>
</template>
