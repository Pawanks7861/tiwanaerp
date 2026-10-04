<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import { formatDateTime } from '@/lib/format';
import { computed, ref } from 'vue';

/**
 * Read-only audit history (AuditPresenter::trail), newest first.
 */
const props = defineProps({
    entries: { type: Array, required: true },
    title: { type: String, default: 'History' },
});

const expanded = ref(false);
const visible = computed(() => (expanded.value ? props.entries : props.entries.slice(0, 8)));
</script>

<template>
    <AppCard :title="title" :subtitle="entries.length ? null : 'No recorded changes yet.'">
        <ol v-if="entries.length" class="space-y-3">
            <li v-for="entry in visible" :key="entry.id" class="border-l-2 border-line pl-3">
                <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
                    <span class="font-medium text-slate-900">{{ entry.event_label }}</span>
                    <span v-if="entry.subject" class="text-xs text-slate-500">{{ entry.subject }}</span>
                </div>
                <div class="text-xs text-slate-500">{{ formatDateTime(entry.at) }}<template v-if="entry.user"> · {{ entry.user }}</template></div>
                <dl v-if="entry.changes.length" class="mt-1 space-y-0.5 text-xs">
                    <div v-for="change in entry.changes" :key="change.field" class="flex min-w-0 flex-wrap gap-x-1">
                        <dt class="text-slate-500">{{ change.field }}:</dt>
                        <dd class="min-w-0 break-all text-slate-700">
                            <template v-if="change.old !== null"><span class="text-slate-400 line-through">{{ change.old }}</span> → </template>{{ change.new ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </li>
        </ol>
        <button v-if="entries.length > 8" type="button" class="mt-3 text-xs font-medium text-brand-700 hover:underline" @click="expanded = !expanded">
            {{ expanded ? 'Show less' : `Show all ${entries.length} entries` }}
        </button>
    </AppCard>
</template>
