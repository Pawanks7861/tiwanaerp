<script setup>
import CadFileViewer from '@/Components/Files/CadFileViewer.vue';
import { usesBrowserCad } from '@/lib/cadSession';
import { formatBytes } from '@/lib/files';
import { formatDateTime } from '@/lib/format';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    source: { type: String, default: '' },
    fileId: { type: Number, default: null },
    gallery: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const current = ref({ source: '', id: null });
const file = ref(null);
const body = ref(null);
const error = ref('');
const scale = ref(1);
const rotation = ref(0);
const sheet = ref(0);
const csvMode = ref('table');
const root = ref(null);
const closeButton = ref(null);
let timer = null;

const position = computed(() => props.gallery.findIndex((item) => item.source === current.value.source && item.id === current.value.id));
const visual = computed(() => ['image', 'svg'].includes(file.value?.strategy) && file.value?.status === 'ready');
const cad = computed(() => file.value?.status === 'ready' && usesBrowserCad(file.value));
const pdf = computed(() => file.value?.status === 'ready' && (file.value?.strategy === 'pdf' || file.value?.strategy === 'office'));
const generating = computed(() => ['pending', 'processing'].includes(file.value?.status));
const retrying = ref(false);
const activeSheet = computed(() => body.value?.sheets?.[sheet.value] ?? null);

watch(() => [props.show, props.source, props.fileId], () => {
    if (!props.show || !props.fileId) {
        stop();
        file.value = null;

        return;
    }
    current.value = { source: props.source, id: props.fileId };
    load();
});

function stop() {
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }
}

async function load() {
    stop();
    error.value = '';
    body.value = null;
    scale.value = 1;
    rotation.value = 0;
    sheet.value = 0;
    csvMode.value = 'table';

    try {
        const response = await window.axios.get(route('files.show', { source: current.value.source, id: current.value.id }));
        file.value = response.data.file;
        if (generating.value) {
            timer = setTimeout(load, 2000);

            return;
        }
        if (['text', 'csv', 'spreadsheet', 'archive'].includes(file.value.strategy)) {
            const preview = await window.axios.get(route('files.preview', { source: current.value.source, id: current.value.id }));
            body.value = preview.data;
        }
        closeButton.value?.focus();
    } catch (exception) {
        file.value = null;
        error.value = exception?.response?.status === 403 ? 'Permission denied' : 'Preview not available';
    }
}

function step(delta) {
    const next = props.gallery[position.value + delta];
    if (!next) {
        return;
    }
    current.value = { source: next.source, id: next.id };
    load();
}

function zoom(delta) {
    scale.value = Math.min(4, Math.max(0.25, Math.round((scale.value + delta) * 100) / 100));
}

function fit() {
    scale.value = 1;
    rotation.value = 0;
}

async function retryPreview() {
    retrying.value = true;
    try {
        await window.axios.post(route('files.preview.retry', { source: current.value.source, id: current.value.id }));
        await load();
    } catch (exception) {
        error.value = exception?.response?.status === 429 ? 'Wait a moment before retrying the preview.' : 'Preview generation failed';
    } finally {
        retrying.value = false;
    }
}

function fullscreen() {
    if (document.fullscreenElement) {
        document.exitFullscreen();

        return;
    }
    root.value?.requestFullscreen?.();
}

function onKey(event) {
    if (props.show && event.key === 'Escape' && !document.fullscreenElement) {
        emit('close');
    }
}

