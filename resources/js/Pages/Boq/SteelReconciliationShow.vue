<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    detail: { type: Object, required: true },
    diameter_mm: { type: Number, required: true },
    material_id: { type: Number, required: true },
});

const header = computed(() => props.detail.header);
const lines = computed(() => props.detail.lines ?? []);
</script>

<template>
    <ProjectLayout :project="project" title="Steel Reconciliation" active="steel-reconciliation">
        <div class="mb-4">
            <Link :href="route('projects.steel-reconciliation.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">
                ← Steel Reconciliation
            </Link>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ diameter_mm }} mm steel</h1>
            <p v-if="header" class="mt-1 text-sm text-slate-500">
                {{ header.material_code }} — {{ header.material_name }}. BBS required already includes wastage.
            </p>
        </div>

        <div v-if="header" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard>
                <p class="text-xs text-slate-500">BBS Required</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(header.required_weight) }} kg</p>
                <p class="mt-1 text-[11px] text-slate-400">
                    Theo {{ formatQty(header.theoretical_weight) }} + waste {{ formatQty(header.wastage_weight) }}
                </p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Net Issued</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(header.net_issued) }} kg</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Issue Variance</p>
                <p class="mt-1 text-lg font-semibold tabular" :class="Number(header.issue_variance) < 0 ? 'text-rose-700' : ''">
                    {{ formatQty(header.issue_variance) }} kg
                </p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Procurement (material)</p>
                <p class="mt-1 text-sm tabular">
                    MR {{ formatQty(header.mr_qty) }} · PO {{ formatQty(header.po_qty) }} · GRN {{ formatQty(header.grn_qty) }}
                </p>
            </AppCard>
        </div>

        <AppCard title="BBS records">
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-[1000px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">BBS</th>
                            <th class="px-2 py-2">BOQ Item</th>
                            <th class="px-2 py-2 text-right">Theoretical</th>
                            <th class="px-2 py-2 text-right">Wastage</th>
                            <th class="px-2 py-2 text-right">Required</th>
                            <th class="px-2 py-2">Mapped Material</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, idx) in lines" :key="`${row.bbs_id}-${idx}`" class="border-t border-line">
                            <td class="px-2 py-2">
                                <div class="font-medium">{{ row.bbs_title }}</div>
                                <div class="text-[11px] text-slate-400">#{{ row.bbs_id }} · {{ row.boq_number }}</div>
                            </td>
                            <td class="px-2 py-2">
                                <Link
                                    v-if="row.boq_id && row.boq_item_id"
                                    :href="route('projects.boqs.items.workspace', [project.id, row.boq_id, row.boq_item_id, { bbs: 1 }])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.item_code ? `${row.item_code} — ` : '' }}{{ row.item_name }}
                                </Link>
                            </td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.theoretical_weight) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.wastage_weight) }} ({{ formatQty(row.wastage_percent) }}%)</td>
                            <td class="px-2 py-2 text-right font-medium tabular">{{ formatQty(row.required_weight) }}</td>
                            <td class="px-2 py-2">{{ row.mapped_material }}</td>
                        </tr>
                        <tr v-if="!lines.length">
                            <td colspan="6" class="px-3 py-6 text-center text-slate-500">No BBS lines for this diameter / material.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="detail.procurement?.note" class="mt-3 text-xs text-slate-500">{{ detail.procurement.note }}</p>
        </AppCard>
    </ProjectLayout>
</template>
