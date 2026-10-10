<script setup>
import { applyLayerVisibility, cadErrorMessage, layerRows, openCadBuffer } from '@/lib/cadSession';
import { formatBytes } from '@/lib/files';
import { CadViewer } from '@flyfish-dev/cad-viewer';
import '@flyfish-dev/cad-viewer/style.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    streamUrl: { type: String, required: true },
    fileName: { type: String, required: true },
    sizeBytes: { type: Number, default: 0 },
    memoryWarning: { type: String, default: '' },
    renderer: { type: String, default: 'auto' },
});

const container = ref(null);
const shxInput = ref(null);
const progress = ref('Initializing CAD engine…');
const percent = ref(0);
const error = ref('');
const loading = ref(true);
const layers = ref([]);
const info = ref(null);
const missing = ref([]);
const dark = ref(true);
const showLayers = ref(false);
const backend = ref('auto');
let viewer = null;
let controller = null;

function phaseLabel(phase, message) {
    const labels = {
        read: 'Loading drawing…',
        detect: 'Loading drawing…',
        'worker-start': 'Initializing CAD engine…',
        'worker-ready': 'Initializing CAD engine…',
        'wasm-init': 'Initializing CAD engine…',
        parse: 'Parsing drawing…',
        normalize: 'Building geometry…',
        render: 'Rendering…',
        'native-render': 'Rendering…',
        done: 'Rendering…',
    };

    return labels[phase] || message || 'Loading drawing…';
}

function destroyViewer() {
    controller?.abort();
    controller = null;
    viewer?.destroy();
    viewer = null;
}

async function open(renderer = props.renderer) {
    error.value = '';
    loading.value = true;
    percent.value = 0;
    progress.value = 'Initializing CAD engine…';
    layers.value = [];
    info.value = null;
    missing.value = [];
    backend.value = renderer;
    destroyViewer();
    controller = new AbortController();
    viewer = new CadViewer({
        container: container.value,
        renderer,
        wasmPath: new URL('/wasm/', window.location.origin).href,
        workerUrl: new URL('/wasm/dwg-worker.js', window.location.origin).href,
        dwfWasmUrl: new URL('/wasm/dwfv-render.wasm', window.location.origin).href,
        useWorker: true,
        canvasOptions: {
            background: dark.value ? '#101827' : '#f8fafc',
            foreground: dark.value ? '#ffffff' : '#111827',
            fitMode: 'auto',
            contrastMode: 'adaptive',
        },
        onLoadProgress(event) {
            progress.value = phaseLabel(event.phase, event.message);
            if (typeof event.percent === 'number') {
                percent.value = event.percent;
            }
        },
        onReferenceStateChange(state) {
            missing.value = (state?.missing || []).map((item) => item.fileName).filter(Boolean);
        },
        onRenderStats(stats) {
            if (stats?.backend) {
                backend.value = stats.backend;
            }
        },
    });

    try {
        await viewer.preloadDwg({ signal: controller.signal });
        progress.value = 'Loading drawing…';
        await openCadBuffer(viewer, props.streamUrl, props.fileName, window.fetch.bind(window), controller.signal);
        const summary = viewer.getLoadResult()?.summary;
        info.value = summary
            ? {
                format: String(summary.format || '').toUpperCase(),
                entities: summary.entityCount,
                layers: summary.layerCount,
                warnings: (summary.warnings || []).slice(0, 3),
            }
            : null;
        layers.value = layerRows(viewer.getDocument());
        loading.value = false;
        viewer.resize();
    } catch (exception) {
        loading.value = false;
        error.value = cadErrorMessage(exception);
    }
}

function cancel() {
    controller?.abort();
    loading.value = false;
    error.value = 'Loading cancelled.';
}

function toggleLayer(layer) {
    layer.visible = !layer.visible;
    applyLayerVisibility(viewer, layer.name, layer.visible);
}

function setLayers(visible) {
    layers.value.forEach((layer) => {
        layer.visible = visible;
        applyLayerVisibility(viewer, layer.name, visible);
    });
}

