<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { router } from '@inertiajs/vue3';

defineProps({
    tally: { type: Object, default: null },
});

function post(url) {
    router.post(url, {}, { preserveScroll: true });
}
</script>

<template>
    <div v-if="tally" class="flex flex-col gap-2 rounded-xl border border-line bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-slate-700">Tally</span>
                <StatusBadge :status="tally.status" :label="tally.status_label" />
            </div>
            <p v-if="tally.error" class="mt-1 text-xs text-slate-500">{{ tally.error }}</p>
            <p v-else-if="tally.reference" class="mt-1 text-xs text-slate-500">Reference {{ tally.reference }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <AppButton v-if="tally.can_preview" size="sm" variant="secondary" :href="tally.preview_url">Preview</AppButton>
            <AppButton v-if="tally.can_sync" size="sm" @click="post(tally.sync_url)">Sync to Tally</AppButton>
            <AppButton v-if="tally.can_retry" size="sm" variant="secondary" @click="post(tally.retry_url)">Retry</AppButton>
            <AppButton v-if="tally.log_url" size="sm" variant="ghost" :href="tally.log_url">View log</AppButton>
        </div>
    </div>
</template>
