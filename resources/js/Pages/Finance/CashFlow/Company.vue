<script setup>
import CashFlowPanel from '@/Components/Finance/CashFlowPanel.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    entries: { type: Array, required: true },
    totals: { type: Object, required: true },
    outstanding: { type: Object, required: true },
    filters: { type: Object, required: true },
    projects: { type: Array, required: true },
    types: { type: Array, required: true },
    modes: { type: Array, required: true },
});
</script>

<template>
    <AppLayout title="Cash flow">
        <PageHeader title="Cash flow & outstanding" subtitle="Across the projects you can see. Receivables and payables are derived from bills and their approved allocations." />
        <CashFlowPanel :entries="entries" :totals="totals" :outstanding="outstanding" :filters="filters" :types="types" :modes="modes" :projects="projects" />
    </AppLayout>
</template>
