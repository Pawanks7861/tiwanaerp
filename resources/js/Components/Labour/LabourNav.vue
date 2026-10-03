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
    can('labour.view')
        ? [
              { key: 'crew', label: 'Labour', short: 'Labour', route: 'projects.labour.index' },
              { key: 'attendance', label: 'Attendance', short: 'Attendance', route: 'projects.attendance.index' },
              { key: 'payments', label: 'Labour payments', short: 'Payments', route: 'projects.labour-payments.index' },
          ]
        : [],
);
</script>

<template>
    <nav class="mb-4 flex max-w-full overflow-x-auto rounded-lg border border-line bg-white p-0.5 shadow-sm sm:inline-flex" aria-label="Labour">
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
