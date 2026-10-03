<script setup>
import FileUpload from '@/Components/Form/FileUpload.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDateTime } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Files attached to a record. Files are stored privately and downloaded through an
 * authorised route; there are no public URLs.
 */
const props = defineProps({
    attachments: { type: Array, required: true },
    attachableType: { type: String, required: true },
    attachableId: { type: Number, required: true },
    canUpload: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    placeholder: { type: String, default: 'GST certificate, PAN, cancelled cheque…' },
});

const form = useForm({ attachable_type: props.attachableType, attachable_id: props.attachableId, category: '', file: null });
const removing = ref(null);
const removeProcessing = ref(false);

function upload(file) {
    form.file = file;
    form.post(route('attachments.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset('file', 'category'),
    });
}

function remove() {
    removeProcessing.value = true;
    router.delete(route('attachments.destroy', removing.value.id), {
        preserveScroll: true,
        onFinish: () => {
            removeProcessing.value = false;
            removing.value = null;
        },
    });
}

function size(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    return bytes < 1048576 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1048576).toFixed(1)} MB`;
}
</script>

<template>
    <AppCard title="Documents" :subtitle="`${attachments.length} file${attachments.length === 1 ? '' : 's'}`" :padded="false">
        <div v-if="canUpload" class="space-y-3 border-b border-line p-4">
            <FormInput v-model="form.category" label="Document type (optional)" :placeholder="placeholder" maxlength="50" :error="form.errors.category" />
            <FileUpload :error="form.errors.file" :disabled="form.processing" @select="upload" />
            <div v-if="form.progress" class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full bg-brand-500 transition-all" :style="{ width: `${form.progress.percentage}%` }" />
            </div>
        </div>

        <ul v-if="attachments.length" class="divide-y divide-line">
            <li v-for="file in attachments" :key="file.id" class="flex items-center gap-3 px-4 py-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[10px] font-bold text-slate-500 uppercase">
                    {{ file.extension }}
                </div>
                <div class="min-w-0 flex-1">
                    <a :href="file.download_url" class="block truncate text-sm font-medium text-slate-800 hover:text-brand-700">{{ file.original_name }}</a>
                    <p class="truncate text-xs text-slate-500">
                        <template v-if="file.category">{{ file.category }} · </template>{{ size(file.size_bytes) }} · {{ file.uploaded_by ?? 'Unknown' }} ·
                        {{ formatDateTime(file.created_at) }}
                    </p>
                </div>
                <a :href="file.download_url" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100" :aria-label="`Download ${file.original_name}`">
                    <Icon name="download" :size="18" />
                </a>
                <button
                    v-if="canDelete"
                    type="button"
                    class="rounded-md p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                    :aria-label="`Remove ${file.original_name}`"
                    @click="removing = file"
                >
                    <Icon name="trash" :size="18" />
                </button>
            </li>
        </ul>
        <EmptyState v-else icon="paperclip" title="No documents yet" />

        <ConfirmDialog
            :show="!!removing"
            :title="`Remove ${removing?.original_name}?`"
            message="The file is removed from this record. A copy is kept for audit history."
            confirm-label="Remove file"
            :processing="removeProcessing"
            @close="removing = null"
            @confirm="remove"
        />
    </AppCard>
</template>
