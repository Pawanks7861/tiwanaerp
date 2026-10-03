<script setup>
import { computed } from 'vue';

const props = defineProps({
    color: { type: String, default: 'slate' }, // slate | green | amber | red | blue | brand | orange
    dot: { type: Boolean, default: false },
});

const tones = {
    slate: ['bg-slate-100 text-slate-700', 'bg-slate-400'],
    green: ['bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'bg-emerald-500'],
    amber: ['bg-amber-50 text-amber-800 ring-amber-600/20', 'bg-amber-500'],
    red: ['bg-red-50 text-red-700 ring-red-600/20', 'bg-red-500'],
    blue: ['bg-sky-50 text-sky-700 ring-sky-600/20', 'bg-sky-500'],
    brand: ['bg-brand-50 text-brand-700 ring-brand-600/20', 'bg-brand-500'],
    orange: ['bg-accent-50 text-accent-700 ring-accent-600/20', 'bg-accent-500'],
};

const tone = computed(() => tones[props.color] ?? tones.slate);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-transparent ring-inset"
        :class="tone[0]"
    >
        <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :class="tone[1]" />
        <slot />
    </span>
</template>
