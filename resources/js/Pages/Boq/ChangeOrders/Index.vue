<script setup>
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatDateTime } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    change_orders: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});
</script>

<template>
    <ProjectLayout :project="project" :title="`Change orders ${boq.boq_number}`" active="boqs">
        <div class="mb-4 flex items-center justify-between gap-2">
            <h1 class="text-xl font-semibold text-slate-900">Revision change orders</h1>
            <Link :href="route('projects.boqs.show', [project.id, boq.id])" class="text-sm font-medium text-brand-700 hover:underline">Back to BOQ</Link>
        </div>
        <AppCard :padded="false">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Number</th>
                        <th class="px-4 py-2 text-left">Revisions</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-right">Commercial Δ</th>
                        <th v-if="can.view_costs" class="px-4 py-2 text-right">Internal Δ</th>
                        <th class="px-4 py-2 text-left">Submitted</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="co in change_orders" :key="co.id">
                        <td class="px-4 py-2">
                            <Link :href="route('projects.boqs.change-orders.show', [project.id, boq.id, co.id])" class="font-mono font-medium text-brand-700 hover:underline">
                                {{ co.change_order_number }}
                            </Link>
                            <div class="text-xs text-slate-500">{{ co.title }}</div>
                        </td>
                        <td class="px-4 py-2 tabular">v{{ co.from_version }} → v{{ co.to_version }}</td>
                        <td class="px-4 py-2"><StatusBadge :status="co.status" :label="co.status_label" /></td>
                        <td class="px-4 py-2 text-right tabular">{{ formatMoney(co.commercial_variation) }}</td>
                        <td v-if="can.view_costs" class="px-4 py-2 text-right tabular">{{ formatMoney(co.internal_cost_variation) }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ co.submitted_at ? formatDateTime(co.submitted_at) : '—' }}</td>
                    </tr>
                    <tr v-if="!change_orders.length">
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">No change orders yet.</td>
                    </tr>
                </tbody>
            </table>
        </AppCard>
    </ProjectLayout>
</template>