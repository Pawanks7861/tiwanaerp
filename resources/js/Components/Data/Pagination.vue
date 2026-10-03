<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Laravel length-aware paginator (as serialised by Inertia).
 */
const props = defineProps({
    paginator: { type: Object, required: true },
});

const pages = computed(() => (props.paginator.links ?? []).slice(1, -1));
const prev = computed(() => props.paginator.prev_page_url);
const next = computed(() => props.paginator.next_page_url);
</script>

<template>
    <div
        v-if="paginator.total > 0"
        class="flex flex-col items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm text-slate-600 sm:flex-row"
    >
        <p class="tabular">
            Showing <span class="font-medium">{{ paginator.from }}</span>–<span class="font-medium">{{ paginator.to }}</span>
            of <span class="font-medium">{{ paginator.total }}</span>
        </p>
        <nav v-if="paginator.last_page > 1" class="flex items-center gap-1" aria-label="Pagination">
            <Link
                :href="prev ?? '#'"
                preserve-scroll
                class="rounded-md border border-line px-2.5 py-1.5"
                :class="prev ? 'bg-white hover:bg-slate-50' : 'pointer-events-none opacity-40'"
            >
                Prev
            </Link>
            <template v-for="link in pages" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="hidden rounded-md px-3 py-1.5 tabular sm:inline-block"
                    :class="link.active ? 'bg-brand-600 text-white' : 'hover:bg-slate-100'"
                >
                    {{ link.label }}
                </Link>
                <span v-else class="hidden px-2 sm:inline">…</span>
            </template>
            <Link
                :href="next ?? '#'"
                preserve-scroll
                class="rounded-md border border-line px-2.5 py-1.5"
                :class="next ? 'bg-white hover:bg-slate-50' : 'pointer-events-none opacity-40'"
            >
                Next
            </Link>
        </nav>
    </div>
</template>
