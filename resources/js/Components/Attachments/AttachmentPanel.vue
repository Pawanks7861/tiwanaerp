<script setup>
import UniversalFileViewer from '@/Components/Files/UniversalFileViewer.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import LargeFileUploader from '@/Components/Uploads/LargeFileUploader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import SkeletonList from '@/Components/UI/Skeleton/SkeletonList.vue';
import { useContentSkeleton } from '@/composables/useContentSkeleton';
import { formatDateTime } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
    title: { type: String, default: 'Documents' },
    embedded: { type: Boolean, default: false },
    placeholder: { type: String, default: 'GST certificate, PAN, cancelled cheque…' },
    fixedCategory: { type: String, default: '' },
});

const form = useForm({ attachable_type: props.attachableType, attachable_id: props.attachableId, category: props.fixedCategory || '' });

const visibleAttachments = computed(() => {
    if (!props.fixedCategory) {
        return props.attachments;
    }

    return props.attachments.filter((file) => (file.category || '').toLowerCase() === props.fixedCategory.toLowerCase());
});
const sectionLoading = useContentSkeleton();
const viewing = ref(null);
const removing = ref(null);
const removeProcessing = ref(false);

function uploaded() {
    form.reset('category');
    router.reload({ preserveScroll: true });
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
    <component :is="embedded ? 'div' : AppCard" v-bind="embedded ? { class: 'rounded-xl border border-line' } : { title, subtitle: `${visibleAttachments.length} file${visibleAttachments.length === 1 ? '' : 's'}`, padded: false }">
        <div v-if="canUpload" class="space-y-3 border-b border-line p-3 sm:p-4">
            <FormInput v-if="!fixedCategory" v-model="form.category" label="Document type (optional)" :placeholder="placeholder" maxlength="50" :error="form.errors.category" />
            <LargeFileUploader
                module="attachment"
                :source-type="attachableType"
                :source-id="attachableId"
                :category="fixedCategory || form.category"
                @completed="uploaded"
            />
        </div>

        <div v-if="sectionLoading && !visibleAttachments.length" aria-busy="true">
            <span class="sr-only">Loading content…</span>
            <SkeletonList :rows="3" />
        </div>
        <ul v-else-if="visibleAttachments.length" class="divide-y divide-line">
            <li v-for="file in visibleAttachments" :key="file.id" class="flex items-center gap-3 px-3 py-3 sm:px-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[10px] font-bold text-slate-500 uppercase">
                    {{ file.extension }}
                </div>
                <div class="min-w-0 flex-1">
                    <button type="button" class="block max-w-full truncate text-left text-sm font-medium text-slate-800 hover:text-brand-700" @click="viewing = file">{{ file.original_name }}</button>
                    <p class="truncate text-xs text-slate-500">
                        <template v-if="file.category">{{ file.category }} · </template>{{ size(file.size_bytes) }} · {{ file.uploaded_by ?? 'Unknown' }} ·
                        {{ formatDateTime(file.created_at) }}
                    </p>
                    <button v-if="['dwg', 'dxf'].includes(file.extension)" type="button" class="text-xs font-medium text-brand-700" @click="viewing = file">View Drawing</button>
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

        <UniversalFileViewer
            :show="!!viewing"
            source="attachment"
            :file-id="viewing?.id ?? null"
            :gallery="attachments.map((file) => ({ source: 'attachment', id: file.id }))"
            @close="viewing = null"
        />

        <ConfirmDialog
            :show="!!removing"
            :title="`Remove ${removing?.original_name}?`"
            message="The file is removed from this record. A copy is kept for audit history."
            confirm-label="Remove file"
            :processing="removeProcessing"
            @close="removing = null"
            @confirm="remove"
        />
    </component>
</template>