window.addEventListener('keydown', onKey);
onBeforeUnmount(() => {
    stop();
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div v-if="show" ref="root" class="fixed inset-0 z-[80] flex bg-slate-900/70" role="dialog" aria-modal="true" aria-label="File viewer">
        <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-white sm:m-4 sm:rounded-xl sm:shadow-xl">
            <header class="flex items-start justify-between gap-3 border-b border-line px-4 py-3">
                <div class="min-w-0">
                    <h2 class="truncate text-sm font-semibold text-slate-900">{{ file?.name || 'File' }}</h2>
                    <p v-if="file" class="mt-0.5 truncate text-xs text-slate-500">
                        {{ file.type_label }} · {{ formatBytes(file.size_bytes) }}
                        <template v-if="file.uploaded_by"> · {{ file.uploaded_by }}</template>
                        <template v-if="file.uploaded_at"> · {{ formatDateTime(file.uploaded_at) }}</template>
                        <span v-if="cad" class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">Local browser viewer</span>
                    </p>
                </div>
                <button ref="closeButton" type="button" class="rounded-md px-2 py-1 text-sm text-slate-600 hover:bg-slate-100" aria-label="Close" title="Close" @click="emit('close')">Close</button>
            </header>

            <div class="flex min-w-0 max-w-full flex-wrap gap-1 border-b border-line px-3 py-2">
                <a v-if="file" :href="file.download_url" class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100" title="Download original">Download original</a>
                <button v-if="visual || pdf" type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" aria-label="Zoom out" title="Zoom out" @click="zoom(-0.25)">Zoom out</button>
                <button v-if="visual || pdf" type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" aria-label="Zoom in" title="Zoom in" @click="zoom(0.25)">Zoom in</button>
                <button v-if="visual || pdf" type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" aria-label="Fit" title="Fit" @click="fit">Fit</button>
                <button v-if="visual" type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" aria-label="Rotate" title="Rotate" @click="rotation = (rotation + 90) % 360">Rotate</button>
                <button type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" aria-label="Full screen" title="Full screen" @click="fullscreen">Full screen</button>
                <button v-if="gallery.length > 1" type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100 disabled:opacity-40" aria-label="Previous file" title="Previous" :disabled="position <= 0" @click="step(-1)">Previous</button>
                <button v-if="gallery.length > 1" type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100 disabled:opacity-40" aria-label="Next file" title="Next" :disabled="position < 0 || position >= gallery.length - 1" @click="step(1)">Next</button>
                <button v-if="body?.kind === 'csv'" type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-100" @click="csvMode = csvMode === 'table' ? 'raw' : 'table'">{{ csvMode === 'table' ? 'Raw text' : 'Table' }}</button>
            </div>

            <div class="min-h-0 flex-1 overflow-auto bg-slate-100" :class="cad ? 'p-0' : 'p-3'">
                <p v-if="error" class="rounded-lg bg-white p-4 text-sm text-slate-700">{{ error }}</p>
                <p v-else-if="!file" class="p-4 text-sm text-slate-500">Opening…</p>
                <div v-else-if="generating" class="rounded-lg bg-white p-6 text-center text-sm text-slate-700">
                    {{ file.strategy === 'cad' ? 'Generating drawing preview…' : 'Generating preview…' }}
                </div>
                <div v-else-if="visual" class="flex min-h-full items-center justify-center overflow-auto">
                    <img :src="file.stream_url" :alt="file.name" class="max-w-full origin-center object-contain" :style="{ transform: `scale(${scale}) rotate(${rotation}deg)` }" />
                </div>
                <CadFileViewer
                    v-else-if="cad"
                    :stream-url="file.stream_url"
                    :file-name="file.name"
                    :size-bytes="file.size_bytes"
                    :memory-warning="file.message || ''"
                    class="h-full"
                />
                <iframe v-else-if="pdf" :src="file.stream_url" :title="file.name" class="h-full min-h-[70vh] border-0 bg-white" :style="{ width: `${Math.round(scale * 100)}%` }" />
                <video v-else-if="file.strategy === 'video'" :src="file.stream_url" controls class="max-h-full max-w-full" :aria-label="file.name" />
                <audio v-else-if="file.strategy === 'audio'" :src="file.stream_url" controls class="w-full" :aria-label="file.name" />
                <div v-else-if="body?.kind === 'text' || (body?.kind === 'csv' && csvMode === 'raw')" class="rounded-lg bg-white p-4">
                    <pre class="whitespace-pre-wrap break-words text-sm text-slate-800">{{ body.text }}</pre>
                </div>
                <div v-else-if="body?.kind === 'csv'" class="overflow-auto rounded-lg bg-white">
                    <table class="min-w-full text-left text-xs">
                        <tbody>
                            <tr v-for="(row, rowIndex) in body.rows" :key="rowIndex" class="border-b border-line">
                                <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="px-2 py-1 align-top break-all">{{ cell }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else-if="body?.kind === 'spreadsheet'" class="rounded-lg bg-white p-3">
                    <label v-if="body.sheets?.length > 1" class="mb-2 block text-xs text-slate-600">
                        Sheet
                        <select v-model.number="sheet" class="ml-2 rounded border-slate-300 text-sm">
                            <option v-for="(item, itemIndex) in body.sheets" :key="item.name" :value="itemIndex">{{ item.name }}</option>
                        </select>
                    </label>
                    <div class="overflow-auto">
                        <table v-if="activeSheet" class="min-w-full text-left text-xs">
                            <tbody>
                                <tr v-for="(row, rowIndex) in activeSheet.rows" :key="rowIndex" class="border-b border-line">
                                    <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="px-2 py-1 align-top break-all">{{ cell }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-slate-600">{{ body.message || 'Preview not available' }}</p>
                    </div>
                </div>
                <ul v-else-if="body?.kind === 'archive' && body.entries?.length" class="rounded-lg bg-white p-4 text-sm text-slate-800">
                    <li v-for="entry in body.entries" :key="entry" class="truncate border-b border-line py-1">{{ entry }}</li>
                </ul>
                <div v-else class="rounded-lg bg-white p-6 text-sm text-slate-700">
                    <p class="font-medium text-slate-900">{{ file.name }}</p>
                    <p class="mt-1">{{ file.type_label }} · {{ formatBytes(file.size_bytes) }}</p>
                    <p v-if="file.message" class="mt-2">{{ file.message }}</p>
                    <p v-else class="mt-2">Preview not available. Download the original file.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button v-if="file.can_retry" type="button" class="inline-flex rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-slate-800" :disabled="retrying" @click="retryPreview">Retry preview</button>
                        <a :href="file.download_url" class="inline-flex rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white">Download original</a>
                    </div>
                </div>
                <p v-if="file?.note" class="mt-3 text-xs text-slate-500">{{ file.note }}</p>
                <p v-if="body?.message" class="mt-3 text-xs text-slate-500">{{ body.message }}</p>
                <p v-if="file?.message && file.status !== 'ready' && !generating" class="mt-3 text-xs text-slate-500">{{ file.message }}</p>
            </div>
        </div>
    </div>
</template>
