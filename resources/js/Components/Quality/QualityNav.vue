<script setup>
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    projectId: { type: Number, required: true },
    active: { type: String, required: true },
});

const items = [
    { key: 'inspections', label: 'Inspections', route: 'projects.inspections.index' },
    { key: 'ncrs', label: 'NCRs', route: 'projects.ncrs.index' },
];
</script>

<template>
    <nav class="mb-4 flex max-w-full overflow-x-auto rounded-lg border border-line bg-white p-0.5 shadow-sm sm:inline-flex" aria-label="Quality">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="route(item.route, props.projectId)"
            class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap"
            :class="active === item.key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
            :aria-current="active === item.key ? 'page' : undefined"
        >
            {{ item.label }}
        </Link>
    </nav>
</template>
