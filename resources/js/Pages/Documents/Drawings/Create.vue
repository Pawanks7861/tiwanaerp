<script setup>
import LargeFileUploader from '@/Components/Uploads/LargeFileUploader.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    disciplines: { type: Array, required: true },
    extensions: { type: Array, required: true },
    maxMb: { type: Number, required: true },
});

const form = useForm({ drawing_number: '', title: '', discipline: 'architectural', revision_code: 'R0', remarks: '', upload_id: null });

function submit() {
    form.transform((d) => ({ ...d, remarks: d.remarks || null })).post(route('projects.drawings.store', props.project.id), { forceFormData: true });
}
</script>

<template>
    <ProjectLayout :project="project" active="drawings" title="Register drawing">
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.drawings.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Drawings</Link>
                    <h2 class="text-lg font-semibold text-slate-900">Register drawing</h2>
                    <p class="text-xs text-slate-500">The drawing number is yours (it is never generated or changed by the system). The first revision starts as a draft.</p>
                </div>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5">
                    <FormInput v-model="form.drawing_number" label="Drawing number" required maxlength="60" placeholder="e.g. ARC-GF-101" :error="form.errors.drawing_number" />
                    <FormSelect v-model="form.discipline" label="Discipline" required :options="disciplines" :error="form.errors.discipline" />
                    <FormInput v-model="form.title" label="Title" required maxlength="200" class="sm:col-span-2" :error="form.errors.title" />
                    <FormInput v-model="form.revision_code" label="First revision code" required maxlength="10" uppercase help="e.g. R0, P1 or A. Codes are never reused." :error="form.errors.revision_code" />
                    <FormInput v-model="form.remarks" label="Remarks" maxlength="1000" :error="form.errors.remarks" />
                    <div class="sm:col-span-2">
                        <LargeFileUploader persist module="drawing" source-type="project" :source-id="project.id" @completed="(file) => (form.upload_id = file.id)" />
                        <p v-if="form.errors.file || form.errors.upload_id" class="mt-1 text-xs text-red-700">{{ form.errors.file || form.errors.upload_id }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ extensions.join(', ').toUpperCase() }} and other non-executable project files. PDF and images can be previewed; other files are download only.</p>
                    </div>
                </div>
            </AppCard>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="route('projects.drawings.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!form.upload_id" class="flex-1 md:flex-none">Register drawing</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
