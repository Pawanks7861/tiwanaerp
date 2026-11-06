<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    viewUrl: { type: String, default: '' },
    downloadUrl: { type: String, default: '' },
    title: { type: String, default: 'PDF' },
    autoPrint: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const printNote = ref('');
const scale = ref(100);
const objectUrl = ref('');
const frame = ref(null);
const closeButton = ref(null);

watch(() => [props.show, props.viewUrl, props.autoPrint], () => {
    if (props.show && props.viewUrl) {
        load();
    } else {
        release();
    }
});

onBeforeUnmount(release);

function release() {
    if (objectUrl.value) {
        URL.revokeObjectURL(objectUrl.value);
        objectUrl.value = '';
    }
}

async function load() {
    release();
    loading.value = true;
    error.value = '';
    printNote.value = '';
    scale.value = 100;

    try {
        const response = await window.axios.get(props.viewUrl, {
            responseType: 'blob',
            validateStatus: () => true,
        });
        const type = String(response.headers['content-type'] || '');
        if (response.status !== 200 || !type.includes('pdf')) {
            error.value = 'Unable to generate PDF.';

            return;
        }
        objectUrl.value = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
        closeButton.value?.focus();
    } catch {
        error.value = 'Unable to generate PDF.';
    } finally {
        loading.value = false;
    }
}

function onFrameLoad() {
    if (props.autoPrint && objectUrl.value) {
        window.setTimeout(printPdf, 250);
    }
}

function printPdf() {
    printNote.value = '';
    const win = frame.value?.contentWindow;
    if (!win) {
        printNote.value = 'Automatic printing was blocked. Use Print again after the PDF finishes loading.';

        return;
    }
    try {
        win.focus();
        win.print();
    } catch {
        printNote.value = 'Automatic printing was blocked. Use the print control inside the PDF.';
    }
}

function zoom(delta) {
    scale.value = Math.min(200, Math.max(50, scale.value + delta));
}

function fit() {
    scale.value = 100;
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-[80] flex bg-slate-900/70" role="dialog" aria-modal="true" :aria-label="title">
        <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-white sm:m-4 sm:rounded-xl sm:shadow-xl">
            <header class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <h2 class="truncate text-sm font-semibold text-slate-900">{{ title }}</h2>
                <button ref="closeButton" type="button" class="rounded-md px-2 py-1 text-sm text-slate-600 hover:bg-slate-100" aria-label="Close" title="Close" @click="emit('close')">Close</button>
            </header>
            <div class="flex min-w-0 max-w-full flex-wrap items-center gap-1 border-b border-line px-3 py-2">
                <button type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" aria-label="Zoom out" title="Zoom out" @click="zoom(-25)">Zoom out</button>
                <button type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" aria-label="Zoom in" title="Zoom in" @click="zoom(25)">Zoom in</button>
                <button type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" aria-label="Fit" title="Fit" @click="fit">Fit</button>
                <button type="button" class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100" aria-label="Print PDF" title="Print PDF" @click="printPdf">
                    <Icon name="printer" :size="14" /> Print
                </button>
                <a :href="downloadUrl" class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100" aria-label="Download PDF" title="Download PDF">
                    <Icon name="download" :size="14" /> Download
                </a>
                <p v-if="printNote" class="w-full text-xs text-amber-800">{{ printNote }}</p>
            </div>
            <div class="min-h-0 flex-1 overflow-auto bg-slate-100 p-3">
                <div v-if="loading" class="space-y-3 rounded-lg bg-white p-4" aria-busy="true">
                    <span class="sr-only">Preparing PDF…</span>
                    <p class="text-sm text-slate-600">Preparing PDF…</p>
                    <span class="skeleton-block skeleton-shimmer block h-4 w-40" aria-hidden="true" />
                    <span class="skeleton-block skeleton-shimmer block h-64 w-full" aria-hidden="true" />
                </div>
                <div v-else-if="error" class="rounded-lg bg-white p-4 text-sm text-slate-700">
                    <p>{{ error }}</p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white" @click="load">Retry</button>
                        <button type="button" class="rounded-lg border border-line px-3 py-2 text-sm" @click="emit('close')">Close</button>
                    </div>
                </div>
                <iframe
                    v-else-if="objectUrl"
                    ref="frame"
                    :src="objectUrl"
                    :title="title"
                    class="h-full min-h-[70vh] border-0 bg-white"
                    :style="{ width: `${scale}%` }"
                    @load="onFrameLoad"
                />
            </div>
        </div>
    </div>
</template>
