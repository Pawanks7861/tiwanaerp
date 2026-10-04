<script setup>
import Chart from '@/Components/Reports/Chart.vue';
import StatCard from '@/Components/Data/StatCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatMoneyShort, formatPercent } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({
    dashboard: { type: Object, default: null },
    pendingApprovals: { type: Number, default: null },
    recentProjects: { type: Array, required: true },
    filters: { type: Object, required: true },
    period: { type: Object, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const greeting = computed(() => {
    const hour = new Date().getHours();

    return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
});

const state = reactive({
    project_id: props.filters.project_id ? String(props.filters.project_id) : '',
    fy: props.filters.fy ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function apply() {
    const query = Object.fromEntries(Object.entries(state).filter(([, value]) => value !== '' && value !== null));
    router.get(route('dashboard'), query, { preserveState: true, preserveScroll: true, replace: true });
}

const kpis = computed(() => props.dashboard?.kpis ?? {});
const charts = computed(() => props.dashboard?.charts ?? {});
const selectClass = 'rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200';
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 sm:text-2xl">{{ greeting }}, {{ page.props.auth.user.name.split(' ')[0] }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ page.props.company?.current?.name }} · {{ period.label }} · as of {{ formatDate(period.as_of) }}</p>
            </div>
            <AppButton v-if="can.reports" :href="route('reports.index')" variant="secondary" size="sm">Reports</AppButton>
        </div>

        <form v-if="can.dashboard" class="mb-4 flex flex-col gap-2 rounded-xl border border-line bg-white p-3 shadow-sm sm:flex-row sm:flex-wrap sm:items-end" @submit.prevent="apply">
            <label class="text-xs text-slate-500">
                Project
                <select v-model="state.project_id" :class="selectClass" class="mt-1 block w-full sm:w-56" @change="apply">
                    <option value="">All visible projects</option>
                    <option v-for="option in options.projects" :key="option.value" :value="String(option.value)">{{ option.label }}</option>
                </select>
            </label>
            <label class="text-xs text-slate-500">
                Financial year
                <select v-model="state.fy" :class="selectClass" class="mt-1 block w-full sm:w-36" @change="apply">
                    <option v-for="option in options.fy" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </label>
            <label class="text-xs text-slate-500">
                From
                <input v-model="state.from" type="date" :class="selectClass" class="mt-1 block" @change="apply" />
            </label>
            <label class="text-xs text-slate-500">
                To
                <input v-model="state.to" type="date" :class="selectClass" class="mt-1 block" @change="apply" />
            </label>
        </form>

        <template v-if="dashboard">
            <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <StatCard label="Total projects" :value="kpis.total_projects" icon="building" tone="slate" />
                <StatCard label="Active projects" :value="kpis.active_projects" icon="building" tone="green" :href="route('projects.index', { status: 'active' })" />
                <StatCard label="Overall progress" :value="formatPercent(kpis.overall_progress)" icon="check-circle" tone="brand" />
                <StatCard label="Delayed tasks" :value="kpis.delayed_tasks" icon="warning" tone="orange" />
                <StatCard label="Open NCRs" :value="kpis.open_ncrs" icon="clipboard" tone="amber" />
                <StatCard v-if="pendingApprovals !== null" label="Awaiting my approval" :value="pendingApprovals" icon="check-circle" tone="amber" :href="route('approvals.index')" />
                <template v-if="can.financials">
                    <StatCard label="Project value" :value="formatMoneyShort(kpis.project_value)" icon="banknotes" />
                    <StatCard label="Budget" :value="formatMoneyShort(kpis.budget)" icon="banknotes" tone="brand" />
                    <StatCard label="Actual cost" :value="formatMoneyShort(kpis.actual_cost)" icon="banknotes" tone="orange" />
                    <StatCard label="Client outstanding" :value="formatMoneyShort(kpis.outstanding_receivables)" icon="receipt" />
                    <StatCard label="Vendor payables" :value="formatMoneyShort(kpis.vendor_payables)" icon="truck" />
                    <StatCard label="Purchase value" :value="formatMoneyShort(kpis.purchase_value)" icon="truck" tone="slate" />
                    <StatCard label="Material cost" :value="formatMoneyShort(kpis.material_cost)" icon="cube" />
                    <StatCard label="Labour cost" :value="formatMoneyShort(kpis.labour_cost)" icon="users" />
                    <StatCard label="Equipment cost" :value="formatMoneyShort(kpis.equipment_cost)" icon="wrench" />
                    <StatCard label="Subcontractor cost" :value="formatMoneyShort(kpis.subcontract_cost)" icon="briefcase" />
                </template>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <AppCard v-if="charts.progress"><Chart type="bar" title="Progress by project" :categories="charts.progress.categories" :series="charts.progress.series" /></AppCard>
                <AppCard v-if="charts.ncr"><Chart type="donut" title="NCR status" :categories="charts.ncr.categories" :series="charts.ncr.series" /></AppCard>
                <AppCard v-if="charts.budget_vs_actual"><Chart type="bar" title="Budget vs actual" :categories="charts.budget_vs_actual.categories" :series="charts.budget_vs_actual.series" money /></AppCard>
                <AppCard v-if="charts.cost_by_head"><Chart type="donut" title="Cost by head" :categories="charts.cost_by_head.categories" :series="charts.cost_by_head.series" money /></AppCard>
                <AppCard v-if="charts.cash_flow"><Chart type="bar" title="Monthly cash flow" :categories="charts.cash_flow.categories" :series="charts.cash_flow.series" money /></AppCard>
                <AppCard v-if="charts.receivables_payables"><Chart type="bar" title="Receivables vs payables" :categories="charts.receivables_payables.categories" :series="charts.receivables_payables.series" money /></AppCard>
            </div>
        </template>

        <AppCard title="Open projects" class="mt-4" :padded="false">
            <ul v-if="recentProjects.length" class="divide-y divide-line">
                <li v-for="project in recentProjects" :key="project.id">
                    <Link :href="route('projects.show', project.id)" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 sm:px-5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs text-slate-500">{{ project.code }}</span>
                                <span class="truncate text-sm font-medium text-slate-900">{{ project.name }}</span>
                            </div>
                            <p class="text-xs text-slate-500">{{ project.city || '—' }}<template v-if="project.manager_name"> · {{ project.manager_name }}</template></p>
                        </div>
                        <StatusBadge :status="project.status" :label="project.status_label" />
                        <span class="hidden text-xs text-slate-500 sm:block">{{ formatDate(project.expected_end_date) }}</span>
                    </Link>
                </li>
            </ul>
            <p v-else class="p-5 text-sm text-slate-500">No open projects.</p>
        </AppCard>
    </AppLayout>
</template>
