<script setup>
import SkeletonBase from './SkeletonBase.vue';

defineProps({
    lines: { type: Number, default: 1 },
    lastWidth: { type: String, default: '70%' },
});
</script>

<template>
    <div class="space-y-2" aria-hidden="true">
        <SkeletonBase v-for="line in lines" :key="line" height="0.75rem" :width="line === lines && lines > 1 ? lastWidth : '100%'" />
    </div>
</template>
