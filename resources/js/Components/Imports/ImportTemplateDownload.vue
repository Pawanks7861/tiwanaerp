<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    module: { type: String, required: true },
    projectId: { type: Number, default: null },
    size: { type: String, default: 'md' }, // sm | md
    label: { type: String, default: 'Download Template' },
});

const open = ref(false);
const root = ref(null);

const baseQuery = computed(() => {
    const q = {};
    if (props.projectId) {
        q.project_id = props.projectId;
    }
    return q;
});

const xlsxUrl = computed(() => route('imports.template', { module: props.module, format: 'xlsx', ...baseQuery.value }));
const csvUrl = computed(() => route('imports.template', { module: props.module, format: 'csv', ...baseQuery.value }));

const buttonClass = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-lg border border-line bg-white font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2',
    props.size === 'sm' ? 'h-8 px-3 text-xs' : 'h-10 px-4 text-sm',
].join(' '));

function onDocClick(event) {
    if (!root.value?.contains(event.target)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <div ref="root" class="relative inline-flex">
        <button type="button" :class="buttonClass" :aria-expanded="open" @click.stop="open = !open">
            {{ label }}
            <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
            </svg>
        </button>
        <div
            v-if="open"
            class="absolute right-0 z-30 mt-1 w-44 overflow-hidden rounded-lg border border-line bg-white py-1 shadow-lg"
            role="menu"
        >
            <a
                :href="xlsxUrl"
                class="block px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                role="menuitem"
                @click="open = false"
            >
                Excel (.xlsx)
            </a>
            <a
                :href="csvUrl"
                class="block px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                role="menuitem"
                @click="open = false"
            >
                CSV (.csv)
            </a>
        </div>
    </div>
</template>
