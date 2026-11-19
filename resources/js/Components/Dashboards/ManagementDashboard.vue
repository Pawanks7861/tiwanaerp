<script setup>
import StatCard from '@/Components/Data/StatCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatMoney, formatMoneyShort } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({
    dashboard: { type: Object, default: null },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
    profile: { type: String, default: null },
    period: { type: Object, default: null },
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    profileKey: { type: String, required: true },
    projectSelector: { type: Boolean, default: true },
    pendingApprovals: { type: Number, default: null },
    recentProjects: { type: Array, default: () => [] },
});

const page = usePage();
const selectClass = 'rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200';

const state = reactive({
    project_id: props.filters.project_id ? String(props.filters.project_id) : '',
    fy: props.filters.fy ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const cost = computed(() => props.dashboard?.cost_control ?? {});
const kpis = computed(() => cost.value.kpis ?? {});
const projects = computed(() => cost.value.projects ?? []);
const heads = computed(() => cost.value.cost_heads ?? []);
const vendors = computed(() => props.dashboard?.vendors ?? cost.value.vendors ?? []);
const detail = computed(() => props.dashboard?.project_detail ?? null);

const metaLine = computed(() => {
    const parts = [page.props.company?.current?.name];
    if (props.period?.label) {
        parts.push(props.period.label);
    }
    if (props.period?.as_of) {
        parts.push(`as of ${formatDate(props.period.as_of)}`);
    }

    return parts.filter(Boolean).join(' · ');
});

function apply() {
    const query = Object.fromEntries(Object.entries(state).filter(([, value]) => value !== '' && value !== null));
    router.get(route('dashboard'), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['dashboard', 'filters', 'period', 'pendingApprovals'],
    });
}

function moneyClass(value) {
    return String(value ?? '').trim().startsWith('-') ? 'text-rose-700' : 'text-slate-800';
}
</script>

<template>
    <AppLayout :title="title">
        <div class="space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 sm:text-2xl">{{ title }}</h1>
                    <p v-if="metaLine" class="mt-1 text-sm text-slate-500">{{ metaLine }}</p>
                    <p v-if="subtitle" class="mt-1 text-sm text-slate-500">{{ subtitle }}</p>
                </div>
                <AppButton v-if="can.reports" :href="route('reports.index')" variant="secondary" size="sm">Reports</AppButton>
            </div>

            <form class="flex flex-col gap-2 rounded-xl border border-line bg-white p-3 shadow-sm sm:flex-row sm:flex-wrap sm:items-end" @submit.prevent="apply">
                <label v-if="projectSelector" class="text-xs text-slate-500">
                    Project
                    <select v-model="state.project_id" :class="selectClass" class="mt-1 block w-full sm:w-56" @change="apply">
                        <option value="">All visible projects</option>
                        <option v-for="option in options.projects ?? []" :key="option.value" :value="String(option.value)">{{ option.label }}</option>
                    </select>
                </label>
                <label class="text-xs text-slate-500">
                    Financial year
                    <select v-model="state.fy" :class="selectClass" class="mt-1 block w-full sm:w-36" @change="apply">
                        <option v-for="option in options.fy ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
            </form>

            <div v-if="can.financials && kpis.revised_budget !== undefined" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <StatCard label="Original budget" :value="formatMoneyShort(kpis.original_budget)" icon="banknotes" tone="slate" />
                <StatCard label="Revised budget" :value="formatMoneyShort(kpis.revised_budget)" icon="banknotes" tone="brand" />
                <StatCard label="Actual invoiced" :value="formatMoneyShort(kpis.actual_invoiced)" icon="banknotes" tone="orange" />
                <StatCard label="Open commitment" :value="formatMoneyShort(kpis.open_commitment)" icon="truck" />
                <StatCard label="Total assigned" :value="formatMoneyShort(kpis.total_assigned)" icon="scale" />
                <StatCard label="Available" :value="formatMoneyShort(kpis.available_budget)" icon="check-circle" tone="green" />
                <StatCard label="GST exposure" :value="formatMoneyShort(kpis.gst_exposure)" icon="receipt" />
                <StatCard label="Cash paid" :value="formatMoneyShort(kpis.cash_paid)" icon="banknotes" />
            </div>

            <div v-if="dashboard?.operations" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <StatCard label="Pending approvals" :value="dashboard.operations.pending_approvals ?? pendingApprovals ?? 0" icon="check-circle" tone="amber" :href="route('approvals.index')" />
                <StatCard label="Budget drafts" :value="dashboard.operations.pending_budget_revisions ?? 0" icon="banknotes" tone="slate" />
                <StatCard label="GRNs in progress" :value="dashboard.operations.pending_grn ?? 0" icon="truck" />
                <StatCard label="Vendor bills pending" :value="dashboard.operations.vendor_bills_pending ?? 0" icon="receipt" />
                <StatCard label="Open NCRs" :value="dashboard.operations.open_ncr ?? 0" icon="clipboard" tone="amber" />
                <StatCard label="Pending MR" :value="dashboard.operations.pending_mr ?? 0" icon="archive" />
            </div>

            <AppCard v-if="dashboard?.change_control" title="Change control">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div>
                        <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Budget drafts</p>
                        <p class="mt-1 text-lg font-semibold tabular">{{ dashboard.change_control.pending_revisions }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Approved BOQ changes</p>
                        <p class="mt-1 text-lg font-semibold tabular">{{ dashboard.change_control.approved_boq_variations }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">BOQ changes in review</p>
                        <p class="mt-1 text-lg font-semibold tabular">{{ dashboard.change_control.pending_boq_variations }}</p>
                    </div>
                </div>
            </AppCard>

            <AppCard title="Project portfolio" :padded="false">
                <div v-if="projects.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Project</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-right">Original</th>
                                <th class="px-3 py-2 text-right">Revised</th>
                                <th class="px-3 py-2 text-right">Actual</th>
                                <th class="px-3 py-2 text-right">Commitment</th>
                                <th class="px-3 py-2 text-right">Available</th>
                                <th class="px-3 py-2 text-right">% Assigned</th>
                                <th class="px-3 py-2 text-left">Health</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in projects" :key="row.id" class="hover:bg-slate-50">
                                <td class="px-3 py-2">
                                    <Link :href="route('projects.cost-control.index', row.id)" class="font-medium text-slate-900 hover:text-brand-700">
                                        <span class="mr-2 font-mono text-xs text-slate-500">{{ row.code }}</span>{{ row.name }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2"><StatusBadge :status="row.status" /></td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(row.original_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(row.revised_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(row.actual_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(row.open_commitment) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(row.available_budget)">{{ formatMoney(row.available_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ row.percent_assigned != null ? `${row.percent_assigned}%` : '—' }}</td>
                                <td class="px-3 py-2">
                                    <StatusBadge
                                        v-if="row.status_indicator"
                                        :status="row.status_indicator.key"
                                        :label="row.status_indicator.label"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="p-5 text-sm text-slate-500">{{ cost.redacted ? 'Cost figures are hidden for this login.' : 'No projects in scope.' }}</p>
            </AppCard>

            <AppCard v-if="heads.length" title="Cost head distribution" :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Head</th>
                                <th class="px-3 py-2 text-right">Revised</th>
                                <th class="px-3 py-2 text-right">Actual</th>
                                <th class="px-3 py-2 text-right">Commitment</th>
                                <th class="px-3 py-2 text-right">Available</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="head in heads" :key="head.id">
                                <td class="px-3 py-2"><span class="mr-2 font-mono text-xs text-slate-500">{{ head.code }}</span>{{ head.name }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.revised_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.actual_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.open_commitment) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(head.available_budget)">{{ formatMoney(head.available_budget) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard v-if="vendors.length" title="Vendor exposure" :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Vendor</th>
                                <th class="px-3 py-2 text-right">Base orders</th>
                                <th class="px-3 py-2 text-right">Invoiced</th>
                                <th class="px-3 py-2 text-right">Open</th>
                                <th class="px-3 py-2 text-right">Cash paid</th>
                                <th class="px-3 py-2 text-right">Payable</th>
                                <th class="px-3 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(vendor, idx) in vendors.slice(0, 12)" :key="vendor.vendor_id ?? idx">
                                <td class="px-3 py-2">{{ vendor.vendor_name }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.total_base_orders) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.actual_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.open_commitment) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.net_cash_paid) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.balance_payable) }}</td>
                                <td class="px-3 py-2">{{ vendor.status }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard v-if="detail?.cost_control" title="Selected project WBS">
                <p class="mb-3 text-sm text-slate-600">
                    {{ detail.project.code }} — {{ detail.project.name }}
                    ·
                    <Link :href="route('projects.cost-control.index', detail.project.id)" class="font-medium text-brand-700 hover:underline">Open Cost Control</Link>
                </p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Head</th>
                                <th class="px-3 py-2 text-right">Revised</th>
                                <th class="px-3 py-2 text-right">Actual</th>
                                <th class="px-3 py-2 text-right">Open</th>
                                <th class="px-3 py-2 text-right">Available</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="head in detail.cost_control.heads" :key="head.id ?? head.code">
                                <td class="px-3 py-2">{{ head.name }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(head.revised_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(head.actual_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(head.open_commitment) }}</td>
                                <td class="px-3 py-2 text-right tabular" :class="moneyClass(head.available_budget)">{{ formatMoney(head.available_budget) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>
    </AppLayout>
</template>
