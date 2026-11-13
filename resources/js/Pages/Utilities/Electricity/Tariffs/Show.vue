<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    tariff: { type: Object, required: true },
    components: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});
</script>

<template>
    <AppLayout :title="tariff.name">
        <AppCard :padded="false" class="mb-4">
            <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <Link :href="route('utilities.electricity.tariffs.index')" class="text-xs font-medium text-slate-500 hover:text-slate-700">Tariffs</Link>
                    <div class="mt-0.5 flex flex-wrap items-center gap-2">
                        <h1 class="text-lg font-semibold text-slate-900">{{ tariff.name }}</h1>
                        <StatusBadge :status="tariff.is_active ? 'active' : 'inactive'" :label="tariff.is_active ? 'Active' : 'Inactive'" />
                    </div>
                    <p class="text-sm text-slate-600">{{ tariff.connection?.connection_number }} · {{ formatDate(tariff.effective_from) }}<span v-if="tariff.effective_to"> – {{ formatDate(tariff.effective_to) }}</span></p>
                    <p v-if="tariff.description" class="mt-2 text-sm text-slate-700">{{ tariff.description }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('utilities.electricity.tariffs.edit', tariff.id)">Edit</AppButton>
                    <AppButton size="sm" variant="secondary" :href="route('utilities.electricity.tariff-comparison', { left: tariff.id })">Compare</AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard title="Components" :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Code</th>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Type</th>
                            <th class="px-4 py-2 font-medium">Basis</th>
                            <th class="px-4 py-2 text-right font-medium">Rate</th>
                            <th class="px-4 py-2 text-right font-medium">%</th>
                            <th class="px-4 py-2 font-medium">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="c in components" :key="c.id" :class="!c.is_active && 'opacity-60'">
                            <td class="px-4 py-2 font-mono text-xs">{{ c.component_code }}</td>
                            <td class="px-4 py-2">{{ c.component_name }}</td>
                            <td class="px-4 py-2 text-xs text-slate-600">{{ c.component_type_label ?? c.component_type }}</td>
                            <td class="px-4 py-2 text-xs text-slate-600">{{ c.calculation_basis_label ?? c.calculation_basis }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ formatNumber(c.rate, 2, 4) }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ c.percentage ? formatNumber(c.percentage, 2) : '—' }}</td>
                            <td class="px-4 py-2 text-xs text-slate-500">
                                <span v-if="c.is_rebate">Rebate </span>
                                <span v-if="c.is_penalty">Penalty </span>
                                <span v-if="c.is_tax">Tax </span>
                                <span v-if="!c.is_rebate && !c.is_penalty && !c.is_tax">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!components.length" class="px-4 py-3 text-sm text-slate-500">No components on this tariff.</p>
        </AppCard>
    </AppLayout>
</template>
