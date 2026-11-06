<script setup>
import SkeletonAvatar from './SkeletonAvatar.vue';
import SkeletonBase from './SkeletonBase.vue';

defineProps({
    rows: { type: Number, default: 5 },
});
</script>

<template>
    <div class="divide-y divide-line" aria-hidden="true">
        <div v-for="row in rows" :key="row" class="flex items-center gap-3 px-3 py-3">
            <SkeletonAvatar />
            <div class="min-w-0 flex-1 space-y-2">
                <SkeletonBase width="55%" height="0.75rem" />
                <SkeletonBase width="35%" height="0.6rem" />
            </div>
        </div>
    </div>
</template>
