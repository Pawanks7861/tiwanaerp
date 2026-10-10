<script setup>
import UniversalFileViewer from '@/Components/Files/UniversalFileViewer.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import LargeFileUploader from '@/Components/Uploads/LargeFileUploader.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
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

const viewing = ref(null);

const retryNotice = ref('');

function retryPreview(revision) {
    retryNotice.value = '';
    window.axios.post(route('files.preview.retry', { source: 'drawing_revision', id: revision.id })).then(() => router.reload({ preserveScroll: true })).catch((error) => {
        retryNotice.value = error?.response?.status === 429
            ? 'Wait a moment before retrying the preview.'
            : 'Preview generation failed';
    });
}

const props = defineProps({
    project: { type: Object, required: true },
    drawing: { type: Object, required: true },
    revisions: { type: Array, required: true },
    disciplines: { type: Array, required: true },
    extensions: { type: Array, required: true },
    maxMb: { type: Number, required: true },
    audit: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['revision', 'revision_code', 'file'].map((k) => page.props.errors?.[k]).find(Boolean));

const editing = ref(false);
const detailsForm = useForm({ drawing_number: props.drawing.drawing_number, title: props.drawing.title, discipline: props.drawing.discipline });
function saveDetails() {
    detailsForm.put(route('projects.drawings.update', [props.project.id, props.drawing.id]), { preserveScroll: true, onSuccess: () => (editing.value = false) });
}

const uploading = ref(false);
const uploadForm = useForm({ revision_code: '', remarks: '', upload_id: null });
function openUpload() {
    uploadForm.reset();
    uploadForm.clearErrors();
    uploading.value = true;
}
function upload() {
    uploadForm
        .transform((d) => ({ ...d, remarks: d.remarks || null }))
        .post(route('projects.drawings.revisions.store', [props.project.id, props.drawing.id]), { forceFormData: true, preserveScroll: true, onSuccess: () => (uploading.value = false) });
}

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    submit: { title: 'Submit revision for review?', message: 'The file can no longer be withdrawn once submitted.', label: 'Submit', danger: false, method: 'post', route: 'submit' },
    review: { title: 'Start reviewing this revision?', message: 'Marks the revision as under review.', label: 'Start review', danger: false, method: 'post', route: 'review' },
    withdraw: { title: 'Withdraw this draft revision?', message: 'The draft is withdrawn and kept in the history. Its code cannot be used again.', label: 'Withdraw', danger: true, method: 'delete', route: 'withdraw' },
};
function confirmAction() {
    const { action, revision } = confirming.value;
    const def = ACTIONS[action];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(`projects.drawings.revisions.${def.route}`, [props.project.id, props.drawing.id, revision.id]);
    def.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const deciding = ref(null);
const decisionForm = useForm({ comments: '' });
function openDecision(kind, revision) {
    decisionForm.reset();
    decisionForm.clearErrors();
    deciding.value = { kind, revision };
}
function decide() {
    const { kind, revision } = deciding.value;
    decisionForm
        .transform((d) => ({ comments: d.comments || null }))
        .post(route(`projects.drawings.revisions.${kind}`, [props.project.id, props.drawing.id, revision.id]), { preserveScroll: true, onSuccess: () => (deciding.value = null) });
}

const timeline = (r) =>
    [
        ['Uploaded', r.uploaded_at, r.uploaded_by],
        ['Submitted', r.submitted_at, r.submitted_by],
        ['Review started', r.reviewed_at, r.reviewed_by],
        [r.status === 'rejected' ? 'Rejected' : 'Approved', r.decided_at, r.decided_by],
        ['Superseded', r.superseded_at, null],
    ].filter(([, at]) => at);
</script>

<template>
    <ProjectLayout :project="project" active="drawings" :title="drawing.drawing_number">
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.drawings.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Drawings</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold break-all text-slate-900">{{ drawing.drawing_number }}</h2>
                            <StatusBadge :status="drawing.status" :label="drawing.status_label" />
                        </div>
                        <p class="mt-1 text-sm break-words text-slate-800">{{ drawing.title }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-3">
                            <div><dt class="text-slate-500">Discipline</dt><dd>{{ drawing.discipline_label }}</dd></div>
                            <div><dt class="text-slate-500">Current revision</dt><dd class="font-mono font-semibold" :class="drawing.current_revision ? 'text-green-700' : 'text-slate-400'">{{ drawing.current_revision ?? 'None approved' }}</dd></div>
                            <div><dt class="text-slate-500">Revisions</dt><dd>{{ revisions.length }}</dd></div>
                        </dl>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" @click="editing = true">Edit details</AppButton>
                        <AppButton v-if="can.upload" size="sm" icon="plus" @click="openUpload">Upload revision</AppButton>
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
            </AppCard>

            <p v-if="retryNotice" class="mb-3 text-sm text-slate-600">{{ retryNotice }}</p>
            <AppCard title="Revision history" subtitle="Newest first. Superseded, rejected and withdrawn revisions stay on record." :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="r in revisions" :key="r.id" class="p-4" :class="r.is_current ? 'bg-green-50/50' : ''">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-base font-semibold text-slate-900">{{ r.revision_code }}</span>
                                    <StatusBadge :status="r.status" :label="r.status_label" />
                                    <span v-if="r.is_current" class="rounded bg-green-600 px-1.5 py-0.5 text-xs font-medium text-white">Current</span>
                                    <span v-if="r.supersedes" class="text-xs text-slate-500">supersedes {{ r.supersedes }}</span>
                                </div>
                                <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 text-xs text-slate-600">
                                    <Icon name="paperclip" :size="12" />
                                    <span class="break-all">{{ r.file_name }}</span>
                                    <span class="text-slate-400">· {{ r.extension.toUpperCase() }} · {{ formatBytes(r.size_bytes) }}</span>
                                </div>
                                <div class="mt-0.5 font-mono text-[11px] break-all text-slate-400" :title="r.checksum">SHA-256 {{ r.checksum }}</div>
                                <p v-if="r.remarks" class="mt-1 text-xs text-slate-600">{{ r.remarks }}</p>
                                <p v-if="r.review_comments" class="mt-1 text-xs" :class="r.status === 'rejected' ? 'text-red-700' : 'text-slate-600'">Review: {{ r.review_comments }}</p>
                                <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-slate-500">
                                    <li v-for="[label, at, by] in timeline(r)" :key="label">{{ label }} {{ formatDateTime(at) }}<template v-if="by"> · {{ by }}</template></li>
                                </ul>
                            </div>
                            <div class="flex flex-wrap gap-2 lg:justify-end">
                                <button v-if="r.download_url && !['pending', 'processing', 'failed'].includes(r.preview?.status)" type="button" class="inline-flex h-8 items-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50" @click="viewing = r">
                                    <Icon name="document" :size="14" />{{ ['dwg', 'dxf'].includes(r.extension) ? 'View Drawing' : 'View' }}
                                </button>
                                <span v-if="['pending', 'processing'].includes(r.preview?.status)" class="inline-flex h-8 items-center text-xs text-slate-500">Generating Preview</span>
                                <span v-if="r.preview?.status === 'failed'" class="inline-flex h-8 items-center text-xs text-slate-500">{{ r.preview.message || 'Preview generation failed' }}</span>
                                <button v-if="r.preview?.status === 'failed'" type="button" class="inline-flex h-8 items-center rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700" @click="retryPreview(r)">Retry Preview</button>
                                <span v-if="r.preview?.status === 'unsupported'" class="inline-flex h-8 items-center text-xs text-slate-500">{{ r.preview.message }}</span>
                                <a v-if="r.download_url" :href="r.download_url" class="inline-flex h-8 items-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                    <Icon name="download" :size="14" />Download
                                </a>
                                <AppButton v-if="r.can.submit" size="sm" @click="confirming = { action: 'submit', revision: r }">Submit</AppButton>
                                <AppButton v-if="r.can.review" size="sm" @click="confirming = { action: 'review', revision: r }">Start review</AppButton>
                                <AppButton v-if="r.can.approve" size="sm" @click="openDecision('approve', r)">Approve</AppButton>
                                <AppButton v-if="r.can.reject" size="sm" variant="danger" @click="openDecision('reject', r)">Reject</AppButton>
                                <AppButton v-if="r.can.withdraw" size="sm" variant="ghost" icon="trash" :aria-label="`Withdraw revision ${r.revision_code}`" @click="confirming = { action: 'withdraw', revision: r }" />
                            </div>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AuditTrail :entries="audit" title="Audit history" />
        </div>

        <UniversalFileViewer
            :show="!!viewing"
            source="drawing_revision"
            :file-id="viewing?.id ?? null"
            :gallery="revisions.filter((revision) => revision.download_url).map((revision) => ({ source: 'drawing_revision', id: revision.id }))"
            @close="viewing = null"
        />

        <AppModal :show="editing" title="Drawing details" @close="editing = false">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput
                    v-model="detailsForm.drawing_number"
                    label="Drawing number"
                    required
                    maxlength="60"
                    :disabled="!can.editNumber"
                    :help="can.editNumber ? null : 'Fixed once a revision has been approved.'"
                    :error="detailsForm.errors.drawing_number"
                />
                <FormSelect v-model="detailsForm.discipline" label="Discipline" required :options="disciplines" :error="detailsForm.errors.discipline" />
                <FormInput v-model="detailsForm.title" label="Title" required maxlength="200" class="sm:col-span-2" :error="detailsForm.errors.title" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="editing = false">Back</AppButton>
                <AppButton :loading="detailsForm.processing" @click="saveDetails">Save</AppButton>
            </template>
        </AppModal>

        <AppModal :show="uploading" title="Upload new revision" @close="uploading = false">
            <p class="mb-3 text-sm text-slate-600">The new revision starts as a draft. Earlier revisions are never overwritten.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput v-model="uploadForm.revision_code" label="Revision code" required maxlength="10" uppercase help="Must be new for this drawing." :error="uploadForm.errors.revision_code" />
                <FormInput v-model="uploadForm.remarks" label="Remarks" maxlength="1000" :error="uploadForm.errors.remarks" />
                <div class="sm:col-span-2">
                    <LargeFileUploader persist module="drawing" source-type="drawing" :source-id="drawing.id" @completed="(file) => (uploadForm.upload_id = file.id)" />
                    <p v-if="uploadForm.errors.file || uploadForm.errors.upload_id" class="mt-1 text-xs text-red-700">{{ uploadForm.errors.file || uploadForm.errors.upload_id }}</p>
                </div>
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="uploading = false">Back</AppButton>
                <AppButton :loading="uploadForm.processing" :disabled="!uploadForm.upload_id || !uploadForm.revision_code" @click="upload">Upload</AppButton>
            </template>
        </AppModal>

        <AppModal :show="!!deciding" :title="deciding ? `${deciding.kind === 'approve' ? 'Approve' : 'Reject'} revision ${deciding.revision.revision_code}` : ''" @close="deciding = null">
            <p v-if="deciding?.kind === 'approve'" class="mb-3 text-sm text-slate-600">
                This revision becomes current<template v-if="drawing.current_revision"> and revision {{ drawing.current_revision }} is marked superseded</template>.
            </p>
            <FormInput
                v-model="decisionForm.comments"
                :label="deciding?.kind === 'reject' ? 'Reason for rejection' : 'Comments'"
                :required="deciding?.kind === 'reject'"
                multiline
                :rows="3"
                maxlength="1000"
                :error="decisionForm.errors.comments ?? decisionForm.errors.revision"
            />
            <template #footer>
                <AppButton variant="secondary" @click="deciding = null">Back</AppButton>
                <AppButton
                    :variant="deciding?.kind === 'reject' ? 'danger' : 'primary'"
                    :loading="decisionForm.processing"
                    :disabled="deciding?.kind === 'reject' && decisionForm.comments.trim().length < 5"
                    @click="decide"
                >{{ deciding?.kind === 'reject' ? 'Reject' : 'Approve' }}</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? ACTIONS[confirming.action].title : ''"
            :message="confirming ? ACTIONS[confirming.action].message : ''"
            :confirm-label="confirming ? ACTIONS[confirming.action].label : ''"
            :danger="confirming ? ACTIONS[confirming.action].danger : true"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
    </ProjectLayout>
</template>
