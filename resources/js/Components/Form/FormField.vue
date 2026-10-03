<script setup>
defineProps({
    label: { type: String, default: null },
    for: { type: String, default: null },
    error: { type: String, default: null },
    help: { type: String, default: null },
    required: { type: Boolean, default: false },
});
</script>

<template>
    <div>
        <label v-if="label" :for="$props.for" class="mb-1 block text-sm font-medium text-slate-700">
            {{ label }}<span v-if="required" class="ml-0.5 text-red-500">*</span>
        </label>
        <slot />
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="help" class="mt-1 text-xs text-slate-500">{{ help }}</p>
    </div>
</template>
