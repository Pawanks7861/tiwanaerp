<script setup>
import StatCard from '@/Components/Data/StatCard.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    summary: { type: Object, default: () => ({}) },
    versions: { type: Array, default: () => [] },
    vendors: { type: Array, default: () => [] },
    cashFlow: { type: Object, default: null },
    unallocated: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const asOf = ref(props.filters.as_of ?? '');
const showPlanEditor = ref(false);
const heads = computed(() => props.summary?.heads ?? []);
const totals = computed(() => props.summary?.totals ?? null);
const versionRows = computed(() => (props.versions?.length ? props.versions : (props.summary?.versions ?? [])));
const vendorRows = computed(() => props.vendors ?? []);
const unallocatedRow = computed(() => props.unallocated ?? props.summary?.unallocated ?? null);
const months = computed(() => props.cashFlow?.months ?? []);

const changeRows = computed(() => heads.value.filter((head) => isMoney(head.approved_changes)));
const commitmentRows = computed(() => heads.value.filter((head) => isMoney(head.open_commitment)));

const planForm = useForm({
    name: props.cashFlow?.plan?.name ?? 'Cash Flow Plan',
    months: (props.cashFlow?.editor_months ?? []).map((row) => ({
        year_month: row.year_month,
        planned_amount: row.planned_amount ?? '0.00',
    })),
});

watch(
    () => props.cashFlow?.editor_months,
    (rows) => {
        if (!rows?.length) {
            return;
        }
        planForm.name = props.cashFlow?.plan?.name ?? 'Cash Flow Plan';
        planForm.months = rows.map((row) => ({
            year_month: row.year_month,
            planned_amount: row.planned_amount ?? '0.00',
        }));
    },
);

function isMoney(value) {
    if (value === null || value === undefined || value === '') {
        return false;
    }
    const normalized = String(value).replace(/,/g, '');

    return normalized !== '0' && normalized !== '0.0' && normalized !== '0.00';
}

function moneyClass(value) {
    return String(value ?? '').trim().startsWith('-') ? 'text-rose-700' : 'text-slate-800';
}

function headHref(head) {
    if (head?.id) {
        return route('projects.cost-control.head', [props.project.id, head.id]);
    }

    return route('projects.cost-control.unallocated', props.project.id);
}

function monthLabel(row) {
    const ym = typeof row === 'string' ? row : row.year_month;
    const match = /^(\d{4})-(\d{2})$/.exec(ym ?? '');
    if (!match) {
        return ym ?? '—';
    }

    return new Intl.DateTimeFormat('en-IN', { month: 'short', year: 'numeric' }).format(new Date(Number(match[1]), Number(match[2]) - 1, 1));
}

