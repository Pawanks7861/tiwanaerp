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
    filters: { type: Object, default: () => ({}) },
    formula_notes: { type: Object, default: () => ({}) },
    materials: { type: Array, default: () => [] },
    status_options: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const filterForm = useForm({
    diameter_mm: props.filters.diameter_mm ?? null,
    material_id: props.filters.material_id ?? null,
    boq_item_id: props.filters.boq_item_id ?? null,
    bbs_id: props.filters.bbs_id ?? null,
    status: props.filters.status ?? null,
});

function applyFilters() {
    filterForm.get(route('projects.steel-reconciliation.index', props.project.id), { preserveState: true });
}

function statusClass(status) {
    if (status === 'Over Issued') return 'bg-rose-100 text-rose-800';
    if (status === 'Under Issued') return 'bg-amber-100 text-amber-800';
    if (status === 'Procurement Pending') return 'bg-sky-100 text-sky-800';
    if (status === 'Within Plan') return 'bg-emerald-100 text-emerald-800';
    return 'bg-slate-100 text-slate-700';
}

const exportHref = route('projects.steel-reconciliation.export', props.project.id) + '?' + new URLSearchParams(
    Object.fromEntries(Object.entries(filterForm.data()).filter(([, v]) => v !== null && v !== '')),
).toString();
</script>

<template>
    <ProjectLayout :project="project" title="Steel Reconciliation" active="steel-reconciliation">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Steel Reconciliation</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Approved BBS theoretical / wastage / required weight vs Net Issued (kg). Mapped materials are not interchangeable unless BBS mapping says so.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    :href="route('projects.material-reconciliation.index', project.id)"
                    class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Material Reconciliation
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
            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="applyFilters">
                <FormInput v-model="filterForm.diameter_mm" label="Diameter (mm)" type="number" />
                <FormSelect
                    v-model="filterForm.material_id"
                    label="Material"
                    :options="[{ value: null, label: '— All —' }, ...materials]"
                />
                <FormInput v-model="filterForm.boq_item_id" label="BOQ Item ID" type="number" />
                <FormInput v-model="filterForm.bbs_id" label="BBS ID" type="number" />
                <FormSelect
                    v-model="filterForm.status"
                    label="Status"
                    :options="status_options"
                />
                <div class="flex items-end lg:col-span-5">
                    <AppButton type="submit" :loading="filterForm.processing">Filter</AppButton>
                </div>
            </form>
            <p class="mt-3 text-xs text-slate-500">{{ formula_notes.steel_variance }} {{ formula_notes.bbs_authority }}</p>
        </AppCard>

        <AppCard>
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-[1300px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">Diameter</th>
                            <th class="px-2 py-2">Mapped Material</th>
                            <th class="px-2 py-2 text-right">Theoretical</th>
                            <th class="px-2 py-2 text-right">Wastage</th>
                            <th class="px-2 py-2 text-right">Required</th>
                            <th class="px-2 py-2 text-right">MT</th>
                            <th class="px-2 py-2 text-right">MR</th>
                            <th class="px-2 py-2 text-right">PO</th>
                            <th class="px-2 py-2 text-right">GRN</th>
                            <th class="px-2 py-2 text-right">Issue</th>
                            <th class="px-2 py-2 text-right">Return</th>
                            <th class="px-2 py-2 text-right">Net Issued</th>
                            <th class="px-2 py-2 text-right">Stock</th>
                            <th class="px-2 py-2 text-right">Variance</th>
                            <th class="px-2 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="`${row.diameter_mm}-${row.material_id}`" class="border-t border-line">
                            <td class="px-2 py-2">
                                <Link
                                    :href="route('projects.steel-reconciliation.show', [project.id, { diameter_mm: row.diameter_mm, material_id: row.material_id }])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.diameter_mm }} mm
                                </Link>
                            </td>
                            <td class="px-2 py-2">{{ row.material_code }} — {{ row.material_name }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.theoretical_weight) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.wastage_weight) }}</td>
                            <td class="px-2 py-2 text-right font-semibold tabular">{{ formatQty(row.required_weight) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.required_weight_mt) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.mr_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.po_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.grn_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.issued_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.returned_qty) }}</td>
                            <td class="px-2 py-2 text-right font-semibold tabular">{{ formatQty(row.net_issued) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.central_stock_available) }}</td>
                            <td class="px-2 py-2 text-right font-medium tabular" :class="Number(row.issue_variance) < 0 ? 'text-rose-700' : ''">
                                {{ formatQty(row.issue_variance) }}
                                <div class="text-[11px] text-slate-400">{{ row.variance_percent ?? '—' }}%</div>
                            </td>
                            <td class="px-2 py-2">
                                <span class="rounded px-1.5 py-0.5 text-[11px] font-medium" :class="statusClass(row.status)">{{ row.status }}</span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="15" class="px-3 py-8 text-center text-slate-500">No approved BBS steel mappings yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
