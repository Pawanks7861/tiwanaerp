<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    material: { type: Object, required: true },
    summary: { type: Object, default: null },
    sources: { type: Array, default: () => [] },
    unit: { type: String, default: null },
});

const isBbs = computed(() => (props.sources[0]?.requirement_source || props.summary?.requirement_source) === 'BBS');
</script>

<template>
    <ProjectLayout :project="project" title="Material Requirement" active="material-requirement">
        <div class="mb-4">
            <Link :href="route('projects.material-requirement.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">
                ← Material Requirement
            </Link>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ material.code }} — {{ material.name }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ isBbs ? 'Approved BBS steel sources' : 'BOQ items that generate this planned requirement' }}{{ unit ? ` (${unit})` : '' }}.
            </p>
            <p v-if="isBbs" class="mt-1 text-xs font-medium text-emerald-700">Steel requirement source: Approved BBS</p>
        </div>

        <div v-if="summary" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard>
                <p class="text-xs text-slate-500">Required</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(summary.required_qty) }} {{ summary.unit }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Received</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(summary.received_qty) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Remaining</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(summary.remaining_requirement) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Central stock</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(summary.available_stock) }}</p>
            </AppCard>
        </div>

        <AppCard :title="isBbs ? 'BBS sources' : 'Source BOQ items'">
            <div class="overflow-x-auto rounded-xl border border-line">
                <table v-if="isBbs" class="min-w-[960px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">BOQ</th>
                            <th class="px-2 py-2">Item</th>
                            <th class="px-2 py-2">BBS</th>
                            <th class="px-2 py-2">Dia</th>
                            <th class="px-2 py-2 text-right">Theoretical</th>
                            <th class="px-2 py-2 text-right">Wastage</th>
                            <th class="px-2 py-2 text-right">Required</th>
                            <th class="px-2 py-2">Mapped material</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, idx) in sources" :key="`${row.bbs_id}-${row.diameter_mm}-${idx}`" class="border-t border-line">
                            <td class="px-2 py-2">{{ row.boq_number }}</td>
                            <td class="px-2 py-2">
                                <Link
                                    :href="route('projects.boqs.items.workspace', [project.id, row.boq_id, row.boq_item_id, { bbs: 1 }])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.item_code ? `${row.item_code} — ` : '' }}{{ row.item_name }}
                                </Link>
                            </td>
                            <td class="px-2 py-2">{{ row.bbs_title }}</td>
                            <td class="px-2 py-2">{{ row.diameter_mm }} mm</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.theoretical_weight) }} kg</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.wastage_weight) }} ({{ formatQty(row.wastage_percent) }}%)</td>
                            <td class="px-2 py-2 text-right font-medium tabular">{{ formatQty(row.required_qty) }} kg</td>
                            <td class="px-2 py-2">{{ row.mapped_material }}</td>
                        </tr>
                        <tr v-if="!sources.length">
                            <td colspan="8" class="px-3 py-6 text-center text-slate-500">No BBS sources.</td>
                        </tr>
                    </tbody>
                </table>

                <table v-else class="min-w-[900px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">BOQ</th>
                            <th class="px-2 py-2">Item</th>
                            <th class="px-2 py-2 text-right">BOQ Qty</th>
                            <th class="px-2 py-2 text-right">Norm</th>
                            <th class="px-2 py-2 text-right">Base</th>
                            <th class="px-2 py-2 text-right">Wastage</th>
                            <th class="px-2 py-2 text-right">Required</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in sources" :key="row.norm_id" class="border-t border-line">
                            <td class="px-2 py-2">{{ row.boq_number }}</td>
                            <td class="px-2 py-2">
                                <Link
                                    :href="route('projects.boqs.items.workspace', [project.id, row.boq_id, row.boq_item_id, { materials: 1 }])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.item_code ? `${row.item_code} — ` : '' }}{{ row.item_name }}
                                </Link>
                            </td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.boq_qty) }} {{ row.boq_unit }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.consumption_qty) }} {{ row.consumption_unit }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.base_requirement) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.wastage_qty) }} ({{ formatQty(row.wastage_percent) }}%)</td>
                            <td class="px-2 py-2 text-right font-medium tabular">{{ formatQty(row.required_qty) }}</td>
                        </tr>
                        <tr v-if="!sources.length">
                            <td colspan="7" class="px-3 py-6 text-center text-slate-500">No sources.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
