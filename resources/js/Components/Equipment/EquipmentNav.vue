<script setup>
import { usePermissions } from '@/lib/permissions';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    projectId: { type: Number, required: true },
    active: { type: String, required: true },
});

const { can } = usePermissions();

const items = computed(() =>
    can('equipment.view')
        ? [
              { key: 'assignments', label: 'Assignments', short: 'Assigned', route: 'projects.equipment-assignments.index' },
              { key: 'usage', label: 'Usage', short: 'Usage', route: 'projects.equipment-usage.index' },
              { key: 'fuel', label: 'Fuel', short: 'Fuel', route: 'projects.equipment-fuel.index' },
              { key: 'repairs', label: 'Repairs', short: 'Repairs', route: 'projects.equipment-repairs.index' },
          ]
        : [],
);
</script>

<template>
    <nav class="mb-4 flex max-w-full overflow-x-auto rounded-lg border border-line bg-white p-0.5 shadow-sm sm:inline-flex" aria-label="Equipment">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="route(item.route, props.projectId)"
            class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap"
            :class="active === item.key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
            :aria-current="active === item.key ? 'page' : undefined"
        >
            <span class="sm:hidden">{{ item.short }}</span><span class="hidden sm:inline">{{ item.label }}</span>
        </Link>
    </nav>
</template>
