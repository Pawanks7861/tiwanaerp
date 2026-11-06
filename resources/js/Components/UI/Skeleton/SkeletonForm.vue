<script setup>
import SkeletonBase from './SkeletonBase.vue';

defineProps({
    fields: { type: Number, default: 4 },
});
</script>

<template>
    <div class="space-y-4" aria-hidden="true">
        <div v-for="field in fields" :key="field" class="space-y-2">
            <SkeletonBase width="28%" height="0.65rem" />
            <SkeletonBase width="100%" :height="field === fields ? '5rem' : '2.25rem'" radius="0.5rem" />
        </div>
    </div>
</template>
