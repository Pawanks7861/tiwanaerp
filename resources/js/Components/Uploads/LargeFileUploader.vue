<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

/**
 * Shared chunked uploader. The browser sends one part at a time and can resume after a refresh.
 * The page stays usable while a transfer is in progress.
 */
const props = defineProps({
    module: { type: String, required: true },
    sourceType: { type: String, default: null },
    sourceId: { type: [Number, String], default: null },
    category: { type: String, default: '' },
    accept: { type: String, default: '' },
    maxFiles: { type: Number, default: 1 },
    persist: { type: Boolean, default: false },
});

const emit = defineEmits(['completed', 'removed']);

const page = usePage();
const maxBytes = computed(() => Number(page.props.uploads?.max_bytes ?? 1073741824));
const chunkBytes = computed(() => Number(page.props.uploads?.chunk_bytes ?? 1048576));
const maxLabel = computed(() => page.props.uploads?.max_label ?? '1 GB');

const input = ref(null);
const dragging = ref(false);
const items = ref([]);
let sequence = 0;

const storageKey = computed(() => `tiwana.upload.${props.module}.${props.sourceType ?? ''}.${props.sourceId ?? ''}`);

function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (value >= 1073741824) {
        return `${(value / 1073741824).toFixed(value % 1073741824 === 0 ? 0 : 1)} GB`;
    }
    if (value >= 1048576) {
        return `${(value / 1048576).toFixed(1)} MB`;
    }
    if (value >= 1024) {
        return `${Math.round(value / 1024)} KB`;
    }

    return `${value} B`;
}

function messageFrom(error) {
    const data = error?.response?.data;
    if (error?.name === 'CanceledError' || error?.code === 'ERR_CANCELED') {
        return '';
    }

    return data?.errors?.file?.[0]
        || data?.errors?.chunk?.[0]
        || data?.errors?.upload_id?.[0]
        || data?.message
        || 'Upload interrupted — Retry';
}

