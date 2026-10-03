<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    icon: { type: String, default: null },
    tone: { type: String, default: 'brand' }, // brand | green | amber | orange | slate
    href: { type: String, default: null },
    hint: { type: String, default: null },
});

const tones = {
    brand: 'bg-brand-50 text-brand-600',
    green: 'bg-emerald-50 text-emerald-600',
    amber: 'bg-amber-50 text-amber-600',
    orange: 'bg-accent-50 text-accent-600',
    slate: 'bg-slate-100 text-slate-600',
};
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        class="flex items-center gap-4 rounded-xl border border-line bg-white p-4 shadow-sm"
        :class="href ? 'transition hover:border-brand-200 hover:shadow' : ''"
    >
        <div v-if="icon" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg" :class="tones[tone]">
            <Icon :name="icon" :size="22" />
        </div>
        <div class="min-w-0">
            <p class="truncate text-xs font-medium tracking-wide text-slate-500 uppercase">{{ label }}</p>
            <p class="mt-0.5 truncate text-xl font-semibold text-slate-900 tabular">{{ value }}</p>
            <p v-if="hint" class="truncate text-xs text-slate-500">{{ hint }}</p>
        </div>
    </component>
</template>
