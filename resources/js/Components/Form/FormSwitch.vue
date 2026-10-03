<script setup>
defineProps({
    label: { type: String, default: null },
    description: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const model = defineModel({ type: Boolean, default: false });
</script>

<template>
    <label class="flex cursor-pointer items-start gap-3" :class="disabled ? 'cursor-not-allowed opacity-60' : ''">
        <button
            type="button"
            role="switch"
            :aria-checked="model"
            :disabled="disabled"
            class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
            :class="model ? 'bg-brand-600' : 'bg-slate-300'"
            @click="model = !model"
        >
            <span class="inline-block h-4 w-4 translate-y-0.5 rounded-full bg-white shadow transition" :class="model ? 'translate-x-4.5' : 'translate-x-0.5'" />
        </button>
        <span v-if="label || description" class="text-sm">
            <span class="font-medium text-slate-700">{{ label }}</span>
            <span v-if="description" class="block text-xs text-slate-500">{{ description }}</span>
        </span>
    </label>
</template>
