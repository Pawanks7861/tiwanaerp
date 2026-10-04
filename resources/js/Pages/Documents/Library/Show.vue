<script setup>
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import FileUpload from '@/Components/Form/FileUpload.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatBytes } from '@/lib/files';
import { formatDateTime } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    document: { type: Object, required: true },
    versions: { type: Array, required: true },
    folders: { type: Array, required: true },
    categories: { type: Array, required: true },
    extensions: { type: Array, required: true },
    maxMb: { type: Number, required: true },
    audit: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['document', 'file'].map((k) => page.props.errors?.[k]).find(Boolean));

const editing = ref(false);
const d = props.document;
const detailsForm = useForm({
    name: d.name,
    category: d.category,
    document_folder_id: d.document_folder_id,
    reference_no: d.reference_no ?? '',
    description: d.description ?? '',
});
function saveDetails() {
    detailsForm
        .transform((x) => ({ ...x, reference_no: x.reference_no || null, description: x.description || null }))
        .put(route('projects.documents.update', [props.project.id, props.document.id]), { preserveScroll: true, onSuccess: () => (editing.value = false) });
}

const uploading = ref(false);
const versionForm = useForm({ revision_label: '', notes: '', file: null });
function openUpload() {
    versionForm.reset();
    versionForm.clearErrors();
    uploading.value = true;
}
function upload() {
    versionForm
        .transform((x) => ({ ...x, revision_label: x.revision_label || null, notes: x.notes || null }))
        .post(route('projects.documents.versions.store', [props.project.id, props.document.id]), { forceFormData: true, preserveScroll: true, onSuccess: () => (uploading.value = false) });
}

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    publish: { title: 'Publish this document?', message: 'It becomes active for the project team. New versions can still be added.', label: 'Publish', danger: false, method: 'post', route: 'projects.documents.publish' },
    archive: { title: 'Archive this document?', message: 'It stays readable with its full history but takes no new versions until restored.', label: 'Archive', danger: false, method: 'post', route: 'projects.documents.archive' },
    restore: { title: 'Restore this document?', message: 'It becomes active again.', label: 'Restore', danger: false, method: 'post', route: 'projects.documents.restore' },
    delete: { title: 'Delete this draft document?', message: 'The draft is removed from the library. Its versions and files are kept on record.', label: 'Delete', danger: true, method: 'delete', route: 'projects.documents.destroy' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(action.route, [props.project.id, props.document.id]);
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}
</script>

