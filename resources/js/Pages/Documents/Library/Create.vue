<script setup>
import LargeFileUploader from '@/Components/Uploads/LargeFileUploader.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    folders: { type: Array, required: true },
    defaultFolderId: { type: Number, default: null },
    categories: { type: Array, required: true },
    extensions: { type: Array, required: true },
    maxMb: { type: Number, required: true },
});

const form = useForm({
    name: '',
    category: 'other',
    document_folder_id: props.folders.some((f) => f.value === props.defaultFolderId) ? props.defaultFolderId : null,
    reference_no: '',
    description: '',
    revision_label: '',
    notes: '',
    upload_id: null,
});

function submit() {
    form.transform((d) => ({
        ...d,
        reference_no: d.reference_no || null,
        description: d.description || null,
        revision_label: d.revision_label || null,
        notes: d.notes || null,
    })).post(route('projects.documents.store', props.project.id), { forceFormData: true });
}
</script>

<template>
    <ProjectLayout :project="project" active="documents" title="New document">
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.documents.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Documents</Link>
                    <h2 class="text-lg font-semibold text-slate-900">New document</h2>
                    <p class="text-xs text-slate-500">A document number is assigned automatically; enter any external reference separately. The file becomes version 1 (draft).</p>
                </div>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5">
                    <FormInput v-model="form.name" label="Name" required maxlength="200" class="sm:col-span-2" :error="form.errors.name" />
                    <FormSelect v-model="form.category" label="Category" required :options="categories" :error="form.errors.category" />
                    <SearchSelect v-model="form.document_folder_id" label="Folder" :options="folders" placeholder="Unfiled" :error="form.errors.document_folder_id" />
                    <FormInput v-model="form.reference_no" label="External reference" maxlength="100" placeholder="e.g. client letter no." :error="form.errors.reference_no" />
                    <FormInput v-model="form.revision_label" label="Revision label" maxlength="30" placeholder="Optional, e.g. Rev A" :error="form.errors.revision_label" />
                    <FormInput v-model="form.description" label="Description" multiline :rows="2" maxlength="1000" class="sm:col-span-2" :error="form.errors.description" />
                    <div class="sm:col-span-2">
                        <LargeFileUploader persist module="document" source-type="project" :source-id="project.id" @completed="(file) => (form.upload_id = file.id)" />
                        <p v-if="form.errors.file || form.errors.upload_id" class="mt-1 text-xs text-red-700">{{ form.errors.file || form.errors.upload_id }}</p>
                    </div>
                    <FormInput v-model="form.notes" label="Version notes" maxlength="1000" class="sm:col-span-2" :error="form.errors.notes" />
                </div>
            </AppCard>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="route('projects.documents.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!form.upload_id" class="flex-1 md:flex-none">Save draft</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
