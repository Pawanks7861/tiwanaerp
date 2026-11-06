<script setup>
import SkeletonBase from './SkeletonBase.vue';

defineProps({
    count: { type: Number, default: 4 },
});
</script>

<template>
    <div class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 sm:gap-4 lg:grid-cols-4" aria-hidden="true">
        <div v-for="card in count" :key="card" class="flex items-center gap-4 rounded-xl border border-line bg-white p-4 shadow-sm">
            <SkeletonBase width="2.75rem" height="2.75rem" radius="0.5rem" />
            <div class="min-w-0 flex-1 space-y-2">
                <SkeletonBase width="55%" height="0.65rem" />
                <SkeletonBase width="40%" height="1.25rem" />
                <SkeletonBase width="30%" height="0.55rem" />
            </div>
        </div>
    </div>
</template>