function toggleBackground() {
    dark.value = !dark.value;
    viewer?.setCanvasOptions({
        background: dark.value ? '#101827' : '#f8fafc',
        foreground: dark.value ? '#ffffff' : '#111827',
    });
}

async function onShx(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !viewer) {
        return;
    }
    try {
        await viewer.addReferenceBuffer(await file.arrayBuffer(), file.name);
    } catch {
        error.value = 'The CAD font could not be used.';
    }
}

watch(() => [props.streamUrl, props.fileName], () => {
    if (container.value && props.streamUrl && props.fileName) {
        open();
    }
});

onMounted(() => {
    open();
});

onBeforeUnmount(() => {
    destroyViewer();
});
</script>

<template>
    <div class="flex h-full min-h-[70vh] min-w-0 max-w-full flex-col overflow-hidden rounded-lg bg-slate-950 text-slate-100">
        <div class="flex min-w-0 max-w-full flex-wrap gap-1 border-b border-white/10 px-2 py-2">
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="viewer?.zoomIn()">Zoom in</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="viewer?.zoomOut()">Zoom out</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="viewer?.fit('auto')">Fit</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="viewer?.fit('extents')">Fit extents</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="viewer?.fit('saved-view')">Saved view</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="viewer?.fit('auto')">Reset view</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="toggleBackground">{{ dark ? 'Light background' : 'Dark background' }}</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="showLayers = !showLayers">Layers</button>
            <button type="button" class="rounded px-2 py-1 text-xs hover:bg-white/10" @click="open('canvas2d')">Canvas view</button>
        </div>
        <p v-if="memoryWarning" class="px-3 py-2 text-xs text-amber-200">{{ memoryWarning }} · {{ formatBytes(sizeBytes) }}</p>
        <div class="relative min-h-0 min-w-0 flex-1">
            <div ref="container" class="h-full min-h-[24rem] w-full max-w-full" />
            <div v-if="loading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-slate-950/80 px-4 text-center">
                <p class="text-sm">{{ progress }}</p>
                <div class="h-1.5 w-48 overflow-hidden rounded bg-white/10">
                    <div class="h-full bg-white" :style="{ width: `${Math.max(8, Math.min(100, percent || 15))}%` }" />
                </div>
                <button type="button" class="rounded border border-white/20 px-3 py-1 text-xs" @click="cancel">Cancel</button>
            </div>
            <div v-if="error" class="absolute inset-x-3 bottom-3 rounded-lg bg-white p-3 text-sm text-slate-800">
                <p>{{ error }}</p>
                <button type="button" class="mt-2 text-xs font-medium text-brand-700" @click="open(backend === 'canvas2d' ? 'canvas2d' : 'auto')">Retry</button>
            </div>
            <aside v-if="showLayers" class="absolute right-2 top-2 max-h-[70%] w-48 overflow-auto rounded-lg bg-slate-900/95 p-2 text-xs shadow">
                <div class="mb-2 flex gap-2">
                    <button type="button" class="underline" @click="setLayers(true)">Show all</button>
                    <button type="button" class="underline" @click="setLayers(false)">Hide all</button>
                </div>
                <label v-for="layer in layers" :key="layer.name" class="flex items-center gap-2 py-0.5">
                    <input type="checkbox" :checked="layer.visible" @change="toggleLayer(layer)" />
                    <span class="truncate">{{ layer.name }}</span>
                </label>
                <p v-if="!layers.length" class="text-slate-400">No layers reported.</p>
            </aside>
        </div>
        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-[11px] text-slate-300">
            <span v-if="info">{{ info.format }} · {{ info.layers }} layers · {{ info.entities }} entities</span>
            <span>Renderer {{ backend }}</span>
            <span v-if="missing.length">Missing CAD font/reference: {{ missing.join(', ') }}</span>
            <button v-if="missing.length" type="button" class="underline" @click="shxInput?.click()">Supply font</button>
            <input ref="shxInput" type="file" accept=".shx,.SHX" class="hidden" @change="onShx" />
        </div>
        <ul v-if="info?.warnings?.length" class="px-3 pb-2 text-[11px] text-slate-400">
            <li v-for="warning in info.warnings" :key="warning">{{ warning }}</li>
        </ul>
    </div>
</template>
