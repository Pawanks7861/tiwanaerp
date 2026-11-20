<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import StatCard from '@/Components/Data/StatCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatQty } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    summary: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    formula_notes: { type: Object, default: () => ({}) },
    materials: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    status_options: { type: Array, default: () => [] },
    budgets: { type: Array, default: () => [] },
    boqs: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const filterForm = useForm({
    material_id: props.filters.material_id ?? null,
    category_id: props.filters.category_id ?? null,
    status: props.filters.status ?? null,
    search: props.filters.search ?? '',
    budget_version_id: props.filters.budget_version_id ?? null,
    boq_id: props.filters.boq_id ?? null,
    as_of_date: props.filters.as_of_date ?? '',
    over_consumption: props.filters.over_consumption ? 1 : null,
    unestimated_only: props.filters.unestimated_only ? 1 : null,
    page: props.pagination.page ?? 1,
});

function applyFilters() {
    filterForm.page = 1;
    filterForm.get(route('projects.material-control.index', props.project.id), { preserveState: true });
}

function goPage(page) {
    filterForm.page = page;
    filterForm.get(route('projects.material-control.index', props.project.id), { preserveState: true });
}

function statusClass(status) {
    if (status === 'Over Consumption') return 'bg-rose-100 text-rose-800';
    if (status === 'Near Limit') return 'bg-amber-100 text-amber-800';
    if (status === 'Unestimated Material') return 'bg-violet-100 text-violet-800';
    if (status === 'Budget Sync Required' || status === 'BOQ Estimate Outdated') return 'bg-orange-100 text-orange-800';
    if (status === 'On Track') return 'bg-emerald-100 text-emerald-800';
    return 'bg-slate-100 text-slate-700';
}

const exportHref =
    route('projects.material-control.export', props.project.id) +
    '?' +
    new URLSearchParams(
        Object.fromEntries(Object.entries(filterForm.data()).filter(([, v]) => v !== null && v !== '')),
    ).toString();
</script>

