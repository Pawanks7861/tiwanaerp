<script setup>
import FormInput from './FormInput.vue';

const model = defineModel({ type: [String, null], default: null });
</script>

<template>
    <FormInput v-model="model" type="date" />
</template>
