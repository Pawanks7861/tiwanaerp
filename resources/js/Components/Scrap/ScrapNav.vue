<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Company-scoped scrap hub still uses this bar.
 * Project-scoped scrap children live in the project top-nav dropdown.
 */
const props = defineProps({
    active: { type: String, required: true },
    projectId: { type: Number, default: null },
    scope: { type: String, default: 'company' },
});

const items = computed(() => {
    if (props.scope === 'project') {
        return [];
    }

    return [
        { key: 'dashboard', label: 'Dashboard', route: 'scrap.dashboard', params: [] },
        { key: 'generations', label: 'Generations', route: 'scrap.generations.index', params: [] },
        { key: 'balances', label: 'Balances', route: 'scrap.balances.index', params: [] },
        { key: 'ledger', label: 'Ledger', route: 'scrap.ledger.index', params: [] },
        { key: 'adjustments', label: 'Adjustments', route: 'scrap.adjustments.index', params: [] },
    ];
});
</script>

<template>
    <nav
        v-if="items.length"
        class="mb-4 flex max-w-full overflow-x-auto rounded-lg border border-line bg-white p-0.5 shadow-sm sm:inline-flex"
        aria-label="Scrap"
    >
        <Link
            v-for="item in items"
            :key="item.key"
            :href="route(item.route, ...item.params)"
            class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap"
            :class="active === item.key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
            :aria-current="active === item.key ? 'page' : undefined"
        >
            {{ item.label }}
        </Link>
    </nav>
    <span v-else class="hidden" aria-hidden="true" />
</template>
