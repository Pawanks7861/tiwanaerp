<script setup>
import ManagementDashboard from '@/Components/Dashboards/ManagementDashboard.vue';

defineProps({
    dashboard: { type: Object, default: null },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
    profile: { type: String, default: 'md' },
    period: { type: Object, default: null },
    pendingApprovals: { type: Number, default: null },
    recentProjects: { type: Array, default: () => [] },
});
</script>

<template>
    <ManagementDashboard
        v-bind="$props"
        title="Dashboard"
        profile-key="md"
    />
</template>
