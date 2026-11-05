<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    profile: { type: Object, default: null },
    projects: { type: Array, required: true },
    can: { type: Object, required: true },
});

const editing = computed(() => !!props.profile);
const readOnly = computed(() => !props.can.update);

const blankLine = () => ({ diameter_mm: 16, weight_kg_per_m: '0' });

const form = useForm({
    name: props.profile?.name ?? '',
    notes: props.profile?.notes ?? '',
    project_id: props.profile?.project_id ?? null,
    lines: props.profile?.lines?.length ? props.profile.lines.map((l) => ({ ...l })) : [blankLine()],
});

function addLine() {
    form.lines.push(blankLine());
}

function removeLine(index) {
    if (form.lines.length === 1) {
        form.lines = [blankLine()];
        return;
    }
    form.lines.splice(index, 1);
}

function submit() {
    const payload = {
        name: form.name,
        notes: form.notes || null,
        project_id: form.project_id || null,
        lines: form.lines,
    };
    if (editing.value) {
        form.transform(() => payload).put(route('coefficients.steel-profiles.update', props.profile.id), { preserveScroll: true });
    } else {
        form.transform(() => payload).post(route('coefficients.steel-profiles.store'));
    }
}

function activateProfile() {
    router.post(route('coefficients.steel-profiles.activate', props.profile.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="editing ? profile.name : 'New steel profile'">
        <PageHeader :title="editing ? profile.name : 'New steel profile'" :back="route('coefficients.steel-profiles.index')">
            <template v-if="editing" #actions>
                <StatusBadge :status="profile.status" :label="profile.status_label" />
                <AppButton v-if="can.activate" variant="secondary" @click="activateProfile">Activate</AppButton>
            </template>
        </PageHeader>

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard>
                <fieldset :disabled="readOnly" class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.name" label="Name" required maxlength="160" :error="form.errors.name" />
                    <FormSelect v-model="form.project_id" label="Project (optional)" :options="[{ value: null, label: 'Company-wide' }, ...projects]" />
                    <FormInput v-model="form.notes" label="Notes" class="sm:col-span-2" />
                </fieldset>
            </AppCard>

            <AppCard title="Diameter weights (kg/m)">
                <div class="overflow-x-auto rounded-xl border border-line">
                    <table class="min-w-[360px] w-full text-left text-xs">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                            <tr>
                                <th class="px-2 py-2">Diameter (mm)</th>
                                <th class="px-2 py-2">Weight kg/m</th>
                                <th v-if="!readOnly" class="px-2 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in form.lines" :key="index" class="border-t border-line">
                                <td class="px-1 py-1"><input v-model.number="line.diameter_mm" type="number" min="1" class="w-24 rounded border-slate-200 px-1.5 py-1" :disabled="readOnly" /></td>
                                <td class="px-1 py-1"><input v-model="line.weight_kg_per_m" class="w-28 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="readOnly" /></td>
                                <td v-if="!readOnly" class="px-1 py-1"><button type="button" class="text-rose-600" @click="removeLine(index)">Del</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!readOnly" class="mt-3 flex gap-2">
                    <AppButton type="button" variant="secondary" @click="addLine">Add diameter</AppButton>
                    <AppButton type="submit" :loading="form.processing">Save</AppButton>
                </div>
            </AppCard>
        </form>
    </AppLayout>
</template>
