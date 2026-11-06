<script setup>
import SkeletonBase from './SkeletonBase.vue';

const props = defineProps({
    rows: { type: Number, default: 6 },
    columns: { type: Number, default: 5 },
    widths: { type: Array, default: () => [] },
});

function widthFor(index) {
    return props.widths[index] || `${Math.max(12, Math.round(100 / props.columns))}%`;
}
</script>

<template>
    <div class="overflow-hidden" aria-hidden="true">
        <div class="hidden md:block">
            <div v-for="row in rows" :key="row" class="flex gap-3 border-b border-line px-4 py-3">
                <SkeletonBase v-for="col in columns" :key="col" height="0.75rem" :width="widthFor(col - 1)" />
            </div>
        </div>
        <div class="space-y-3 p-4 md:hidden">
            <div v-for="row in Math.min(rows, 4)" :key="`m-${row}`" class="space-y-2 rounded-lg border border-line p-3">
                <SkeletonBase width="70%" height="0.85rem" />
                <SkeletonBase width="45%" height="0.65rem" />
                <SkeletonBase width="55%" height="0.65rem" />
            </div>
        </div>
    </div>
</template>