<template>
    <ProjectLayout :project="project" title="Material Control" active="material-control">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Material Control</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Estimate → Budget → MR → PO → GRN → Issue → Net Consumption. Estimate is project total;
                    actuals are cumulative to {{ meta.as_of_date }}.
                </p>
                <p v-if="meta.sync_status?.label" class="mt-1 text-xs font-medium text-amber-800">
                    Budget sync: {{ meta.sync_status.label }}
                    <span v-if="meta.budget_version"> · Budget v{{ meta.budget_version }}</span>
                    <span v-if="meta.boq_version"> · BOQ v{{ meta.boq_version }}</span>
                </p>
            </div>
            <a
                v-if="can.export"
                :href="exportHref"
                class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
                Export XLSX
            </a>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard v-if="can.view_costs" label="Estimated material cost" :value="formatMoney(summary.estimated_material_cost)" />
            <StatCard v-if="can.view_costs" label="Actual consumption cost" :value="formatMoney(summary.actual_material_consumption_cost)" />
            <StatCard v-if="can.view_costs" label="Cost variance" :value="formatMoney(summary.cost_variance)" />
            <StatCard label="Over-consumed materials" :value="String(summary.over_consumed_materials ?? 0)" />
            <StatCard label="Unestimated materials" :value="String(summary.unestimated_materials ?? 0)" />
            <StatCard label="Pending procurement" :value="String(summary.pending_procurement ?? 0)" />
            <StatCard label="Estimate lines" :value="String(summary.estimated_material_lines ?? 0)" />
            <StatCard v-if="can.view_costs" label="Budgeted material cost" :value="formatMoney(summary.budgeted_material_cost)" />
        </div>

        <AppCard class="mb-4">
            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" @submit.prevent="applyFilters">
                <FormSelect v-model="filterForm.material_id" label="Material" :options="[{ value: null, label: '— All —' }, ...materials]" />
                <FormSelect v-model="filterForm.category_id" label="Category" :options="[{ value: null, label: '— All —' }, ...categories]" />
                <FormSelect v-model="filterForm.status" label="Status" :options="status_options" />
                <FormSelect v-model="filterForm.budget_version_id" label="Budget version" :options="[{ value: null, label: '— Active —' }, ...budgets]" />
                <FormSelect v-model="filterForm.boq_id" label="BOQ revision" :options="[{ value: null, label: '— Current / linked —' }, ...boqs]" />
                <FormInput v-model="filterForm.as_of_date" label="As of date" type="date" />
                <FormInput v-model="filterForm.search" label="Search" />
                <label class="flex items-end gap-2 pb-2 text-sm text-slate-700">
                    <input v-model="filterForm.over_consumption" type="checkbox" true-value="1" false-value="" class="rounded border-line" />
                    Over consumption only
                </label>
                <label class="flex items-end gap-2 pb-2 text-sm text-slate-700">
                    <input v-model="filterForm.unestimated_only" type="checkbox" true-value="1" false-value="" class="rounded border-line" />
                    Unestimated only
                </label>
                <div class="flex items-end">
                    <AppButton type="submit">Apply</AppButton>
                </div>
            </form>
        </AppCard>

        <AppCard>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-3 py-2 text-left">Material</th>
                            <th class="px-3 py-2 text-right">Estimate</th>
                            <th class="px-3 py-2 text-right">Budget</th>
                            <th class="px-3 py-2 text-right">MR</th>
                            <th class="px-3 py-2 text-right">PO</th>
                            <th class="px-3 py-2 text-right">GRN</th>
                            <th class="px-3 py-2 text-right">Issued</th>
                            <th class="px-3 py-2 text-right">Returned</th>
                            <th class="px-3 py-2 text-right">Net</th>
                            <th class="px-3 py-2 text-right">Remaining</th>
                            <th class="px-3 py-2 text-right">Var</th>
                            <th class="px-3 py-2 text-right">Var %</th>
                            <th v-if="can.view_costs" class="px-3 py-2 text-right">Est cost</th>
                            <th v-if="can.view_costs" class="px-3 py-2 text-right">Act cost</th>
                            <th v-if="can.view_costs" class="px-3 py-2 text-right">Cost var</th>
                            <th class="px-3 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows" :key="row.material_id" class="hover:bg-slate-50/80">
                            <td class="px-3 py-2">
                                <Link
                                    :href="route('projects.material-control.show', [project.id, row.material_id])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.material_code }} — {{ row.material_name }}
                                </Link>
                                <div class="text-[11px] text-slate-500">{{ row.unit }}</div>
                            </td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.estimated_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.budget_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.mr_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.po_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.grn_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.issued_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.returned_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular font-medium">{{ formatQty(row.net_consumed_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.remaining_estimate) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.variance_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular text-xs">{{ row.variance_percent_label ?? '—' }}</td>
                            <td v-if="can.view_costs" class="px-3 py-2 text-right tabular">{{ formatMoney(row.estimated_cost) }}</td>
                            <td v-if="can.view_costs" class="px-3 py-2 text-right tabular">{{ formatMoney(row.actual_consumption_cost) }}</td>
                            <td v-if="can.view_costs" class="px-3 py-2 text-right tabular">{{ formatMoney(row.cost_variance) }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusClass(row.status)">
                                    {{ row.status }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="can.view_costs ? 16 : 13" class="px-3 py-8 text-center text-slate-500">No materials match these filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="pagination.last_page > 1" class="mt-3 flex items-center justify-between text-sm text-slate-600">
                <span>Page {{ pagination.page }} of {{ pagination.last_page }} · {{ pagination.total }} materials</span>
                <div class="flex gap-2">
                    <AppButton variant="secondary" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">Prev</AppButton>
                    <AppButton variant="secondary" :disabled="pagination.page >= pagination.last_page" @click="goPage(pagination.page + 1)">Next</AppButton>
                </div>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
