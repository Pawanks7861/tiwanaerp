<script setup>
import CashFlowPanel from '@/Components/Finance/CashFlowPanel.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';

defineProps({
    project: { type: Object, required: true },
    entries: { type: Array, required: true },
    totals: { type: Object, required: true },
    outstanding: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    modes: { type: Array, required: true },
});
</script>

<template>
    <ProjectLayout :project="project" active="finance" title="Cash flow">
        <FinanceNav :project-id="project.id" active="cash-flow" />
        <CashFlowPanel :entries="entries" :totals="totals" :outstanding="outstanding" :filters="filters" :types="types" :modes="modes" />
    </ProjectLayout>
</template>
