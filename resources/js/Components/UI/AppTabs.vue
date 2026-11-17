<script setup>
import { scrollToActiveProjectTab } from '@/lib/scrollToActiveProjectTab';
import { Link } from '@inertiajs/vue3';
import { nextTick, onMounted, ref, watch } from 'vue';
import Icon from './Icon.vue';

/**
 * Tabs as links (each tab is its own page) or buttons when `modelValue` is used.
 * items: [{ key, label, href?, icon?, count? }]
 */
const props = defineProps({
    items: { type: Array, required: true },
    active: { type: String, default: null },
});

const model = defineModel({ type: String, default: null });
const tabNav = ref(null);
let positionedForKey = null;

function positionKey() {
    const key = props.active ?? model.value;
    const firstHref = props.items?.[0]?.href ?? '';

    return `${firstHref}::${key}`;
}

function positionActiveTab({ instant = false } = {}) {
    const key = positionKey();

    if (positionedForKey === key) {
        return;
    }

    positionedForKey = key;
    nextTick(() => {
        scrollToActiveProjectTab(tabNav.value, { instant });
    });
}

onMounted(() => {
    positionActiveTab({ instant: true });
});

watch(
    () => props.active ?? model.value,
    () => {
        positionActiveTab({ instant: false });
    },
);
</script>

<template>
    <nav ref="tabNav" class="-mb-px flex gap-1 overflow-x-auto" aria-label="Tabs">
        <template v-for="(item, index) in items" :key="item.key">
            <span
                v-if="item.group && item.group !== items[index - 1]?.group"
                class="inline-flex shrink-0 items-end border-b-2 border-transparent px-2 pb-2.5 text-[10px] font-semibold tracking-wide text-slate-400 uppercase"
                aria-hidden="true"
            >
                {{ item.group }}
            </span>
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
