<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    formula_notes: { type: Object, default: () => ({}) },
    materials: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    status_options: { type: Array, default: () => [] },
    source_options: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const filterForm = useForm({
    material_id: props.filters.material_id ?? null,
    category_id: props.filters.category_id ?? null,
    boq_head: props.filters.boq_head ?? '',
    requirement_source: props.filters.requirement_source ?? null,
    status: props.filters.status ?? null,
    search: props.filters.search ?? '',
});

function applyFilters() {
    filterForm.get(route('projects.material-reconciliation.index', props.project.id), { preserveState: true });
}

function statusClass(status) {
    if (status === 'Over Issued') return 'bg-rose-100 text-rose-800';
    if (status === 'Under Issued') return 'bg-amber-100 text-amber-800';
    if (status === 'Procurement Pending') return 'bg-sky-100 text-sky-800';
    if (status === 'Within Plan') return 'bg-emerald-100 text-emerald-800';
    return 'bg-slate-100 text-slate-700';
}

const exportHref = route('projects.material-reconciliation.export', props.project.id) + '?' + new URLSearchParams(
    Object.fromEntries(Object.entries(filterForm.data()).filter(([, v]) => v !== null && v !== '')),
).toString();
</script>

<template>
    <ProjectLayout :project="project" title="Material Reconciliation" active="material-reconciliation">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Material Reconciliation</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Planned requirement vs procurement pipeline vs project Net Issued. Read-only analysis — no stock or cost posting.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="can.view_cost_report"
                    :href="route('reports.project', [project.id, 'estimated-vs-actual-cost'])"
                    class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    View Cost Variance
                </Link>
                <Link
                    :href="route('projects.steel-reconciliation.index', project.id)"
                    class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Steel Reconciliation
                </Link>
                <a
                    v-if="can.export"
                    :href="exportHref"
                    class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Export XLSX
                </a>
            </div>
        </div>

        <AppCard class="mb-4">
            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" @submit.prevent="applyFilters">
                <FormSelect
                    v-model="filterForm.material_id"
                    label="Material"
                    :options="[{ value: null, label: '— All —' }, ...materials]"
                />
                <FormSelect
                    v-model="filterForm.category_id"
                    label="Category"
                    :options="[{ value: null, label: '— All —' }, ...categories]"
                />
                <FormInput v-model="filterForm.boq_head" label="BOQ Head" />
                <FormSelect
                    v-model="filterForm.requirement_source"
                    label="Requirement Source"
                    :options="source_options"
                />
                <FormSelect
                    v-model="filterForm.status"
                    label="Status"
                    :options="status_options"
                />
                <FormInput v-model="filterForm.search" label="Search" />
                <div class="flex items-end xl:col-span-6">
                    <AppButton type="submit" :loading="filterForm.processing">Filter</AppButton>
                </div>
            </form>
            <p class="mt-3 text-xs text-slate-500">{{ formula_notes.net_issued }} {{ formula_notes.issue_variance }}</p>
        </AppCard>

        <AppCard>
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-[1500px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">Material</th>
                            <th class="px-2 py-2">Source</th>
                            <th class="px-2 py-2">Unit</th>
                            <th class="px-2 py-2 text-right">Base</th>
                            <th class="px-2 py-2 text-right">Wastage</th>
                            <th class="px-2 py-2 text-right">Allowed</th>
                            <th class="px-2 py-2 text-right">MR</th>
                            <th class="px-2 py-2 text-right">PO</th>
                            <th class="px-2 py-2 text-right">GRN</th>
                            <th class="px-2 py-2 text-right">Issued</th>
                            <th class="px-2 py-2 text-right">Returned</th>
                            <th class="px-2 py-2 text-right">Net Issued</th>
                            <th class="px-2 py-2 text-right">Central Stock</th>
                            <th class="px-2 py-2 text-right">Unalloc. Issue</th>
                            <th class="px-2 py-2 text-right">Issue Variance</th>
                            <th class="px-2 py-2 text-right">Var %</th>
                            <th class="px-2 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="`${row.material_id}-${row.unit}`" class="border-t border-line">
                            <td class="px-2 py-2">
                                <Link
                                    :href="route('projects.material-reconciliation.show', [project.id, row.material_id, { unit: row.unit }])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.material_code }} — {{ row.material_name }}
                                </Link>
                            </td>
                            <td class="px-2 py-2">
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-700">{{ row.requirement_source }}</span>
                            </td>
                            <td class="px-2 py-2">{{ row.unit }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.base_requirement) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.wastage_qty) }}</td>
                            <td class="px-2 py-2 text-right font-semibold tabular">{{ formatQty(row.allowed_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">
                                <Link
                                    :href="route('projects.material-reconciliation.sources', [project.id, row.material_id, { type: 'mr' }])"
                                    class="text-brand-700 hover:underline"
                                >{{ formatQty(row.mr_qty) }}</Link>
                            </td>
                            <td class="px-2 py-2 text-right tabular">
                                <Link
                                    :href="route('projects.material-reconciliation.sources', [project.id, row.material_id, { type: 'po' }])"
                                    class="text-brand-700 hover:underline"
                                >{{ formatQty(row.po_qty) }}</Link>
                            </td>
                            <td class="px-2 py-2 text-right tabular">
                                <Link
                                    :href="route('projects.material-reconciliation.sources', [project.id, row.material_id, { type: 'grn' }])"
                                    class="text-brand-700 hover:underline"
                                >{{ formatQty(row.grn_qty) }}</Link>
                                <div v-if="row.over_received_qty !== '0.0000'" class="text-[11px] text-amber-700">
                                    Over {{ formatQty(row.over_received_qty) }}
                                </div>
                            </td>
                            <td class="px-2 py-2 text-right tabular">
                                <Link
                                    :href="route('projects.material-reconciliation.sources', [project.id, row.material_id, { type: 'issue' }])"
                                    class="text-brand-700 hover:underline"
                                >{{ formatQty(row.issued_qty) }}</Link>
                            </td>
                            <td class="px-2 py-2 text-right tabular">
                                <Link
                                    :href="route('projects.material-reconciliation.sources', [project.id, row.material_id, { type: 'return' }])"
                                    class="text-brand-700 hover:underline"
                                >{{ formatQty(row.returned_qty) }}</Link>
                            </td>
                            <td class="px-2 py-2 text-right font-semibold tabular">{{ formatQty(row.net_issued) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.central_stock_available) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.unallocated_issue_qty) }}</td>
                            <td class="px-2 py-2 text-right font-medium tabular" :class="Number(row.issue_variance) < 0 ? 'text-rose-700' : ''">
                                {{ formatQty(row.issue_variance) }}
                                <div v-if="can.view_costs && row.estimated_variance_value != null" class="text-[11px] text-slate-400">
                                    Est. {{ row.estimated_variance_value }}
                                </div>
                            </td>
                            <td class="px-2 py-2 text-right tabular">{{ row.variance_percent ?? '—' }}</td>
                            <td class="px-2 py-2">
                                <span class="rounded px-1.5 py-0.5 text-[11px] font-medium" :class="statusClass(row.status)">{{ row.status }}</span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="17" class="px-3 py-8 text-center text-slate-500">No planned materials to reconcile yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-500">{{ formula_notes.stock }} {{ formula_notes.unallocated }}</p>
        </AppCard>
    </ProjectLayout>
</template>
