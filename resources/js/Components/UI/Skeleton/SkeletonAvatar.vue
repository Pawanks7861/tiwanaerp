<script setup>
import SkeletonBase from './SkeletonBase.vue';

defineProps({
    size: { type: String, default: '2.25rem' },
});
</script>

<template>
    <SkeletonBase :width="size" :height="size" radius="999px" />
</template>
