<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    projectId: { type: Number, required: true },
    active: { type: String, required: true },
});
</script>

<template>
    <nav class="mb-4 inline-flex rounded-lg border border-line bg-white p-0.5 shadow-sm" aria-label="Planning views">
        <Link
            v-for="item in [
                { key: 'tasks', label: 'Tasks', route: 'projects.planning.tasks.index' },
                { key: 'gantt', label: 'Gantt', route: 'projects.planning.gantt' },
                { key: 'milestones', label: 'Milestones', route: 'projects.planning.milestones.index' },
            ]"
            :key="item.key"
            :href="route(item.route, projectId)"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="active === item.key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
            :aria-current="active === item.key ? 'page' : undefined"
        >
            {{ item.label }}
        </Link>
    </nav>
</template>
