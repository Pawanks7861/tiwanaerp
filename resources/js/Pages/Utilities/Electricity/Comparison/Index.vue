<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatCost } from '@/lib/electricity';
import { formatDate, formatNumber } from '@/lib/format';

defineProps({
    months: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
    sites: { type: Array, default: () => [] },
    can_view_costs: { type: Boolean, default: false },
});
</script>

<template>
    <AppLayout title="Monthly comparison">
        <PageHeader title="Monthly comparison" subtitle="Consumption and billing metrics month by month." :back="route('utilities.electricity.hub')" />
        <AppCard :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="sticky left-0 z-10 bg-slate-50 px-4 py-2 font-medium">Metric</th>
                            <th v-for="m in months" :key="m.key" class="px-4 py-2 text-right font-medium whitespace-nowrap">{{ m.label ?? formatDate(m.month ?? m) }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows" :key="row.key">
                            <td class="sticky left-0 z-10 bg-white px-4 py-2 font-medium text-slate-800">{{ row.label }}</td>
                            <td v-for="(cell, idx) in row.values" :key="idx" class="px-4 py-2 text-right tabular whitespace-nowrap">
                                <template v-if="row.kind === 'money'">{{ formatCost(cell, can_view_costs) }}</template>
                                <template v-else-if="row.kind === 'quantity'">{{ cell != null ? formatNumber(cell, 0, 2) : '—' }}<span v-if="row.unit && cell != null" class="text-xs text-slate-500"> {{ row.unit }}</span></template>
                                <template v-else>{{ cell ?? '—' }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!rows.length" class="px-4 py-6 text-sm text-slate-500">No comparison data for the selected filters.</p>
        </AppCard>
    </AppLayout>
</template>
