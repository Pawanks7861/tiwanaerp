<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatQty } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    project: { type: Object, required: true },
    material: { type: Object, required: true },
    detail: { type: Object, required: true },
    can: { type: Object, required: true },
});
</script>

<template>
    <ProjectLayout :project="project" title="Material Control" active="material-control">
        <div class="mb-4">
            <Link :href="route('projects.material-control.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">
                ← Material Control
            </Link>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ material.code }} — {{ material.name }}</h1>
            <p v-if="detail.summary" class="mt-1 text-sm text-slate-500">
                {{ detail.summary.unit }} · {{ detail.summary.status }}
                · Estimate {{ formatQty(detail.summary.estimated_qty) }}
                · Net {{ formatQty(detail.summary.net_consumed_qty) }}
            </p>
        </div>

        <div class="space-y-4">
            <AppCard title="Estimate sources">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Source</th>
                                <th class="px-3 py-2 text-left">BOQ / Item</th>
                                <th class="px-3 py-2 text-right">Qty</th>
                                <th v-if="can.view_costs" class="px-3 py-2 text-right">Rate</th>
                                <th v-if="can.view_costs" class="px-3 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(src, i) in detail.estimate_sources" :key="i">
                                <td class="px-3 py-2">{{ src.type }}</td>
                                <td class="px-3 py-2">
                                    <a v-if="src.url" :href="src.url" class="text-brand-700 hover:underline">
                                        {{ src.boq_number }} v{{ src.boq_version }} · {{ src.item_code }} {{ src.item_name }}
                                    </a>
                                    <span v-else>{{ src.item_code }} {{ src.item_name }}</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(src.estimated_qty) }}</td>
                                <td v-if="can.view_costs" class="px-3 py-2 text-right tabular">{{ src.rate != null ? formatMoney(src.rate) : '—' }}</td>
                                <td v-if="can.view_costs" class="px-3 py-2 text-right tabular">{{ src.amount != null ? formatMoney(src.amount) : '—' }}</td>
                            </tr>
                            <tr v-if="!detail.estimate_sources?.length">
                                <td :colspan="can.view_costs ? 5 : 3" class="px-3 py-4 text-center text-slate-500">No estimate sources.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard title="Budget lines">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Version</th>
                                <th class="px-3 py-2 text-left">Source</th>
                                <th class="px-3 py-2 text-right">Qty</th>
                                <th v-if="can.view_costs" class="px-3 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="line in detail.budget_lines" :key="line.line_id">
                                <td class="px-3 py-2">v{{ line.budget_version }}</td>
                                <td class="px-3 py-2">{{ line.source_label }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(line.quantity) }}</td>
                                <td v-if="can.view_costs" class="px-3 py-2 text-right tabular">{{ formatMoney(line.amount) }}</td>
                            </tr>
                            <tr v-if="!detail.budget_lines?.length">
                                <td :colspan="can.view_costs ? 4 : 3" class="px-3 py-4 text-center text-slate-500">No budget lines.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard v-for="type in ['mr', 'po', 'grn', 'issue', 'return']" :key="type" :title="type.toUpperCase() + ' documents'">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Document</th>
                                <th class="px-3 py-2 text-left">Date / status</th>
                                <th class="px-3 py-2 text-right">Qty</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(doc, i) in detail.documents?.[type] || []" :key="i">
                                <td class="px-3 py-2">
                                    <a v-if="doc.url" :href="doc.url" class="text-brand-700 hover:underline">{{ doc.number || doc.label || doc.id }}</a>
                                    <span v-else>{{ doc.number || doc.label || doc.id }}</span>
                                </td>
                                <td class="px-3 py-2 text-slate-600">{{ doc.date || doc.status || '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(doc.quantity ?? doc.qty ?? doc.accepted_qty) }}</td>
                            </tr>
                            <tr v-if="!(detail.documents?.[type] || []).length">
                                <td colspan="3" class="px-3 py-4 text-center text-slate-500">None.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard title="Inventory ledger (issue / return / transfer)">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Date</th>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-right">In</th>
                                <th class="px-3 py-2 text-right">Out</th>
                                <th v-if="can.view_costs" class="px-3 py-2 text-right">Value</th>
                                <th class="px-3 py-2 text-left">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="txn in detail.ledger" :key="txn.id">
                                <td class="px-3 py-2">{{ txn.txn_date }}</td>
                                <td class="px-3 py-2">{{ txn.txn_type }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(txn.qty_in) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(txn.qty_out) }}</td>
                                <td v-if="can.view_costs" class="px-3 py-2 text-right tabular">{{ formatMoney(txn.value) }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ txn.remarks || '—' }}</td>
                            </tr>
                            <tr v-if="!detail.ledger?.length">
                                <td :colspan="can.view_costs ? 6 : 5" class="px-3 py-4 text-center text-slate-500">No ledger rows.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>
    </ProjectLayout>
</template>
