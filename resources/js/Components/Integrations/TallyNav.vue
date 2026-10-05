<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    active: { type: String, required: true },
});

const links = [
    { key: 'connection', label: 'Connection', route: 'integrations.tally.edit' },
    { key: 'mappings', label: 'Ledger mapping', route: 'integrations.tally.mappings' },
    { key: 'history', label: 'Sync history', route: 'integrations.tally.history' },
    { key: 'reconciliation', label: 'Reconciliation', route: 'integrations.tally.reconciliation' },
];
</script>

<template>
    <nav class="mb-4 flex gap-2 overflow-x-auto" aria-label="Tally">
        <Link
            v-for="link in links"
            :key="link.key"
            :href="route(link.route)"
            class="shrink-0 rounded-full px-3 py-1.5 text-sm"
            :class="active === link.key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'"
        >
            {{ link.label }}
        </Link>
    </nav>
</template>
