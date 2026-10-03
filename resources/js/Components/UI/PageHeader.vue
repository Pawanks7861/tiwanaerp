<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from './Icon.vue';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    back: { type: String, default: null },
});
</script>

<template>
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <Link v-if="back" :href="back" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700">
                <Icon name="arrow-left" :size="14" /> Back
            </Link>
            <h1 class="truncate text-xl font-semibold text-slate-900 sm:text-2xl">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-slate-500">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
