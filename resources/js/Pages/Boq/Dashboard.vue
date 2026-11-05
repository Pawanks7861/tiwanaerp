<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    cards: { type: Object, required: true },
    contract: { type: Object, required: true },
    health: { type: Object, required: true },
    variation_widget: { type: Object, required: true },
    can: { type: Object, required: true },
    links: { type: Object, required: true },
});

function money(v) {
    if (v == null || v === '') return '—';
    return v;
}
</script>

<template>
    <ProjectLayout :project="project" title="BOQ Dashboard" active="boq-dashboard">
        <div class="mb-4">
            <h1 class="text-xl font-semibold text-slate-900">BOQ Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">
                Original contract + approved variations = effective scope. Draft variations have zero operational effect.
            </p>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard>
                <p class="text-xs text-slate-500">Original Contract</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.original_contract_value) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Approved Variations</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.approved_variation_value) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Effective Contract</p>
                <p class="mt-1 text-lg font-semibold tabular text-emerald-800">{{ money(cards.effective_contract_value) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Pending Variations</p>
                <p class="mt-1 text-lg font-semibold tabular text-amber-800">{{ money(cards.pending_variation_value) }}</p>
            </AppCard>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard v-if="can.view_costs">
                <p class="text-xs text-slate-500">Estimated Cost</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.estimated_cost) }}</p>
                <p v-if="cards.original_estimated_cost" class="mt-1 text-[11px] text-slate-400">
                    Original {{ money(cards.original_estimated_cost) }} · VO {{ money(cards.variation_estimated_cost) }}
                </p>
            </AppCard>
            <AppCard v-if="can.view_costs">
                <p class="text-xs text-slate-500">Actual Cost</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.actual_cost) }}</p>
            </AppCard>
            <AppCard v-if="can.view_costs">
                <p class="text-xs text-slate-500">Est. Margin (contract − estimate)</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.estimated_margin) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Progress %</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ cards.progress_percent != null ? `${cards.progress_percent}%` : '—' }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Billed Value</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.billed_value) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Received</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.received_value) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Outstanding</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.outstanding_value) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs text-slate-500">Remaining Contract</p>
                <p class="mt-1 text-lg font-semibold tabular">{{ money(cards.remaining_contract_value) }}</p>
            </AppCard>
        </div>

        <div class="mb-4 grid gap-3 lg:grid-cols-2">
            <AppCard title="Variations">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-slate-500">Total</dt><dd class="tabular font-medium">{{ variation_widget.count }}</dd>
                    <dt class="text-slate-500">Approved</dt><dd class="tabular">{{ variation_widget.approved_count }}</dd>
                    <dt class="text-slate-500">Pending</dt><dd class="tabular text-amber-800">{{ variation_widget.pending_count }}</dd>
                    <template v-if="can.view_costs">
                        <dt class="text-slate-500">Approved value</dt><dd class="tabular">{{ money(variation_widget.approved_value) }}</dd>
                        <dt class="text-slate-500">Pending value</dt><dd class="tabular text-amber-800">{{ money(variation_widget.pending_value) }}</dd>
                    </template>
                </dl>
                <Link v-if="can.view_variations" :href="links.variations" class="mt-3 inline-block text-sm font-medium text-brand-700 hover:underline">
                    Open Variation Register →
                </Link>
            </AppCard>

            <AppCard title="BOQ Health">
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <Link :href="links.health_risk" class="rounded-lg border border-line px-3 py-2 hover:bg-slate-50">
                        <p class="text-xs text-slate-500">Over-cost items</p>
                        <p class="font-semibold tabular">{{ health.over_cost_items }}</p>
                    </Link>
                    <Link :href="links.health_risk" class="rounded-lg border border-line px-3 py-2 hover:bg-slate-50">
                        <p class="text-xs text-slate-500">Over-issued materials</p>
                        <p class="font-semibold tabular">{{ health.over_issued_materials }}</p>
                    </Link>
                    <Link :href="links.variations" class="rounded-lg border border-line px-3 py-2 hover:bg-slate-50">
                        <p class="text-xs text-slate-500">Pending variations</p>
                        <p class="font-semibold tabular">{{ health.pending_variations }}</p>
                    </Link>
                    <Link :href="links.measurements" class="rounded-lg border border-line px-3 py-2 hover:bg-slate-50">
                        <p class="text-xs text-slate-500">Pending measurements</p>
                        <p class="font-semibold tabular">{{ health.pending_measurements }}</p>
                    </Link>
                    <Link :href="links.health_risk" class="rounded-lg border border-line px-3 py-2 hover:bg-slate-50">
                        <p class="text-xs text-slate-500">Unmapped BBS</p>
                        <p class="font-semibold tabular">{{ health.unmapped_bbs_steel }}</p>
                    </Link>
                    <Link :href="links.progress_billing" class="rounded-lg border border-line px-3 py-2 hover:bg-slate-50">
                        <p class="text-xs text-slate-500">Executed unbilled</p>
                        <p class="font-semibold tabular">{{ health.executed_unbilled_items }}</p>
                    </Link>
                </div>
            </AppCard>
        </div>

        <AppCard title="Reports">
            <div class="flex flex-wrap gap-2 text-sm">
                <Link v-if="can.view_reports" :href="links.item_analysis" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Item Analysis</Link>
                <Link v-if="can.view_reports" :href="links.progress_billing" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Progress vs Billing</Link>
                <Link v-if="can.view_reports" :href="links.measurements" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Measurements</Link>
                <Link v-if="can.view_reports && can.view_costs" :href="links.cost_buildup" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Cost Build-Up</Link>
                <Link v-if="can.view_reports && can.view_costs" :href="links.estimated_actual" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Estimated vs Actual</Link>
                <Link v-if="can.view_materials" :href="links.material_requirement" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Material Requirement</Link>
                <Link v-if="can.view_reports" :href="links.procurement_gap" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Procurement Gap</Link>
                <Link v-if="can.view_reconciliation" :href="links.material_reconciliation" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Material Reconciliation</Link>
                <Link v-if="can.view_reconciliation" :href="links.steel_reconciliation" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Steel Reconciliation</Link>
                <Link v-if="can.view_reports" :href="links.health_risk" class="rounded-lg border border-line bg-white px-3 py-2 hover:bg-slate-50">Health & Risk</Link>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
