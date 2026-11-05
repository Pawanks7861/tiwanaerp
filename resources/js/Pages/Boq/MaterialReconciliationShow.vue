<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    material: { type: Object, required: true },
    detail: { type: Object, required: true },
    unit: { type: String, default: null },
    can: { type: Object, required: true },
});

const summary = computed(() => props.detail.summary);
const items = computed(() => props.detail.items ?? []);
</script>

<template>
    <ProjectLayout :project="project" title="Material Reconciliation" active="material-reconciliation">
        <div class="mb-4">
            <Link :href="route('projects.material-reconciliation.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">
                ← Material Reconciliation
            </Link>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ material.code }} — {{ material.name }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                Planning, procurement, inventory Net Issued, and BOQ item drill-down{{ unit ? ` (${unit})` : '' }}.
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
                <Link
                    v-if="can.view_cost_report"
                    :href="route('reports.project', [project.id, 'estimated-vs-actual-cost'])"
                    class="text-xs font-medium text-brand-700 hover:underline"
                >
                    View Cost Variance
                </Link>
            </div>
        </div>

        <div v-if="summary" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard>
                <p class="text-xs text-slate-500">Allowed / Required</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(summary.allowed_qty) }} {{ summary.unit }}</p>
                <p class="mt-1 text-[11px] text-slate-400">Base {{ formatQty(summary.base_requirement) }} + wastage {{ formatQty(summary.wastage_qty) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Procurement</p>
                <p class="mt-1 text-sm tabular">MR {{ formatQty(summary.mr_qty) }} · PO {{ formatQty(summary.po_qty) }} · GRN {{ formatQty(summary.grn_qty) }}</p>
                <p class="mt-1 text-[11px] text-slate-400">
                    Unreq {{ formatQty(summary.unrequested_qty) }} · Unord {{ formatQty(summary.unordered_qty) }} · Pending {{ formatQty(summary.pending_receipt_qty) }}
                </p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Project Net Issued</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(summary.net_issued) }}</p>
                <p class="mt-1 text-[11px] text-slate-400">Issue {{ formatQty(summary.issued_qty) }} − Return {{ formatQty(summary.returned_qty) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Issue Variance</p>
                <p class="mt-1 text-lg font-semibold tabular" :class="Number(summary.issue_variance) < 0 ? 'text-rose-700' : ''">
                    {{ formatQty(summary.issue_variance) }}
                </p>
                <p class="mt-1 text-[11px] text-slate-400">
                    {{ summary.status }}
                    <span v-if="summary.variance_percent != null"> · {{ summary.variance_percent }}%</span>
                </p>
            </AppCard>
        </div>

        <div v-if="summary" class="mb-4 grid gap-3 sm:grid-cols-2">
            <AppCard>
                <p class="text-xs text-slate-500">Central Stock Available</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(summary.central_stock_available) }}</p>
                <p class="mt-1 text-[11px] text-slate-400">Company warehouse balance — not reserved for this project.</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Unallocated Issue Qty</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ formatQty(detail.unallocated_issue_qty) }}</p>
                <p class="mt-1 text-[11px] text-slate-400">Project issues without BOQ item link — not allocated into item rows below.</p>
            </AppCard>
        </div>

        <AppCard title="BOQ item sources">
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-[1100px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2">BOQ Item</th>
                            <th class="px-2 py-2">Source</th>
                            <th class="px-2 py-2 text-right">BOQ Qty</th>
                            <th class="px-2 py-2 text-right">Progress %</th>
                            <th class="px-2 py-2 text-right">Required</th>
                            <th class="px-2 py-2 text-right">Net Issued</th>
                            <th class="px-2 py-2 text-right">Variance</th>
                            <th class="px-2 py-2 text-right">Progress Var</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, idx) in items" :key="`${row.boq_item_id}-${idx}`" class="border-t border-line">
                            <td class="px-2 py-2">
                                <Link
                                    v-if="row.boq_id && row.boq_item_id"
                                    :href="route('projects.boqs.items.workspace', [project.id, row.boq_id, row.boq_item_id])"
                                    class="font-medium text-brand-700 hover:underline"
                                >
                                    {{ row.item_code ? `${row.item_code} — ` : '' }}{{ row.item_name }}
                                </Link>
                                <span v-else>{{ row.item_code }} {{ row.item_name }}</span>
                                <div class="text-[11px] text-slate-400">{{ row.boq_number }}</div>
                            </td>
                            <td class="px-2 py-2">{{ row.requirement_source }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.boq_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ row.progress_percent ?? '—' }}</td>
                            <td class="px-2 py-2 text-right tabular">{{ formatQty(row.required_qty) }}</td>
                            <td class="px-2 py-2 text-right tabular">
                                <template v-if="row.issue_traceable">{{ formatQty(row.net_issued) }}</template>
                                <span v-else class="text-slate-400">Not tagged</span>
                            </td>
                            <td class="px-2 py-2 text-right tabular">
                                <template v-if="row.issue_variance != null">{{ formatQty(row.issue_variance) }}</template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-2 py-2 text-right tabular">
                                <template v-if="row.progress_variance != null">{{ formatQty(row.progress_variance) }}</template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!items.length">
                            <td colspan="8" class="px-3 py-6 text-center text-slate-500">No linked BOQ sources.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
