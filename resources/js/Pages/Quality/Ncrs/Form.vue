<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import QualityNav from '@/Components/Quality/QualityNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    ncr: { type: Object, default: null },
    inspections: { type: Array, required: true },
    defaultInspectionId: { type: Number, default: null },
    severities: { type: Array, required: true },
    members: { type: Array, required: true },
    subcontractors: { type: Array, required: true },
});

const editing = computed(() => !!props.ncr);
const n = props.ncr;
const initialInspection = props.inspections.find((i) => i.value === props.defaultInspectionId) ? props.defaultInspectionId : null;
const form = useForm({
    quality_inspection_id: initialInspection,
    issue: n?.issue ?? '',
    location: n?.location ?? props.inspections.find((i) => i.value === initialInspection)?.location ?? '',
    severity: n?.severity ?? 'minor',
    responsible_user_id: n?.responsible_user_id ?? null,
    subcontractor_id: n?.subcontractor_id ?? null,
    target_date: n?.target_date ?? '',
});

watch(
    () => form.quality_inspection_id,
    (id) => {
        const location = props.inspections.find((i) => i.value === id)?.location;
        if (location && !form.location) {
            form.location = location;
        }
    },
);

function submit() {
    const transform = (d) => {
        const data = { ...d, location: d.location || null, target_date: d.target_date || null };
        if (editing.value) {
            delete data.quality_inspection_id;
        }

        return data;
    };
    editing.value
        ? form.transform(transform).put(route('projects.ncrs.update', [props.project.id, props.ncr.id]))
        : form.transform(transform).post(route('projects.ncrs.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="quality" :title="editing ? ncr.ncr_number : 'Raise NCR'">
        <QualityNav :project-id="project.id" active="ncrs" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.ncrs.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">NCRs</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit ${ncr.ncr_number}` : 'Raise NCR' }}</h2>
                    <p class="text-xs text-slate-500">
                        <template v-if="editing && ncr.inspection">Raised from inspection {{ ncr.inspection }}.</template>
                        <template v-else-if="!editing">Link a failed or conditional inspection, or leave it empty for a manual NCR.</template>
                    </p>
                </div>
                <p v-if="form.errors.ncr" class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ form.errors.ncr }}</p>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5">
                    <SearchSelect v-if="!editing" v-model="form.quality_inspection_id" label="Source inspection" :options="inspections" class="sm:col-span-2" :error="form.errors.quality_inspection_id" />
                    <FormInput v-model="form.issue" label="Non-conformance" required multiline :rows="3" maxlength="2000" class="sm:col-span-2" :error="form.errors.issue" />
                    <FormInput v-model="form.location" label="Location" maxlength="255" :error="form.errors.location" />
                    <FormSelect v-model="form.severity" label="Severity" required :options="severities" :error="form.errors.severity" />
                    <SearchSelect v-model="form.responsible_user_id" label="Responsible person" :options="members" help="Active member of the project team." :error="form.errors.responsible_user_id" />
                    <SearchSelect v-model="form.subcontractor_id" label="Responsible subcontractor" :options="subcontractors" :error="form.errors.subcontractor_id" />
                    <FormInput v-model="form.target_date" type="date" label="Target date" :error="form.errors.target_date" />
                </div>
            </AppCard>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.ncrs.show', [project.id, ncr.id]) : route('projects.ncrs.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Raise NCR' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
