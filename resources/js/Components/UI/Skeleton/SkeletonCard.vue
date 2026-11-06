<script setup>
import SkeletonBase from './SkeletonBase.vue';
import SkeletonText from './SkeletonText.vue';

defineProps({
    lines: { type: Number, default: 3 },
});
</script>

<template>
    <div class="rounded-xl border border-line bg-white p-4 shadow-sm" aria-hidden="true">
        <SkeletonBase width="40%" height="0.85rem" />
        <div class="mt-4">
            <SkeletonText :lines="lines" />
        </div>
    </div>
</template>
