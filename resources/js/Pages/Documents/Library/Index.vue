<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
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
import { formatDate } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    documents: { type: Object, required: true },
    folders: { type: Array, required: true },
    unfiledCount: { type: Number, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    categories: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const folderError = computed(() => page.props.errors?.folder);
const selected = computed(() => (props.filters.folder === undefined || props.filters.folder === null ? null : Number(props.filters.folder)));
const selectedFolder = computed(() => props.folders.find((f) => f.id === selected.value) ?? null);

/** Depth-first flattening of the folder tree. */
const tree = computed(() => {
    const children = {};
    props.folders.forEach((f) => (children[f.parent_id ?? 0] ??= []).push(f));
    const out = [];
    const walk = (parentId, depth, seen) => {
        (children[parentId] ?? []).forEach((f) => {
            if (seen.has(f.id)) {
                return;
            }
            out.push({ ...f, depth });
            walk(f.id, depth + 1, new Set([...seen, f.id]));
        });
    };
    walk(0, 0, new Set());

    return out;
});

const pathOf = (folder) => {
    const parts = [];
    for (let f = folder, guard = 0; f && guard < 50; f = props.folders.find((p) => p.id === f.parent_id), guard++) {
        parts.unshift(f.name);
    }

    return parts.join(' / ');
};
const parentOptions = (exclude = null) => {
    const banned = new Set();
    if (exclude) {
        const mark = (id) => {
            banned.add(id);
            props.folders.filter((f) => f.parent_id === id).forEach((f) => mark(f.id));
        };
        mark(exclude);
    }

    return [{ value: '', label: 'Top level' }, ...tree.value.filter((f) => !banned.has(f.id)).map((f) => ({ value: f.id, label: pathOf(f) }))];
};

function openFolder(id) {
    const query = { ...props.filters, folder: id ?? undefined };
    Object.keys(query).forEach((k) => (query[k] === undefined || query[k] === null || query[k] === '') && delete query[k]);
    router.get(route('projects.documents.index', props.project.id), query, { preserveState: true, preserveScroll: true, replace: true });
}

const folderModal = ref(null);
const folderForm = useForm({ name: '', parent_id: '' });
function newFolder() {
    folderForm.reset();
    folderForm.clearErrors();
    folderForm.parent_id = selectedFolder.value?.id ?? '';
    folderModal.value = 'create';
}
function editFolder() {
    folderForm.clearErrors();
    folderForm.name = selectedFolder.value.name;
    folderForm.parent_id = selectedFolder.value.parent_id ?? '';
    folderModal.value = 'edit';
}
function saveFolder() {
    const transform = (d) => ({ name: d.name, parent_id: d.parent_id || null });
    const options = { preserveScroll: true, onSuccess: () => (folderModal.value = null) };
    folderModal.value === 'create'
        ? folderForm.transform(transform).post(route('projects.documents.folders.store', props.project.id), options)
        : folderForm.transform(transform).put(route('projects.documents.folders.update', [props.project.id, selectedFolder.value.id]), options);
}
const deletingFolder = ref(false);
const processing = ref(false);
function deleteFolder() {
    processing.value = true;
    router.delete(route('projects.documents.folders.destroy', [props.project.id, selectedFolder.value.id]), {
        preserveScroll: true,
        onFinish: () => ((processing.value = false), (deletingFolder.value = false)),
    });
}

const columns = [
    { key: 'document_number', label: 'Document' },
    { key: 'category_label', label: 'Category', mobile: false },
    { key: 'current_version', label: 'Version' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <ProjectLayout :project="project" active="documents" title="Documents">
        <div class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <AppCard title="Folders" :padded="false">
                <template v-if="can.manageFolders" #actions>
                    <AppButton size="sm" variant="ghost" icon="plus" aria-label="New folder" @click="newFolder" />
                </template>
                <nav class="max-h-[60vh] overflow-y-auto py-1 text-sm" aria-label="Document folders">
                    <button type="button" class="flex w-full items-center gap-2 px-4 py-1.5 text-left hover:bg-slate-50" :class="selected === null ? 'bg-brand-50 font-medium text-brand-800' : 'text-slate-700'" @click="openFolder(null)">
                        <Icon name="grid" :size="14" />All documents
                    </button>
                    <button type="button" class="flex w-full items-center gap-2 px-4 py-1.5 text-left hover:bg-slate-50" :class="selected === 0 ? 'bg-brand-50 font-medium text-brand-800' : 'text-slate-700'" @click="openFolder(0)">
                        <Icon name="document" :size="14" /><span class="flex-1">Unfiled</span><span class="text-xs text-slate-400">{{ unfiledCount }}</span>
                    </button>
                    <button
                        v-for="folder in tree"
                        :key="folder.id"
                        type="button"
                        class="flex w-full items-center gap-2 py-1.5 pr-4 text-left hover:bg-slate-50"
                        :class="selected === folder.id ? 'bg-brand-50 font-medium text-brand-800' : 'text-slate-700'"
                        :style="{ paddingLeft: `${1 + folder.depth * 0.9}rem` }"
                        @click="openFolder(folder.id)"
                    >
                        <Icon name="folder" :size="14" class="shrink-0" /><span class="min-w-0 flex-1 truncate">{{ folder.name }}</span><span class="text-xs text-slate-400">{{ folder.documents_count }}</span>
                    </button>
                    <p v-if="!folders.length" class="px-4 py-2 text-xs text-slate-500">No folders yet.</p>
                </nav>
                <div v-if="can.manageFolders && selectedFolder" class="flex gap-2 border-t border-line p-2">
                    <AppButton size="sm" variant="secondary" icon="pencil" class="flex-1" @click="editFolder">Rename / move</AppButton>
                    <AppButton size="sm" variant="ghost" icon="trash" aria-label="Delete folder" @click="deletingFolder = true" />
                </div>
                <p v-if="folderError" class="border-t border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ folderError }}</p>
            </AppCard>

            <AppCard :title="selectedFolder ? pathOf(selectedFolder) : selected === 0 ? 'Unfiled documents' : 'All documents'" subtitle="Every upload is a new immutable version; nothing is overwritten." :padded="false">
                <template #actions>
                    <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.documents.create', project.id) + (selectedFolder ? `?folder=${selectedFolder.id}` : '')">New document</AppButton>
                </template>
                <FilterBar
                    :filters="{ search: filters.search ?? '', status: filters.status ?? 'all', category: filters.category ?? 'all', folder: filters.folder ?? '' }"
                    :selects="[
                        { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                        { key: 'category', options: [{ value: 'all', label: 'All categories' }, ...categories] },
                    ]"
                    placeholder="Search number, reference or name…"
                />
                <DataTable
                    :columns="columns"
                    :rows="documents.data"
                    :row-href="(row) => route('projects.documents.show', [project.id, row.id])"
                    empty-icon="folder"
                    empty-title="No documents"
                    empty-description="Upload a document to start its version history."
                >
                    <template #cell-document_number="{ row }">
                        <div class="font-mono text-xs font-medium text-slate-900">{{ row.document_number }}<span v-if="row.reference_no" class="ml-1 font-sans font-normal text-slate-500">· {{ row.reference_no }}</span></div>
                        <div class="text-sm break-words text-slate-800">{{ row.name }}</div>
                        <div v-if="row.folder && selected === null" class="text-xs text-slate-400">{{ row.folder }}</div>
                    </template>
                    <template #cell-current_version="{ row }">
                        <template v-if="row.current_version">
                            <span class="font-medium">v{{ row.current_version.version_no }}</span>
                            <span class="ml-1 text-xs text-slate-500">{{ formatBytes(row.current_version.size_bytes) }} · {{ formatDate(row.current_version.uploaded_at) }}</span>
                        </template>
                        <span v-else class="text-slate-400">—</span>
                    </template>
                    <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                </DataTable>
                <Pagination :paginator="documents" />
            </AppCard>
        </div>

        <AppModal :show="!!folderModal" :title="folderModal === 'create' ? 'New folder' : 'Rename or move folder'" @close="folderModal = null">
            <div class="grid gap-4">
                <FormInput v-model="folderForm.name" label="Folder name" required maxlength="120" :error="folderForm.errors.name" />
                <FormSelect v-model="folderForm.parent_id" label="Inside" :options="parentOptions(folderModal === 'edit' ? selectedFolder?.id : null)" placeholder="Top level" :error="folderForm.errors.parent_id" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="folderModal = null">Back</AppButton>
                <AppButton :loading="folderForm.processing" @click="saveFolder">Save</AppButton>
            </template>
        </AppModal>
        <ConfirmDialog
            :show="deletingFolder"
            title="Delete this folder?"
            message="Only an empty folder (no documents, no subfolders) can be deleted."
            confirm-label="Delete"
            :processing="processing"
            @close="deletingFolder = false"
            @confirm="deleteFolder"
        />
    </ProjectLayout>
</template>
