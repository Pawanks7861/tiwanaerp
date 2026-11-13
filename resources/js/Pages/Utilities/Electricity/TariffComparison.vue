<script setup>
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatNumber } from '@/lib/format';
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    tariffs: { type: Array, default: () => [] },
    left: { type: Object, default: null },
    right: { type: Object, default: null },
    diff: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const leftId = ref(props.left?.id ?? props.filters.left ?? '');
const rightId = ref(props.right?.id ?? props.filters.right ?? '');

const tariffOptions = props.tariffs.map((t) => ({
    value: t.id,
    label: `${t.name} (${t.connection?.connection_number ?? t.electricity_connection_id})`,
}));

function apply() {
    router.get(
        route('utilities.electricity.tariff-comparison'),
        { left: leftId.value || undefined, right: rightId.value || undefined },
        { preserveState: true, preserveScroll: true },
    );
}

watch(
    () => [props.left?.id, props.right?.id],
    () => {
        leftId.value = props.left?.id ?? '';
        rightId.value = props.right?.id ?? '';
    },
);
</script>

<template>
    <AppLayout title="Tariff comparison">
        <PageHeader title="Tariff comparison" subtitle="Side-by-side component rates for two tariff versions." :back="route('utilities.electricity.hub')" />
        <AppCard class="mb-4">
            <form class="flex flex-col gap-4 sm:flex-row sm:items-end" @submit.prevent="apply">
                <FormSelect v-model="leftId" label="Left tariff" :options="[{ value: '', label: 'Select…' }, ...tariffOptions]" class="flex-1" />
                <FormSelect v-model="rightId" label="Right tariff" :options="[{ value: '', label: 'Select…' }, ...tariffOptions]" class="flex-1" />
                <AppButton type="submit" class="sm:mb-0.5">Compare</AppButton>
            </form>
        </AppCard>

        <div v-if="left && right" class="grid gap-4 lg:grid-cols-2">
            <AppCard :title="left.name" :subtitle="left.connection?.connection_number">
                <p class="mb-3 text-xs text-slate-500">{{ left.components?.length ?? 0 }} components</p>
            </AppCard>
            <AppCard :title="right.name" :subtitle="right.connection?.connection_number">
                <p class="mb-3 text-xs text-slate-500">{{ right.components?.length ?? 0 }} components</p>
            </AppCard>
        </div>

        <AppCard v-if="diff.length" title="Component differences" :padded="false" class="mt-4">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Code</th>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 text-right font-medium">Left rate</th>
                            <th class="px-4 py-2 text-right font-medium">Right rate</th>
                            <th class="px-4 py-2 text-right font-medium">Δ rate</th>
                            <th class="px-4 py-2 font-medium">Basis</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in diff" :key="row.component_code" :class="row.missing_left || row.missing_right ? 'bg-amber-50/50' : ''">
                            <td class="px-4 py-2 font-mono text-xs">{{ row.component_code }}</td>
                            <td class="px-4 py-2">{{ row.component_name }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ row.left_rate != null ? formatNumber(row.left_rate, 2, 4) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ row.right_rate != null ? formatNumber(row.right_rate, 2, 4) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular font-medium" :class="row.rate_delta > 0 ? 'text-red-700' : row.rate_delta < 0 ? 'text-emerald-700' : ''">
                                {{ row.rate_delta != null ? formatNumber(row.rate_delta, 2, 4) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-slate-600">{{ row.calculation_basis_label ?? row.calculation_basis ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
        <p v-else-if="leftId && rightId" class="text-sm text-slate-500">Select two tariffs and click Compare to see differences.</p>
    </AppLayout>
</template>
