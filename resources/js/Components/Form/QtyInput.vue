<script setup>
import DecimalInput from './DecimalInput.vue';

defineProps({
    unit: { type: String, default: null },
    decimals: { type: Number, default: 4 },
});

const model = defineModel({ type: [String, Number, null], default: null });
</script>

<template>
    <DecimalInput v-model="model" :decimals="decimals" :suffix="unit" />
</template>
