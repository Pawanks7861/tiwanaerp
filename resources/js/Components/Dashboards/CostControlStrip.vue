<script setup>
import StatCard from '@/Components/Data/StatCard.vue';
import { formatMoneyShort } from '@/lib/format';
import { computed } from 'vue';

const props = defineProps({
    costControl: { type: Object, default: null },
});

const cards = computed(() => {
    const source = props.costControl;
    if (!source || source.redacted) {
        return [];
    }

    const bag = source.kpis && typeof source.kpis === 'object' ? source.kpis : source;
    const specs = [
        ['revised_budget', 'Revised budget', 'banknotes', 'brand', bag.revised_budget ?? bag.budget],
        ['actual_invoiced', 'Actual invoiced', 'banknotes', 'orange', bag.actual_invoiced ?? bag.actual ?? bag.actual_cost],
        ['open_commitment', 'Open commitment', 'truck', 'slate', bag.open_commitment ?? bag.committed ?? bag.commitments],
        ['available_budget', 'Available', 'check-circle', 'green', bag.available_budget],
        ['unallocated_cost', 'Unallocated', 'warning', 'amber', bag.unallocated_cost ?? bag.unallocated],
        ['gst_exposure', 'GST exposure', 'receipt', 'slate', bag.gst_exposure],
    ];

    return specs
        .filter(([, , , , value]) => value !== undefined && value !== null && value !== 'DEFERRED')
        .map(([key, label, icon, tone, value]) => ({
            key,
            label,
            icon,
            tone,
            value: formatMoneyShort(value),
        }));
});
</script>

<template>
    <div v-if="cards.length" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <StatCard v-for="card in cards" :key="card.key" :label="card.label" :value="card.value" :icon="card.icon" :tone="card.tone" />
    </div>
</template>
