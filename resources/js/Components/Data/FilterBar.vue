<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

/**
 * Search box plus optional filters that reload the current page with query parameters.
 * filters: initial values from the server, e.g. { search: '', status: 'all' }
 * selects: [{ key, options: [{ value, label }] }]
 */
const props = defineProps({
    filters: { type: Object, required: true },
    selects: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Search…' },
    searchable: { type: Boolean, default: true },
});

const state = reactive({ ...props.filters });
let timer = null;

function apply() {
    const query = Object.fromEntries(Object.entries(state).filter(([, v]) => v !== '' && v !== null && v !== 'all'));
    router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true });
}

watch(
    () => state.search,
    () => {
        clearTimeout(timer);
        timer = setTimeout(apply, 350);
    },
);
</script>

<template>
    <div class="flex flex-col gap-2 border-b border-line p-3 sm:flex-row sm:items-center">
        <div v-if="searchable" class="relative flex-1">
            <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-slate-400" />
            <input
                v-model="state.search"
                type="search"
                :placeholder="placeholder"
                class="block w-full rounded-lg border-slate-300 py-2 pr-3 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200"
            />
        </div>
        <select
            v-for="select in selects"
            :key="select.key"
            v-model="state[select.key]"
            class="rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200 sm:w-44"
            @change="apply"
        >
            <option v-for="option in select.options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <slot />
    </div>
</template>
