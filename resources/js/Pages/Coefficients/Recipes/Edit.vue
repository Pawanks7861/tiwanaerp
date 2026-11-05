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
    recipe: { type: Object, default: null },
    materials: { type: Array, required: true },
    projects: { type: Array, required: true },
    can: { type: Object, required: true },
});

const editing = computed(() => !!props.recipe);
const readOnly = computed(() => !props.can.update);

const blankLine = () => ({
    material_id: null,
    consumption_qty: '1',
    consumption_unit: '',
    wastage_percent: '0',
    notes: '',
});

const form = useForm({
    code: props.recipe?.code ?? '',
    name: props.recipe?.name ?? '',
    description: props.recipe?.description ?? '',
    notes: props.recipe?.notes ?? '',
    project_id: props.recipe?.project_id ?? null,
    effective_from: props.recipe?.effective_from ?? '',
    effective_to: props.recipe?.effective_to ?? '',
    lines: props.recipe?.lines?.length ? props.recipe.lines.map((l) => ({ ...l })) : [blankLine()],
});

function onMaterial(row) {
    const m = props.materials.find((o) => o.value === row.material_id);
    if (m?.unit && !row.consumption_unit) row.consumption_unit = m.unit;
}

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
        ...form.data(),
        project_id: form.project_id || null,
        effective_from: form.effective_from || null,
        effective_to: form.effective_to || null,
        lines: form.lines.map(({ material_id, consumption_qty, consumption_unit, wastage_percent, notes }) => ({
            material_id,
            consumption_qty,
            consumption_unit,
            wastage_percent,
            notes: notes || null,
        })),
    };
    if (editing.value) {
        form.transform(() => payload).put(route('coefficients.recipes.update', props.recipe.id), { preserveScroll: true });
    } else {
        form.transform(() => payload).post(route('coefficients.recipes.store'));
    }
}

function activateRecipe() {
    router.post(route('coefficients.recipes.activate', props.recipe.id), {}, { preserveScroll: true });
}

function newVersion() {
    router.post(route('coefficients.recipes.new-version', props.recipe.id));
}
</script>

<template>
    <AppLayout :title="editing ? recipe.name : 'New material recipe'">
        <PageHeader
            :title="editing ? `${recipe.code} · ${recipe.name}` : 'New material recipe'"
            :back="route('coefficients.recipes.index')"
            :subtitle="editing ? `Version ${recipe.version}` : 'Draft recipe lines apply per BOQ unit when activated.'"
        >
            <template v-if="editing" #actions>
                <StatusBadge :status="recipe.status" :label="recipe.status_label" />
                <AppButton v-if="can.activate" variant="secondary" @click="activateRecipe">Activate</AppButton>
                <AppButton v-if="can.new_version" variant="secondary" @click="newVersion">New version</AppButton>
            </template>
        </PageHeader>

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Header">
                <fieldset :disabled="readOnly" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormInput v-if="!editing" v-model="form.code" label="Code" required maxlength="40" :error="form.errors.code" />
                    <FormInput v-model="form.name" label="Name" required maxlength="160" class="sm:col-span-2" :error="form.errors.name" />
                    <FormSelect
                        v-model="form.project_id"
                        label="Project (optional)"
                        :options="[{ value: null, label: 'Company-wide' }, ...projects]"
                        :error="form.errors.project_id"
                    />
                    <FormInput v-model="form.effective_from" label="Effective from" type="date" />
                    <FormInput v-model="form.effective_to" label="Effective to" type="date" />
                    <FormInput v-model="form.description" label="Description" class="sm:col-span-3" />
                    <FormInput v-model="form.notes" label="Notes" class="sm:col-span-3" />
                </fieldset>
            </AppCard>

            <AppCard title="Consumption lines" :subtitle="`${form.lines.length} material(s) per BOQ unit`">
                <div class="overflow-x-auto rounded-xl border border-line">
                    <table class="min-w-[880px] w-full text-left text-xs">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                            <tr>
                                <th class="px-2 py-2">Material</th>
                                <th class="px-2 py-2">Qty / BOQ unit</th>
                                <th class="px-2 py-2">Unit</th>
                                <th class="px-2 py-2">Wastage %</th>
                                <th class="px-2 py-2">Notes</th>
                                <th v-if="!readOnly" class="px-2 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in form.lines" :key="index" class="border-t border-line align-top">
                                <td class="px-1 py-1">
                                    <select v-model="line.material_id" class="w-52 rounded border-slate-200 py-1" :disabled="readOnly" @change="onMaterial(line)">
                                        <option :value="null">— Select —</option>
                                        <option v-for="m in materials" :key="m.value" :value="m.value">{{ m.label }}</option>
                                    </select>
                                </td>
                                <td class="px-1 py-1"><input v-model="line.consumption_qty" class="w-24 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="readOnly" /></td>
                                <td class="px-1 py-1"><input v-model="line.consumption_unit" class="w-16 rounded border-slate-200 px-1.5 py-1" :disabled="readOnly" /></td>
                                <td class="px-1 py-1"><input v-model="line.wastage_percent" class="w-16 rounded border-slate-200 px-1.5 py-1 tabular" :disabled="readOnly" /></td>
                                <td class="px-1 py-1"><input v-model="line.notes" class="w-40 rounded border-slate-200 px-1.5 py-1" :disabled="readOnly" /></td>
                                <td v-if="!readOnly" class="px-1 py-1"><button type="button" class="text-rose-600" @click="removeLine(index)">Del</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!readOnly" class="mt-3 flex gap-2">
                    <AppButton type="button" variant="secondary" @click="addLine">Add line</AppButton>
                    <AppButton type="submit" :loading="form.processing">Save draft</AppButton>
                </div>
            </AppCard>
        </form>
    </AppLayout>
</template>
