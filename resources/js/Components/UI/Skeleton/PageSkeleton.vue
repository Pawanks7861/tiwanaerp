<script setup>
import SkeletonChart from './SkeletonChart.vue';
import SkeletonStatCard from './SkeletonStatCard.vue';
import SkeletonTable from './SkeletonTable.vue';
</script>

<template>
    <div class="space-y-4" aria-busy="true">
        <span class="sr-only">Loading content…</span>
        <div class="space-y-2">
            <div class="h-6 w-48 max-w-full rounded-md skeleton-block skeleton-shimmer" aria-hidden="true" />
            <div class="h-3 w-72 max-w-full rounded-md skeleton-block skeleton-shimmer" aria-hidden="true" />
        </div>
        <SkeletonStatCard :count="4" />
        <div class="grid gap-4 lg:grid-cols-2">
            <SkeletonChart />
            <SkeletonChart />
        </div>
        <div class="overflow-hidden rounded-xl border border-line bg-white">
            <SkeletonTable :rows="6" :columns="6" :widths="['18%', '28%', '12%', '12%', '14%', '16%']" />
        </div>
    </div>
</template>
