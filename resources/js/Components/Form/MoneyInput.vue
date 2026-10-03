<script setup>
import DecimalInput from './DecimalInput.vue';

const model = defineModel({ type: [String, Number, null], default: null });
</script>

<template>
    <DecimalInput v-model="model" :decimals="2" prefix="₹" />
</template>
