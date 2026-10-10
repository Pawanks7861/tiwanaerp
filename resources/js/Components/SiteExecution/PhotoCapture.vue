<script setup>
import UniversalFileViewer from '@/Components/Files/UniversalFileViewer.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDateTime } from '@/lib/format';
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

/**
 * Site photos: camera capture (or gallery), client-side downscale to JPEG, optional geolocation
 * (never blocks the upload) and sequential uploads. Each photo keeps one uuid so a retry after a
 * network failure cannot create a duplicate on the server.
 */
const props = defineProps({
    photos: { type: Array, required: true },
    uploadUrl: { type: String, default: null },
    deleteRoute: { type: Function, default: null },
    canEdit: { type: Boolean, default: false },
    maxKb: { type: Number, default: 10240 },
});

const MAX_EDGE = 1920;
const caption = ref('');
const viewing = ref(null);
const queue = reactive([]);
const busy = ref(false);
const camera = ref(null);
const gallery = ref(null);

const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => ((c === 'x' ? Math.random() * 16 : (Math.random() * 4) | 8) | 0).toString(16)));

function position() {
    if (!navigator.geolocation) {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude }),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 6000, maximumAge: 120000 },
        );
    });
}

async function compress(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || typeof createImageBitmap !== 'function') {
        return file;
    }
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
        if (!blob || blob.size >= file.size) {
            return file;
        }

        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}

async function picked(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';
    if (!files.length) {
        return;
    }

    const where = await position();
    for (const original of files) {
        const file = await compress(original);
        queue.push({
            key: uuid(),
            uuid: uuid(),
            file,
            name: original.name,
            caption: caption.value,
            taken_at: new Date(original.lastModified || Date.now()).toISOString(),
            ...(where ?? {}),
            state: file.size > props.maxKb * 1024 ? 'too_big' : 'waiting',
            error: file.size > props.maxKb * 1024 ? `Larger than ${Math.round(props.maxKb / 1024)} MB after compression.` : null,
        });
    }
    caption.value = '';
    upload();
}

function send(item) {
    return new Promise((resolve) => {
        item.state = 'uploading';
        item.error = null;
        router.post(
            props.uploadUrl,
            {
                photo: item.file,
                uuid: item.uuid,
                caption: item.caption || null,
                latitude: item.latitude ?? null,
                longitude: item.longitude ?? null,
                taken_at: item.taken_at,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (item.state = 'done'),
                onError: (errors) => ((item.state = 'failed'), (item.error = errors.photo || errors.diary || Object.values(errors)[0] || 'Upload failed.')),
                onFinish: () => {
                    if (item.state === 'uploading') {
                        item.state = 'failed';
                        item.error = 'Network error. Retry when the connection is back.';
                    }
                    resolve();
                },
            },
        );
    });
}

async function upload() {
    if (busy.value) {
        return;
    }
    busy.value = true;
    let next;
    while ((next = queue.find((i) => i.state === 'waiting'))) {
        await send(next);
    }
    busy.value = false;
    for (let i = queue.length - 1; i >= 0; i--) {
        if (queue[i].state === 'done') {
            queue.splice(i, 1);
        }
    }
}

function retry(item) {
    item.state = 'waiting';
    upload();
}

function remove(photo) {
    if (props.deleteRoute) {
        router.delete(props.deleteRoute(photo), { preserveScroll: true, preserveState: true });
    }
}
</script>

<template>
    <div>
        <div v-if="canEdit && uploadUrl" class="space-y-3">
            <input ref="camera" type="file" accept="image/*" capture="environment" class="hidden" multiple @change="picked" />
            <input ref="gallery" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" multiple @change="picked" />
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-slate-700">Caption for the next photos</span>
                <input v-model="caption" type="text" maxlength="255" placeholder="e.g. Slab reinforcement, grid C-D" class="block min-h-11 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200" />
            </label>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="flex min-h-11 items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 text-sm font-medium text-white hover:bg-brand-700" @click="camera?.click()">
                    <Icon name="camera" :size="18" />Take photo
                </button>
                <button type="button" class="flex min-h-11 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50" @click="gallery?.click()">
                    <Icon name="folder" :size="18" />From gallery
                </button>
            </div>
            <p class="text-xs text-slate-500">Photos are reduced on the phone before upload. Location is attached when the browser allows it; it is optional.</p>
            <ul v-if="queue.length" class="divide-y divide-line rounded-lg border border-line text-sm">
                <li v-for="item in queue" :key="item.key" class="flex items-center justify-between gap-2 px-3 py-2">
                    <div class="min-w-0">
                        <p class="truncate text-slate-800">{{ item.name }}</p>
                        <p class="text-xs" :class="item.error ? 'text-red-600' : 'text-slate-500'">
                            {{ item.error ?? (item.state === 'uploading' ? 'Uploading…' : item.state === 'waiting' ? 'Waiting…' : 'Uploaded') }}
                        </p>
                    </div>
                    <AppButton v-if="item.state === 'failed'" size="sm" variant="secondary" @click="retry(item)">Retry</AppButton>
                </li>
            </ul>
        </div>

        <ul v-if="photos.length" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <li v-for="photo in photos" :key="photo.id" class="overflow-hidden rounded-lg border border-line bg-white">
                <button type="button" class="block aspect-[4/3] w-full bg-slate-100" @click="viewing = photo">
                    <img :src="photo.thumb_url" :alt="photo.caption || 'Site photo'" loading="lazy" class="h-full w-full object-cover" />
                </button>
                <div class="flex items-start justify-between gap-1 p-2">
                    <div class="min-w-0 text-xs">
                        <p class="truncate font-medium text-slate-800">{{ photo.caption || 'No caption' }}</p>
                        <p class="text-slate-500">{{ formatDateTime(photo.taken_at) }}</p>
                        <p v-if="photo.latitude" class="text-slate-400 tabular">{{ Number(photo.latitude).toFixed(5) }}, {{ Number(photo.longitude).toFixed(5) }}</p>
                    </div>
                    <button v-if="canEdit && deleteRoute" type="button" class="flex size-11 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove photo" @click="remove(photo)">
                        <Icon name="trash" :size="16" />
                    </button>
                </div>
            </li>
        </ul>
        <p v-else-if="!canEdit" class="text-sm text-slate-500">No photos.</p>
        <UniversalFileViewer
            :show="!!viewing"
            source="site_photo"
            :file-id="viewing?.id ?? null"
            :gallery="photos.map((photo) => ({ source: 'site_photo', id: photo.id }))"
            @close="viewing = null"
        />
    </div>
</template>