async function sha256(buffer) {
    if (!window.crypto?.subtle) {
        return null;
    }
    const digest = await window.crypto.subtle.digest('SHA-256', buffer);

    return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function remember(item) {
    if (!item.id) {
        return;
    }
    const saved = JSON.parse(window.localStorage.getItem(storageKey.value) || '[]');
    const next = saved.filter((row) => row.id !== item.id);
    next.push({ id: item.id, name: item.name, size: item.size });
    window.localStorage.setItem(storageKey.value, JSON.stringify(next.slice(-props.maxFiles)));
}

function forget(id) {
    const saved = JSON.parse(window.localStorage.getItem(storageKey.value) || '[]').filter((row) => row.id !== id);
    if (saved.length) {
        window.localStorage.setItem(storageKey.value, JSON.stringify(saved));
    } else {
        window.localStorage.removeItem(storageKey.value);
    }
}

function addFiles(list) {
    const incoming = Array.from(list ?? []);
    for (const file of incoming) {
        const waiting = items.value.find((row) => row.status === 'paused' && !row.file && row.name === file.name && row.size === file.size);
        if (waiting) {
            waiting.file = file;
            resume(waiting);
            continue;
        }
        if (items.value.length >= props.maxFiles) {
            break;
        }
        const item = {
            key: ++sequence,
            file,
            id: null,
            name: file.name,
            size: file.size,
            loaded: 0,
            received: [],
            totalChunks: Math.max(1, Math.ceil(file.size / chunkBytes.value)),
            status: 'queued',
            error: '',
            speed: 0,
            paused: false,
            controller: null,
            stamp: 0,
            stampBytes: 0,
        };
        if (file.size > maxBytes.value) {
            item.status = 'failed';
            item.error = 'File exceeds 1 GB';
        }
        items.value.push(item);
        const row = items.value.at(-1);
        if (row.status !== 'failed') {
            start(row);
        }
    }
    if (input.value) {
        input.value.value = '';
    }
}

async function start(item) {
    item.paused = false;
    item.error = '';
    try {
        if (!item.id) {
            const saved = JSON.parse(window.localStorage.getItem(storageKey.value) || '[]')
                .find((row) => row.name === item.name && row.size === item.size);
            if (saved?.id) {
                const status = await window.axios.get(route('uploads.show', saved.id));
                const upload = status.data.upload;
                if (upload && ['initialized', 'uploading'].includes(upload.status)) {
                    item.id = upload.id;
                    item.received = upload.received ?? [];
                    item.totalChunks = upload.total_chunks;
                    item.loaded = item.received.length * (upload.chunk_bytes || chunkBytes.value);
                }
            }
        }
        if (!item.id) {
            const created = await window.axios.post(route('uploads.store'), {
                original_name: item.name,
                total_size: item.size,
                module: props.module,
                source_type: props.sourceType,
                source_id: props.sourceId || null,
            });
            item.id = created.data.upload.id;
            item.totalChunks = created.data.upload.total_chunks;
            item.received = [];
            remember(item);
        }
        await sendChunks(item);
        if (item.paused || item.status === 'cancelled') {
            return;
        }
        const done = await window.axios.post(route('uploads.complete', item.id), {
            category: props.category || null,
        });
        item.status = 'completed';
        item.loaded = item.size;
        item.received = done.data.upload.received ?? item.received;
        emit('completed', { id: item.id, name: item.name, size: item.size });
        if (!props.persist) {
            forget(item.id);
            items.value = items.value.filter((row) => row.key !== item.key);
        }
    } catch (error) {
        if (item.paused || item.status === 'cancelled') {
            return;
        }
        item.status = 'failed';
        item.error = messageFrom(error) || 'Upload interrupted — Retry';
    }
}

async function sendChunks(item) {
    const size = chunkBytes.value;
    item.status = 'uploading';
    item.stamp = performance.now();
    item.stampBytes = item.loaded;
    for (let number = 1; number <= item.totalChunks; number += 1) {
        if (item.paused || item.status === 'cancelled') {
            return;
        }
        if (item.received.includes(number)) {
            continue;
        }
        const startAt = (number - 1) * size;
        const blob = item.file.slice(startAt, Math.min(startAt + size, item.file.size));
        const buffer = await blob.arrayBuffer();
        const checksum = await sha256(buffer);
        const body = new FormData();
        body.append('chunk', new Blob([buffer]), 'chunk.bin');
        if (checksum) {
            body.append('checksum', checksum);
        }
        item.controller = new AbortController();
        let attempt = 0;
        while (attempt < 3) {
            try {
                const response = await window.axios.post(route('uploads.chunks.store', { upload: item.id, number }), body, {
                    signal: item.controller.signal,
                    onUploadProgress: (event) => {
                        const base = (number - 1) * size;
                        item.loaded = Math.min(item.size, base + (event.loaded || 0));
                        const elapsed = (performance.now() - item.stamp) / 1000;
                        if (elapsed > 0.4) {
                            item.speed = Math.max(0, (item.loaded - item.stampBytes) / elapsed);
                        }
                    },
                });
                item.received = response.data.upload.received ?? [...item.received, number];
                break;
            } catch (error) {
                if (item.paused || error?.code === 'ERR_CANCELED') {
                    return;
                }
                attempt += 1;
                if (attempt >= 3) {
                    throw error;
                }
            }
        }
    }
}

function pause(item) {
    item.paused = true;
    item.status = 'paused';
    item.controller?.abort();
}

function resume(item) {
    item.status = 'uploading';
    start(item);
}

async function cancel(item) {
    item.status = 'cancelled';
    item.paused = true;
    item.controller?.abort();
    if (item.id) {
        try {
            await window.axios.delete(route('uploads.cancel', item.id));
        } catch {
            // The session expires on its own if the cancel request cannot get through.
        }
        forget(item.id);
        emit('removed', { id: item.id });
    }
    items.value = items.value.filter((row) => row.key !== item.key);
}

function retry(item) {
    item.error = '';
    start(item);
}

onMounted(async () => {
    const saved = JSON.parse(window.localStorage.getItem(storageKey.value) || '[]');
    for (const row of saved) {
        try {
            const status = await window.axios.get(route('uploads.show', row.id));
            const upload = status.data.upload;
            if (!upload || ['cancelled', 'expired', 'failed', 'completed'].includes(upload.status)) {
                forget(row.id);
                continue;
            }
            items.value.push({
                key: ++sequence,
                file: null,
                id: upload.id,
                name: upload.original_name || row.name,
                size: upload.total_size || row.size,
                loaded: (upload.uploaded_chunks || 0) * (upload.chunk_bytes || chunkBytes.value),
                received: upload.received ?? [],
                totalChunks: upload.total_chunks,
                status: 'paused',
                error: '',
                speed: 0,
                paused: true,
                controller: null,
                stamp: 0,
                stampBytes: 0,
            });
        } catch {
            forget(row.id);
        }
    }
});

function percent(item) {
    if (!item.size) {
        return 0;
    }

    return Math.min(100, Math.round((item.loaded / item.size) * 100));
}

function remaining(item) {
    return Math.max(0, item.totalChunks - (item.received?.length || 0));
}
</script>

<template>
    <div class="space-y-3">
        <div
            class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-center"
            :class="dragging ? 'border-brand-500 bg-brand-50' : ''"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="dragging = false; addFiles($event.dataTransfer?.files)"
        >
            <p class="text-sm font-medium text-slate-800">Drop a file here, or browse</p>
            <p class="mt-1 text-xs text-slate-500">Maximum file size: {{ maxLabel }}. Large files upload in parts and can be resumed.</p>
            <button type="button" class="mt-3 inline-flex min-h-10 items-center rounded-lg border border-line bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50" @click="input?.click()">
                Browse files
            </button>
            <input ref="input" type="file" class="hidden" :accept="accept" :multiple="maxFiles > 1" @change="addFiles($event.target.files)" />
        </div>

        <ul v-if="items.length" class="space-y-2">
            <li v-for="item in items" :key="item.key" class="rounded-lg border border-line bg-white p-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-800">{{ item.name }}</p>
                        <p class="text-xs text-slate-500">
                            {{ formatBytes(item.loaded) }} / {{ formatBytes(item.size) }}
                            <span v-if="item.status === 'uploading'"> · {{ percent(item) }}%</span>
                            <span v-if="item.speed"> · {{ formatBytes(item.speed) }}/s</span>
                            <span v-if="item.status === 'uploading'"> · {{ remaining(item) }} parts left</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button v-if="item.status === 'uploading'" type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100" @click="pause(item)">Pause</button>
                        <button v-if="item.status === 'paused' && item.file" type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100" @click="resume(item)">Resume</button>
                        <button v-if="item.status === 'failed'" type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100" @click="retry(item)">Retry</button>
                        <button v-if="item.status !== 'completed'" type="button" class="rounded px-2 py-1 text-xs text-red-700 hover:bg-red-50" @click="cancel(item)">Cancel</button>
                    </div>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full bg-brand-500 transition-all" :style="{ width: `${percent(item)}%` }" />
                </div>
                <p v-if="item.status === 'paused' && !item.file" class="mt-2 text-xs text-slate-600">Choose the same file to resume this upload.</p>
                <p v-if="item.status === 'completed'" class="mt-2 text-xs text-emerald-700">Upload complete.</p>
                <p v-if="item.error" class="mt-2 text-xs text-red-700">{{ item.error }}</p>
                <button v-if="item.status === 'paused' && !item.file" type="button" class="mt-2 text-xs font-medium text-brand-700" @click="input?.click()">Browse to resume</button>
            </li>
        </ul>
    </div>
</template>
