<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    materials: { type: Array, default: () => [] },
    formula_note: { type: String, default: '' },
    can: { type: Object, required: true },
});

const filterForm = useForm({
    material_id: props.filters.material_id ?? null,
    search: props.filters.search ?? '',
});

const selected = ref({});

const selectedLines = computed(() => props.rows.filter((r) => selected.value[r.material_id]));

function applyFilters() {
    filterForm.get(route('projects.material-requirement.index', props.project.id), { preserveState: true });
}

function toggleAll(on) {
    const next = {};
    if (on) {
        props.rows.forEach((r) => { next[r.material_id] = true; });
    }
    selected.value = next;
}

function createMr() {
    if (!selectedLines.value.length) return;
    router.post(route('projects.material-requirement.create-mr', props.project.id), {
        lines: selectedLines.value.map((r) => ({
            material_id: r.material_id,
            quantity: r.remaining_requirement !== '0.0000' ? r.remaining_requirement : r.required_qty,
            unit: r.unit,
            remarks: `BOQ Material Requirement · ${r.material_code}`,
        })),
    });
}
</script>

<template>
    <ProjectLayout :project="project" title="Material Requirement" active="material-requirement">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Material Requirement</h1>
                <p class="mt-1 text-sm text-slate-500">Planned BOQ material need. Not stock, not actual consumption.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a
                    v-if="can.export"
                    :href="route('projects.material-requirement.export', project.id)"
                    class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Export XLSX
                </a>
                <AppButton v-if="can.create_mr" type="button" :disabled="!selectedLines.length" @click="createMr">
                    Create MR ({{ selectedLines.length }})
                </AppButton>
            </div>
        </div>

        <AppCard class="mb-4">
            <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="applyFilters">
                <FormSelect
                    v-model="filterForm.material_id"
                    label="Material"
                    :options="[{ value: null, label: '— All —' }, ...materials]"
                />
                <FormInput v-model="filterForm.search" label="Search" />
                <div class="flex items-end">
                    <AppButton type="submit" :loading="filterForm.processing">Filter</AppButton>
                </div>
            </form>
            <p class="mt-3 text-xs text-slate-500">{{ formula_note }}</p>
        </AppCard>

        <AppCard>
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-[1200px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">
                                <input type="checkbox" @change="toggleAll($event.target.checked)" />
                            </th>
                            <th class="px-2 py-2">Material</th>
                            <th class="px-2 py-2">Source</th>
                            <th class="px-2 py-2">Unit</th>
                            <th class="px-2 py-2 text-right">Base</th>
                            <th class="px-2 py-2 text-right">Wastage %</th>
                            <th class="px-2 py-2 text-right">Wastage Qty</th>
                            <th class="px-2 py-2 text-right">Required</th>
                            <th class="px-2 py-2 text-right">MR</th>
                            <th class="px-2 py-2 text-right">PO</th>
                            <th class="px-2 py-2 text-right">Received</th>
                            <th class="px-2 py-2 text-right">Issued</th>
                            <th class="px-2 py-2 text-right">Returned</th>
                            <th class="px-2 py-2 text-right">Stock</th>
                            <th class="px-2 py-2 text-right">Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="`${row.material_id}-${row.unit}`" class="border-t border-line">
                            <td class="px-2 py-2">
                                <input v-model="selected[row.material_id]" type="checkbox" />
                            </td>
                            <td class="px-2 py-2">
                                <Link
                                    :href="route('projects.material-requirement.show', [project.id, row.material_id, { unit: row.unit }])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.material_code }} — {{ row.material_name }}
                                </Link>
                                <div class="text-[11px] text-slate-400">{{ row.sources_count }} source(s)</div>
                            </td>
                            <td class="px-2 py-2">
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-700">{{ row.requirement_source || 'Material Norm' }}</span>
                            </td>
                            <td class="px-2 py-2">{{ row.unit }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.base_requirement) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.wastage_percent) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.wastage_qty) }}</td>
                            <td class="px-2 py-2 text-right font-semibold tabular">{{ formatQty(row.required_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.mr_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.po_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.received_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.issued_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.returned_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.available_stock) }}</td>
                            <td class="px-2 py-2 text-right font-medium tabular">
                                {{ formatQty(row.remaining_requirement) }}
                                <div v-if="row.over_procured_qty !== '0.0000'" class="text-[11px] text-amber-700">
                                    Over {{ formatQty(row.over_procured_qty) }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="15" class="px-3 py-8 text-center text-slate-500">No material norms or approved BBS steel on contributing BOQs yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
