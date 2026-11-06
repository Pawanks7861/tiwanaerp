<script setup>
defineProps({
    width: { type: String, default: '100%' },
    height: { type: String, default: '0.75rem' },
    radius: { type: String, default: '0.375rem' },
    animate: { type: Boolean, default: true },
    tone: { type: String, default: 'light' },
});
</script>

<template>
    <span
        class="skeleton-block"
        :class="[animate ? 'skeleton-shimmer' : '', tone === 'dark' ? 'skeleton-block-on-dark' : '']"
        aria-hidden="true"
        :style="{ width, height, borderRadius: radius }"
    />
</template>
