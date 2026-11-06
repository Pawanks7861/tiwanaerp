<script setup>
import SkeletonBase from './SkeletonBase.vue';
import SkeletonForm from './SkeletonForm.vue';
import SkeletonTabs from './SkeletonTabs.vue';
</script>

<template>
    <div class="space-y-4" aria-busy="true">
        <span class="sr-only">Loading content…</span>
        <SkeletonBase width="50%" height="1.1rem" />
        <SkeletonTabs :count="3" />
        <div class="grid grid-cols-2 gap-3">
            <SkeletonBase height="3.5rem" radius="0.75rem" />
            <SkeletonBase height="3.5rem" radius="0.75rem" />
        </div>
        <SkeletonForm :fields="5" />
    </div>
</template>