<template>
    <ProjectLayout :project="project" active="documents" :title="document.document_number">
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.documents.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Documents</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ document.document_number }}</h2>
                            <StatusBadge :status="document.status" :label="document.status_label" />
                        </div>
                        <p class="mt-1 text-sm break-words text-slate-800">{{ document.name }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Category</dt><dd>{{ document.category_label }}</dd></div>
                            <div><dt class="text-slate-500">Folder</dt><dd>{{ document.folder ?? 'Unfiled' }}</dd></div>
                            <div><dt class="text-slate-500">External reference</dt><dd class="break-words">{{ document.reference_no ?? '—' }}</dd></div>
                            <div><dt class="text-slate-500">Versions</dt><dd>{{ versions.length }}</dd></div>
                        </dl>
                        <p v-if="document.description" class="mt-2 text-xs break-words text-slate-600">{{ document.description }}</p>
                        <p class="mt-2 text-xs text-slate-500">
                            Created {{ formatDateTime(document.created_at) }}<template v-if="document.created_by"> by {{ document.created_by }}</template>
                            <template v-if="document.archived_at"> · Archived {{ formatDateTime(document.archived_at) }} by {{ document.archived_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" @click="editing = true">Edit details</AppButton>
                        <AppButton v-if="can.addVersion" size="sm" icon="plus" @click="openUpload">Upload new version</AppButton>
                        <AppButton v-if="can.publish" size="sm" @click="confirming = 'publish'">Publish</AppButton>
                        <AppButton v-if="can.archive" size="sm" variant="secondary" icon="archive" @click="confirming = 'archive'">Archive</AppButton>
                        <AppButton v-if="can.restore" size="sm" @click="confirming = 'restore'">Restore</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete document" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
            </AppCard>

            <AppCard title="Version history" subtitle="Versions are immutable and never deleted. The newest version is current." :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="v in versions" :key="v.id" class="flex flex-col gap-3 p-4 lg:flex-row lg:items-start lg:justify-between" :class="v.is_current ? 'bg-green-50/50' : ''">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base font-semibold text-slate-900">v{{ v.version_no }}</span>
                                <span v-if="v.revision_label" class="text-sm text-slate-600">{{ v.revision_label }}</span>
                                <span v-if="v.is_current" class="rounded bg-green-600 px-1.5 py-0.5 text-xs font-medium text-white">Current</span>
                                <span v-if="v.duplicate_of" class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">Same file as v{{ v.duplicate_of }}</span>
                            </div>
                            <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 text-xs text-slate-600">
                                <Icon name="paperclip" :size="12" />
                                <span class="break-all">{{ v.file_name }}</span>
                                <span class="text-slate-400">· {{ v.extension.toUpperCase() }} · {{ formatBytes(v.size_bytes) }}</span>
                            </div>
                            <div class="mt-0.5 font-mono text-[11px] break-all text-slate-400" :title="v.checksum">SHA-256 {{ v.checksum }}</div>
                            <p v-if="v.notes" class="mt-1 text-xs text-slate-600">{{ v.notes }}</p>
                            <p class="mt-1 text-xs text-slate-500">Uploaded {{ formatDateTime(v.uploaded_at) }}<template v-if="v.uploaded_by"> by {{ v.uploaded_by }}</template></p>
                        </div>
                        <div class="flex flex-wrap gap-2 lg:justify-end">
                            <a v-if="v.preview_url" :href="v.preview_url" target="_blank" rel="noopener" class="inline-flex h-8 items-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                <Icon name="document" :size="14" />Preview
                            </a>
                            <a :href="v.download_url" class="inline-flex h-8 items-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                <Icon name="download" :size="14" />Download
                            </a>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AuditTrail :entries="audit" title="Audit history" />
        </div>

        <AppModal :show="editing" title="Document details" @close="editing = false">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput v-model="detailsForm.name" label="Name" required maxlength="200" class="sm:col-span-2" :error="detailsForm.errors.name" />
                <FormSelect v-model="detailsForm.category" label="Category" required :options="categories" :error="detailsForm.errors.category" />
                <SearchSelect v-model="detailsForm.document_folder_id" label="Folder" :options="folders" placeholder="Unfiled" :error="detailsForm.errors.document_folder_id" />
                <FormInput v-model="detailsForm.reference_no" label="External reference" maxlength="100" class="sm:col-span-2" :error="detailsForm.errors.reference_no" />
                <FormInput v-model="detailsForm.description" label="Description" multiline :rows="2" maxlength="1000" class="sm:col-span-2" :error="detailsForm.errors.description" />
            </div>
            <p v-if="detailsForm.errors.document" class="mt-2 text-sm text-red-700">{{ detailsForm.errors.document }}</p>
            <template #footer>
                <AppButton variant="secondary" @click="editing = false">Back</AppButton>
                <AppButton :loading="detailsForm.processing" @click="saveDetails">Save</AppButton>
            </template>
        </AppModal>

        <AppModal :show="uploading" title="Upload new version" @close="uploading = false">
            <p class="mb-3 text-sm text-slate-600">Becomes version {{ (versions[0]?.version_no ?? 0) + 1 }} and the current version. Earlier versions stay available.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput v-model="versionForm.revision_label" label="Revision label" maxlength="30" :error="versionForm.errors.revision_label" />
                <FormInput v-model="versionForm.notes" label="Notes" maxlength="1000" :error="versionForm.errors.notes" />
                <div class="sm:col-span-2">
                    <FileUpload :accept="extensions.map((e) => `.${e}`).join(',')" :max-mb="maxMb" :error="versionForm.errors.file" @select="versionForm.file = $event" />
                    <p v-if="versionForm.file" class="mt-1 text-xs break-all text-slate-600">{{ versionForm.file.name }}</p>
                </div>
            </div>
            <p v-if="versionForm.errors.document" class="mt-2 text-sm text-red-700">{{ versionForm.errors.document }}</p>
            <template #footer>
                <AppButton variant="secondary" @click="uploading = false">Back</AppButton>
                <AppButton :loading="versionForm.processing" :disabled="!versionForm.file" @click="upload">Upload</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? ACTIONS[confirming].title : ''"
            :message="confirming ? ACTIONS[confirming].message : ''"
            :confirm-label="confirming ? ACTIONS[confirming].label : ''"
            :danger="confirming ? ACTIONS[confirming].danger : true"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
    </ProjectLayout>
</template>
