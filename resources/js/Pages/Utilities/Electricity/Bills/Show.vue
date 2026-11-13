<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatCost, formatUnitRate } from '@/lib/electricity';
import { formatDate, formatNumber } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    bill: { type: Object, required: true },
    components: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    can_view_costs: { type: Boolean, default: false },
});

const varianceClass = computed(() => {
    if (!props.can_view_costs || props.bill.variance_total == null) {
        return '';
    }
    const v = Number(String(props.bill.variance_total).replace(/,/g, ''));
    if (Number.isNaN(v) || v === 0) {
        return 'text-slate-700';
    }

    return v > 0 ? 'text-red-700' : 'text-emerald-700';
});
</script>

<template>
    <AppLayout :title="bill.bill_number">
        <AppCard :padded="false" class="mb-4">
            <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <Link :href="route('utilities.electricity.bills.index')" class="text-xs font-medium text-slate-500 hover:text-slate-700">Bills</Link>
                    <div class="mt-0.5 flex flex-wrap items-center gap-2">
                        <h1 class="text-lg font-semibold text-slate-900">{{ bill.bill_number }}</h1>
                        <StatusBadge :status="bill.status" :label="bill.status_label ?? bill.status" />
                    </div>
                    <p class="text-sm text-slate-600">{{ bill.site?.name }} · {{ formatDate(bill.billing_month) }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
                        <div><dt class="text-slate-500">Calculated</dt><dd class="tabular font-medium">{{ formatCost(bill.calculated_total, can_view_costs) }}</dd></div>
                        <div><dt class="text-slate-500">Actual</dt><dd class="tabular">{{ formatCost(bill.actual_bill_total, can_view_costs) }}</dd></div>
                        <div><dt class="text-slate-500">Variance</dt><dd class="tabular font-medium" :class="varianceClass">{{ formatCost(bill.variance_total, can_view_costs) }}</dd></div>
                        <div><dt class="text-slate-500">Cost / kWh</dt><dd class="tabular">{{ formatUnitRate(bill.cost_per_kwh, 'kWh', can_view_costs) }}</dd></div>
                        <div><dt class="text-slate-500">Cost / kVAh</dt><dd class="tabular">{{ formatUnitRate(bill.cost_per_kvah, 'kVAh', can_view_costs) }}</dd></div>
                        <div v-if="bill.demand_utilization_percent != null"><dt class="text-slate-500">Demand util.</dt><dd class="tabular">{{ formatNumber(bill.demand_utilization_percent, 0, 2) }}%</dd></div>
                    </dl>
                </div>
                <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('utilities.electricity.bills.edit', bill.id)">Edit</AppButton>
            </div>
        </AppCard>

        <AppCard title="Component comparison" :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Component</th>
                            <th class="px-4 py-2 text-right font-medium">Qty</th>
                            <th class="px-4 py-2 text-right font-medium">Rate</th>
                            <th class="px-4 py-2 text-right font-medium">Calculated</th>
                            <th class="px-4 py-2 text-right font-medium">Actual</th>
                            <th class="px-4 py-2 text-right font-medium">Variance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="c in components" :key="c.id">
                            <td class="px-4 py-2">
                                <div class="font-medium text-slate-900">{{ c.component_name }}</div>
                                <div class="font-mono text-xs text-slate-500">{{ c.component_code }}</div>
                            </td>
                            <td class="px-4 py-2 text-right tabular">{{ c.quantity != null ? formatNumber(c.quantity, 0, 4) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ can_view_costs && c.rate != null ? formatNumber(c.rate, 2, 4) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ formatCost(c.calculated_amount, can_view_costs) }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ formatCost(c.actual_amount, can_view_costs) }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ formatCost(c.variance_amount, can_view_costs) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="components.length && can_view_costs" class="border-t border-line bg-slate-50 text-sm font-medium">
                        <tr>
                            <td class="px-4 py-2" colspan="3">Totals</td>
                            <td class="px-4 py-2 text-right tabular">{{ formatCost(bill.calculated_total, true) }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ formatCost(bill.actual_bill_total, true) }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ formatCost(bill.variance_total, true) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p v-if="!components.length" class="px-4 py-3 text-sm text-slate-500">No component lines.</p>
            <p v-if="!can_view_costs" class="border-t border-line px-4 py-2 text-xs text-slate-500">Financial amounts are hidden for your role.</p>
        </AppCard>
    </AppLayout>
</template>
