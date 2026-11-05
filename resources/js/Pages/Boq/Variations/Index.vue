<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    contract: { type: Object, default: null },
    status_options: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const filterForm = useForm({
    status: props.filters.status ?? null,
    search: props.filters.search ?? '',
});

function applyFilters() {
    filterForm.get(route('projects.variations.index', props.project.id), { preserveState: true });
}

function statusClass(status) {
    if (status === 'approved') return 'bg-emerald-100 text-emerald-800';
    if (status === 'submitted') return 'bg-sky-100 text-sky-800';
    if (status === 'rejected') return 'bg-rose-100 text-rose-800';
    return 'bg-slate-100 text-slate-700';
}
</script>

<template>
    <ProjectLayout :project="project" title="Variation Register" active="variations">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Variation Register</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Approved variations change effective BOQ scope. Draft / submitted / rejected have zero operational effect.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a
                    v-if="can.export"
                    :href="route('projects.variations.export', project.id)"
                    class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Export XLSX
                </a>
                <Link v-if="can.manage" :href="route('projects.variations.create', project.id)">
                    <AppButton type="button">New Variation</AppButton>
                </Link>
            </div>
        </div>

        <div v-if="contract" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard>
                <p class="text-xs text-slate-500">Original Contract</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ contract.original_contract_value }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Approved Variations</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ contract.approved_variation_value }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Pending Variations</p>
                <p class="mt-1 text-lg font-semibold tabular text-amber-800">{{ contract.pending_variation_value }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Effective Contract</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ contract.effective_contract_value }}</p>
            </AppCard>
        </div>

        <AppCard class="mb-4">
            <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="applyFilters">
                <FormSelect v-model="filterForm.status" label="Status" :options="status_options" />
                <FormInput v-model="filterForm.search" label="Search" />
                <div class="flex items-end">
                    <AppButton type="submit" :loading="filterForm.processing">Filter</AppButton>
                </div>
            </form>
        </AppCard>

        <AppCard>
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-[960px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">Variation</th>
                            <th class="px-2 py-2">Type</th>
                            <th class="px-2 py-2">Source</th>
                            <th class="px-2 py-2">Status</th>
                            <th v-if="can.view_costs" class="px-2 py-2 text-right">Revenue</th>
                            <th v-if="can.view_costs" class="px-2 py-2 text-right">Cost</th>
                            <th class="px-2 py-2">Approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-t border-line">
                            <td class="px-2 py-2">
                                <Link
                                    :href="route('projects.variations.show', [project.id, row.id])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.variation_number }}
                                </Link>
                                <div class="text-slate-500">{{ row.title }}</div>
                            </td>
                            <td class="px-2 py-2">{{ row.change_order_type_label }}</td>
                            <td class="px-2 py-2">{{ row.source_type_label }}</td>
                            <td class="px-2 py-2">
                                <span class="rounded px-1.5 py-0.5 text-[11px] font-medium" :class="statusClass(row.status)">
                                    {{ row.status_label }}
                                </span>
                            </td>
                            <td v-if="can.view_costs" class="px-2 py-2 text-right tabular">{{ row.revenue_impact }}</td>
                            <td v-if="can.view_costs" class="px-2 py-2 text-right tabular">{{ row.cost_impact }}</td>
                            <td class="px-2 py-2">
                                <div>{{ row.approved_by || '—' }}</div>
                                <div class="text-[11px] text-slate-400">{{ row.approved_at || '' }}</div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="can.view_costs ? 7 : 5" class="px-3 py-8 text-center text-slate-500">No variations yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
