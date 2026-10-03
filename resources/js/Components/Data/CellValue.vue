<script setup>
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { formatDate, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';

defineProps({
    type: { type: String, default: 'text' },
    value: { type: [String, Number, Boolean, null], default: null },
});
</script>

<template>
    <StatusBadge v-if="type === 'status'" :status="!!value" :label="value ? 'Active' : 'Inactive'" />
    <span v-else-if="type === 'code'" class="font-mono text-xs text-slate-700">{{ value ?? '—' }}</span>
    <span v-else-if="type === 'money'" class="tabular">{{ formatMoney(value) }}</span>
    <span v-else-if="type === 'rate'" class="tabular">{{ formatRate(value) }}</span>
    <span v-else-if="type === 'qty'" class="tabular">{{ formatQty(value) }}</span>
    <span v-else-if="type === 'percent'" class="tabular">{{ formatPercent(value) }}</span>
    <span v-else-if="type === 'date'" class="tabular">{{ formatDate(value) }}</span>
    <span v-else>{{ value === null || value === '' || value === undefined ? '—' : value }}</span>
</template>
