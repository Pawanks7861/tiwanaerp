<script setup>
/**
 * Replaced by the project top-nav Subcontract dropdown (AppTabs children).
 * Kept as a no-op so existing page imports do not break.
 */
defineProps({
    projectId: { type: Number, required: true },
    active: { type: String, required: true },
});
</script>

<template>
    <span class="hidden" aria-hidden="true" />
</template>
