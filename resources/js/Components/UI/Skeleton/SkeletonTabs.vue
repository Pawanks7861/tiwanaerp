<script setup>
import SkeletonBase from './SkeletonBase.vue';

defineProps({
    count: { type: Number, default: 4 },
});
</script>

<template>
    <div class="flex gap-2 overflow-hidden" aria-hidden="true">
        <SkeletonBase v-for="tab in count" :key="tab" width="5.5rem" height="2rem" radius="999px" />
    </div>
</template>
