<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import QualityNav from '@/Components/Quality/QualityNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    inspection: { type: Object, default: null },
    checklists: { type: Array, required: true },
    sites: { type: Array, required: true },
    tasks: { type: Array, required: true },
    boqItems: { type: Array, required: true },
    members: { type: Array, required: true },
});

const editing = computed(() => !!props.inspection);
const i = props.inspection;
const form = useForm({
    quality_checklist_id: null,
    site_id: i?.site_id ?? null,
    location: i?.location ?? '',
    task_id: i?.task_id ?? null,
    boq_item_id: i?.boq_item_id ?? null,
    request_notes: i?.request_notes ?? '',
    inspection_date: i?.inspection_date ?? '',
    engineer_id: i?.engineer_id ?? null,
});

function submit() {
    const transform = (d) => {
        const data = { ...d, location: d.location || null, request_notes: d.request_notes || null, inspection_date: d.inspection_date || null };
        if (editing.value) {
            delete data.quality_checklist_id;
        }

        return data;
    };
    editing.value
        ? form.transform(transform).put(route('projects.inspections.update', [props.project.id, props.inspection.id]))
        : form.transform(transform).post(route('projects.inspections.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="quality" :title="editing ? inspection.inspection_number : 'Request inspection'">
        <QualityNav :project-id="project.id" active="inspections" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.inspections.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Inspections</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit ${inspection.inspection_number}` : 'Request inspection' }}</h2>
                    <p class="text-xs text-slate-500">
                        <template v-if="editing">Checklist: {{ inspection.checklist }} (fixed once requested).</template>
                        <template v-else>The checklist's checkpoints are copied into the inspection now; later template edits do not change it.</template>
                    </p>
                </div>
                <p v-if="form.errors.inspection" class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ form.errors.inspection }}</p>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5">
                    <SearchSelect v-if="!editing" v-model="form.quality_checklist_id" label="Checklist" required :options="checklists" class="sm:col-span-2" :error="form.errors.quality_checklist_id" />
                    <SearchSelect v-model="form.site_id" label="Site" :options="sites" :error="form.errors.site_id" />
                    <FormInput v-model="form.location" label="Location" maxlength="255" placeholder="e.g. Block A, 3rd floor slab" :error="form.errors.location" />
                    <SearchSelect v-model="form.task_id" label="Task" :options="tasks" :error="form.errors.task_id" />
                    <SearchSelect v-model="form.boq_item_id" label="BOQ item" :options="boqItems" help="Optional. Defaults to the task's BOQ line." :error="form.errors.boq_item_id" />
                    <FormInput v-model="form.inspection_date" type="date" label="Proposed date" :required="inspection?.status === 'scheduled'" :error="form.errors.inspection_date" />
                    <SearchSelect v-model="form.engineer_id" label="Inspector" :options="members" help="Active member of the project team." :error="form.errors.engineer_id" />
                    <FormInput v-model="form.request_notes" label="Request notes" multiline :rows="2" maxlength="1000" class="sm:col-span-2" :error="form.errors.request_notes" />
                </div>
            </AppCard>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.inspections.show', [project.id, inspection.id]) : route('projects.inspections.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Request inspection' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