function applyAsOf() {
    router.get(
        route('projects.cost-control.index', props.project.id),
        asOf.value ? { as_of: asOf.value } : {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function savePlan() {
    planForm.post(route('projects.cost-control.cash-flow-plan', props.project.id), {
        preserveScroll: true,
        onSuccess: () => {
            showPlanEditor.value = false;
        },
    });
}

function percentLabel(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return `${value}%`;
}
</script>

<template>
    <ProjectLayout :project="project" active="cost-control" title="Cost Control">
        <div class="space-y-4">
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="applyAsOf">
                <label class="text-xs text-slate-500">
                    As of
                    <input v-model="asOf" type="date" class="mt-1 block rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200" @change="applyAsOf" />
                </label>
                <p v-if="summary.status?.label" class="pb-2 text-sm text-slate-600">
                    {{ summary.status.label }}
                    <template v-if="summary.status.over_by"> · over by {{ formatMoney(summary.status.over_by) }}</template>
                </p>
                <AppButton
                    v-if="can.export"
                    :href="route('projects.cost-control.export', project.id)"
                    variant="secondary"
                    size="sm"
                    class="ml-auto"
                >
                    Export
                </AppButton>
            </form>

            <div v-if="totals" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <StatCard label="Original budget" :value="formatMoney(totals.original_budget)" icon="banknotes" tone="slate" />
                <StatCard label="Revised Main Budget" :value="formatMoney(totals.revised_budget)" icon="banknotes" tone="brand" />
                <StatCard label="Total assigned" :value="formatMoney(totals.total_assigned)" icon="scale" />
                <StatCard label="Main Budget Available" :value="formatMoney(totals.available_budget)" icon="check-circle" tone="green" />
                <StatCard label="BOQ Estimate" :value="formatMoney(totals.boq_estimate)" icon="scale" />
                <StatCard label="BOQ vs Main" :value="formatMoney(totals.boq_vs_main_budget)" icon="scale" tone="slate" />
                <StatCard label="Actual invoiced" :value="formatMoney(totals.actual_invoiced)" icon="banknotes" tone="orange" />
                <StatCard label="Open commitment" :value="formatMoney(totals.open_commitment)" icon="truck" />
                <StatCard label="GST exposure" :value="formatMoney(totals.gst_exposure)" icon="receipt" />
                <StatCard label="Assigned incl. GST" :value="formatMoney(totals.total_assigned_incl_gst)" icon="receipt" />
                <StatCard label="Cash paid" :value="formatMoney(summary.cash_paid)" icon="banknotes" />
                <StatCard label="Balance payable" :value="formatMoney(summary.balance_payable)" icon="receipt" />
            </div>

            <AppCard title="WBS / Cost head summary" subtitle="Main Project Budget controls commitments. BOQ Estimate is a technical reference only and is not included in Total Assigned. ETC/EAC/VAC remain deferred." :padded="false">
                <div v-if="heads.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">WBS</th>
                                <th class="px-3 py-2 text-right">Original</th>
                                <th class="px-3 py-2 text-right">Changes</th>
                                <th class="px-3 py-2 text-right">Revised</th>
                                <th class="px-3 py-2 text-right">BOQ Est.</th>
                                <th class="px-3 py-2 text-right">BOQ vs Main</th>
                                <th class="px-3 py-2 text-right">Invoiced</th>
                                <th class="px-3 py-2 text-right">Commitment</th>
                                <th class="px-3 py-2 text-right">Assigned</th>
                                <th class="px-3 py-2 text-right">Available</th>
                                <th class="px-3 py-2 text-right">% Assigned</th>
                                <th class="px-3 py-2 text-right">GST</th>
                                <th class="px-3 py-2 text-right">Assigned+GST</th>
                                <th class="px-3 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="head in heads" :key="head.id ?? head.code">
                                <td class="px-3 py-2">
                                    <Link :href="headHref(head)" class="font-medium text-slate-900 hover:text-brand-700">
                                        <span v-if="head.code" class="mr-2 font-mono text-xs text-slate-500">{{ head.code }}</span>{{ head.name }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.original_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(head.approved_changes)">{{ formatMoney(head.approved_changes) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.revised_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.boq_estimate) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(head.boq_vs_main_budget)">{{ formatMoney(head.boq_vs_main_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.actual_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.open_commitment) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.total_assigned) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(head.available_budget)">{{ formatMoney(head.available_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ percentLabel(head.percent_assigned) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.gst_exposure) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.total_assigned_incl_gst) }}</td>
                                <td class="px-3 py-2">
                                    <StatusBadge v-if="head.status" :status="head.status.key || head.status" :label="head.status.label" />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="totals" class="border-t border-line bg-slate-50 font-medium">
                            <tr>
                                <td class="px-3 py-2 text-slate-900">Total</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(totals.original_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(totals.approved_changes)">{{ formatMoney(totals.approved_changes) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(totals.revised_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(totals.boq_estimate) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(totals.boq_vs_main_budget)">{{ formatMoney(totals.boq_vs_main_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(totals.actual_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(totals.open_commitment) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(totals.total_assigned) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(totals.available_budget)">{{ formatMoney(totals.available_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ percentLabel(totals.percent_assigned) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(totals.gst_exposure) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(totals.total_assigned_incl_gst) }}</td>
                                <td class="px-3 py-2" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-else class="p-5 text-sm text-slate-500">No project cost heads.</p>
            </AppCard>

            <AppCard title="Budget versions" subtitle="Main Project Budget and BOQ Estimate keep independent version streams." :padded="false">
                <div v-if="versionRows.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-left">Version</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Source</th>
                                <th class="px-3 py-2 text-left">Approved</th>
                                <th class="px-3 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="version in versionRows" :key="version.id">
                                <td class="px-3 py-2 text-slate-700">{{ version.budget_type_label || version.budget_type || '—' }}</td>
                                <td class="px-3 py-2">
                                    <Link :href="route('projects.budget.index', [project.id, { budget_type: version.budget_type || 'main_project', version_id: version.id }])" class="font-medium text-brand-700 hover:underline">v{{ version.version }}</Link>
                                </td>
                                <td class="px-3 py-2"><StatusBadge :status="version.status" :label="version.status_label" /></td>
                                <td class="px-3 py-2 text-slate-700">{{ version.source_label || version.source || '—' }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ formatDate(version.approved_at) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(version.total_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="p-5 text-sm text-slate-500">No budget versions.</p>
            </AppCard>

            <AppCard title="Approved changes" subtitle="Revised budget less the original baseline, by cost head." :padded="false">
                <div v-if="changeRows.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Cost head</th>
                                <th class="px-3 py-2 text-right">Original</th>
                                <th class="px-3 py-2 text-right">Revised</th>
                                <th class="px-3 py-2 text-right">Approved changes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="head in changeRows" :key="head.id">
                                <td class="px-3 py-2">
                                    <Link :href="headHref(head)" class="font-medium text-brand-700 hover:underline">{{ head.code }} — {{ head.name }}</Link>
                                </td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.original_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.revised_budget) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(head.approved_changes)">{{ formatMoney(head.approved_changes) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="p-5 text-sm text-slate-500">No approved changes.</p>
            </AppCard>

            <AppCard title="Commitments" subtitle="Open purchase and work order value by cost head." :padded="false">
                <div v-if="commitmentRows.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Cost head</th>
                                <th class="px-3 py-2 text-right">Open commitment</th>
                                <th class="px-3 py-2 text-right">Invoiced</th>
                                <th class="px-3 py-2 text-right">Available</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="head in commitmentRows" :key="head.id">
                                <td class="px-3 py-2">
                                    <Link :href="headHref(head)" class="font-medium text-brand-700 hover:underline">{{ head.code }} — {{ head.name }}</Link>
                                </td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.open_commitment) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(head.actual_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(head.available_budget)">{{ formatMoney(head.available_budget) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="p-5 text-sm text-slate-500">No open commitments.</p>
            </AppCard>

            <AppCard title="Vendor / subcontractor exposure" subtitle="Retention held applies to certified subcontract bills net of approved releases. Vendor bills have no retention field in this ERP." :padded="false">
                <div v-if="vendorRows.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Party</th>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-left">Primary WBS</th>
                                <th class="px-3 py-2 text-right">Base orders</th>
                                <th class="px-3 py-2 text-right">Invoiced</th>
                                <th class="px-3 py-2 text-right">Open</th>
                                <th class="px-3 py-2 text-right">Retention</th>
                                <th class="px-3 py-2 text-right">Cash paid</th>
                                <th class="px-3 py-2 text-right">Payable</th>
                                <th class="px-3 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(vendor, index) in vendorRows" :key="vendor.vendor_id ?? vendor.subcontractor_id ?? index">
                                <td class="px-3 py-2 font-medium text-slate-900">{{ vendor.vendor_name || vendor.name }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ vendor.party_type || '—' }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ vendor.primary_wbs || '—' }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.total_base_orders ?? vendor.committed) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.actual_invoiced ?? vendor.billed) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.open_commitment) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.retention_held) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.net_cash_paid ?? vendor.paid) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(vendor.balance_payable ?? vendor.outstanding) }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ vendor.status || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="p-5 text-sm text-slate-500">No vendor exposure.</p>
            </AppCard>

            <AppCard :title="cashFlow?.plan?.name || 'Cash flow'" :subtitle="cashFlow?.message || 'Planned outflow against invoiced cost and cash paid. Forecast remaining (FAC) is deferred.'" :padded="false">
                <div class="space-y-3 p-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <p v-if="cashFlow?.plan_reconciles === false" class="text-sm text-amber-700">
                            Plan total {{ formatMoney(cashFlow?.plan?.total_planned) }} differs from Main Budget {{ formatMoney(cashFlow?.main_project_budget) }}
                            <template v-if="cashFlow?.plan_vs_budget"> ({{ formatMoney(cashFlow.plan_vs_budget) }})</template>.
                        </p>
                        <p v-else-if="cashFlow?.plan_reconciles === true" class="text-sm text-emerald-700">
                            Plan reconciles with Main Project Budget.
                        </p>
                        <AppButton
                            v-if="can.manageCashFlow"
                            type="button"
                            variant="secondary"
                            size="sm"
                            class="ml-auto"
                            @click="showPlanEditor = !showPlanEditor"
                        >
                            {{ showPlanEditor ? 'Close plan editor' : (cashFlow?.configured ? 'Edit cash flow plan' : 'Create cash flow plan') }}
                        </AppButton>
                    </div>

                    <form v-if="showPlanEditor && can.manageCashFlow" class="rounded-lg border border-line bg-slate-50 p-3" @submit.prevent="savePlan">
                        <FormInput v-model="planForm.name" label="Plan name" class="mb-3 max-w-md" :error="planForm.errors.name" />
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-[11px] tracking-wide text-slate-500 uppercase">
                                    <tr>
                                        <th class="px-2 py-1 text-left">Month</th>
                                        <th class="px-2 py-1 text-right">Planned outflow</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(month, idx) in planForm.months" :key="month.year_month" class="border-t border-line">
                                        <td class="px-2 py-2 text-slate-700">{{ monthLabel(month.year_month) }}</td>
                                        <td class="px-2 py-2">
                                            <MoneyInput v-model="planForm.months[idx].planned_amount" class="ml-auto max-w-[10rem]" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="planForm.errors.months" class="mt-2 text-xs text-rose-600">{{ planForm.errors.months }}</p>
                        <div class="mt-3 flex gap-2">
                            <AppButton type="submit" size="sm" :disabled="planForm.processing">Save plan</AppButton>
                        </div>
                    </form>
                </div>

                <div v-if="months.length" class="overflow-x-auto border-t border-line">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Month</th>
                                <th class="px-3 py-2 text-right">Planned</th>
                                <th class="px-3 py-2 text-right">Invoiced</th>
                                <th class="px-3 py-2 text-right">Cash paid</th>
                                <th class="px-3 py-2 text-right">Variance (Plan − Inv)</th>
                                <th class="px-3 py-2 text-right">Cumulative planned</th>
                                <th class="px-3 py-2 text-right">Cumulative cash</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="month in months" :key="month.year_month">
                                <td class="px-3 py-2 text-slate-800">{{ monthLabel(month) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(month.planned_outflow) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(month.actual_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(month.actual_cash_paid) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap" :class="moneyClass(month.variance_planned_minus_invoiced)">{{ formatMoney(month.variance_planned_minus_invoiced) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(month.cumulative_planned) }}</td>
                                <td class="px-3 py-2 text-right tabular whitespace-nowrap">{{ formatMoney(month.cumulative_cash_paid) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="p-5 text-sm text-slate-500">{{ cashFlow?.message || 'No cash flow plan.' }}</p>
            </AppCard>

            <AppCard title="Unallocated" subtitle="Cost not assigned to a project cost head.">
                <div class="flex flex-col gap-4">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div>
                            <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Invoiced</p>
                            <p class="mt-1 text-lg font-semibold tabular">{{ formatMoney(unallocatedRow?.actual_invoiced) }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Commitment</p>
                            <p class="mt-1 text-lg font-semibold tabular">{{ formatMoney(unallocatedRow?.open_commitment) }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Assigned</p>
                            <p class="mt-1 text-lg font-semibold tabular">{{ formatMoney(unallocatedRow?.total_assigned) }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">GST</p>
                            <p class="mt-1 text-lg font-semibold tabular">{{ formatMoney(unallocatedRow?.gst_exposure) }}</p>
                        </div>
                    </div>
                    <Link :href="route('projects.cost-control.unallocated', project.id)" class="text-sm font-medium text-brand-700 hover:underline">
                        View unallocated documents
                    </Link>
                </div>
            </AppCard>
        </div>
    </ProjectLayout>
</template>
