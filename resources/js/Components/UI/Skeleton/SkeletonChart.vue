<script setup>
import SkeletonBase from './SkeletonBase.vue';
</script>

<template>
    <div class="rounded-xl border border-line bg-white p-4" aria-hidden="true">
        <SkeletonBase width="36%" height="0.8rem" />
        <div class="mt-4 flex h-40 items-end gap-2">
            <SkeletonBase v-for="bar in 8" :key="bar" width="100%" :height="`${30 + ((bar * 17) % 60)}%`" radius="0.25rem" />
        </div>
    </div>
</template>
