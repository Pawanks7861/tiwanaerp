<script setup>
import Icon from './Icon.vue';

defineProps({
    icon: { type: String, default: 'folder' },
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
        <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
            <Icon :name="icon" :size="24" />
        </div>
        <h3 class="text-sm font-semibold text-slate-900">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-slate-500">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
