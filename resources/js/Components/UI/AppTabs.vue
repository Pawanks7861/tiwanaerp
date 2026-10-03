<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from './Icon.vue';

/**
 * Tabs as links (each tab is its own page) or buttons when `modelValue` is used.
 * items: [{ key, label, href?, icon?, count? }]
 */
defineProps({
    items: { type: Array, required: true },
    active: { type: String, default: null },
});

const model = defineModel({ type: String, default: null });
</script>

<template>
    <nav class="-mb-px flex gap-1 overflow-x-auto" aria-label="Tabs">
        <template v-for="item in items" :key="item.key">
            <component
                :is="item.href ? Link : 'button'"
                :href="item.href"
                :type="item.href ? undefined : 'button'"
                class="inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition"
                :class="
                    (active ?? model) === item.key
                        ? 'border-accent-500 text-slate-900'
                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                "
                :aria-current="(active ?? model) === item.key ? 'page' : undefined"
                @click="!item.href && (model = item.key)"
            >
                <Icon v-if="item.icon" :name="item.icon" :size="16" />
                {{ item.label }}
                <span
                    v-if="item.count !== undefined && item.count !== null"
                    class="rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600 tabular"
                >
                    {{ item.count }}
                </span>
            </component>
        </template>
    </nav>
</template>
