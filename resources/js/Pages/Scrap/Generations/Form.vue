<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ScrapNav from '@/Components/Scrap/ScrapNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    generation: { type: Object, default: null },
    options: { type: Object, required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.generation);
let tempId = 0;
const categoryFilter = ref(null);

const blankLine = () => ({ _key: `n${++tempId}`, scrap_category_id: categoryFilter.value, scrap_type_id: null, description: '', quantity: null, unit_id: null, unit: '', weight: null, condition: '', remarks: '' });

const form = useForm({
    generation_date: props.generation?.generation_date ?? props.today,
    location: props.generation?.location ?? '',
    source_type: props.generation?.source_type ?? 'site_work',
    department_id: props.generation?.department_id ?? null,
    remarks: props.generation?.remarks ?? '',
    items: props.generation?.items?.length
        ? props.generation.items.map((i, n) => {
              const type = props.options.scrapTypes.find((t) => t.value === i.scrap_type_id);

              return { _key: `i${n}`, scrap_category_id: type?.scrap_category_id ?? null, ...i, unit: type?.unit ?? '' };
          })
        : [blankLine()],
});

const filteredTypes = (line) => {
    const cat = line.scrap_category_id;

    return cat ? props.options.scrapTypes.filter((t) => t.scrap_category_id === cat) : props.options.scrapTypes;
};

const onTypeChange = (line) => {
    const type = props.options.scrapTypes.find((t) => t.value === line.scrap_type_id);
    line.unit_id = type?.unit_id ?? null;
    line.unit = type?.unit ?? '';
    if (type) {
        line.scrap_category_id = type.scrap_category_id;
    }
};

function submit() {
    const transform = (d) => ({
        ...d,
        location: d.location || null,
        department_id: d.department_id || null,
        remarks: d.remarks || null,
        items: d.items.map(({ _key, scrap_category_id, unit, ...line }) => ({
            ...line,
            description: line.description || null,
            weight: line.weight || null,
            condition: line.condition || null,
            remarks: line.remarks || null,
        })),
    });
    editing.value
        ? form.transform(transform).put(route('projects.scrap.generations.update', [props.project.id, props.generation.id]))
        : form.transform(transform).post(route('projects.scrap.generations.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="scrap" :title="editing ? generation.generation_number : 'New scrap generation'">
        <ScrapNav active="generations" scope="project" :project-id="project.id" />
        <form class="space-y-4 pb-20" @submit.prevent="submit">
            <AppCard :title="editing ? generation.generation_number : 'New scrap generation'" subtitle="Draft until submitted. Approval posts scrap into project stock.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormInput v-model="form.generation_date" type="date" label="Generation date" required :max="today" :error="form.errors.generation_date" />
                    <FormSelect v-model="form.source_type" label="Source" required :options="options.sourceTypes" :error="form.errors.source_type" />
                    <FormInput v-model="form.location" label="Location" maxlength="150" :error="form.errors.location" />
                    <SearchSelect v-model="form.department_id" label="Department" :options="options.departments" :error="form.errors.department_id" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" class="sm:col-span-2 lg:col-span-4" :error="form.errors.remarks" />
                </div>
            </AppCard>
            <AppCard title="Lines" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.items" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
                        <FormSelect v-model="line.scrap_category_id" label="Category" :options="options.categories" :error="form.errors[`items.${index}.scrap_category_id`]" />
                        <SearchSelect v-model="line.scrap_type_id" label="Scrap type" required :options="filteredTypes(line)" :error="form.errors[`items.${index}.scrap_type_id`]" @update:model-value="onTypeChange(line)" />
                        <FormInput v-model="line.description" label="Description" maxlength="300" class="lg:col-span-2" :error="form.errors[`items.${index}.description`]" />
                        <DecimalInput v-model="line.quantity" label="Qty" required :decimals="4" :error="form.errors[`items.${index}.quantity`]" />
                        <FormInput :model-value="line.unit" label="Unit" readonly />
                        <DecimalInput v-model="line.weight" label="Weight" :decimals="4" :error="form.errors[`items.${index}.weight`]" />
                        <FormInput v-model="line.condition" label="Condition" maxlength="30" :error="form.errors[`items.${index}.condition`]" />
                        <FormInput v-model="line.remarks" label="Remarks" maxlength="500" class="lg:col-span-2" :error="form.errors[`items.${index}.remarks`]" />
                        <button v-if="form.items.length > 1" type="button" class="text-xs text-red-600" @click="form.items.splice(index, 1)">Remove</button>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="ghost" icon="plus" @click="form.items.push(blankLine())">Add line</AppButton>
                </div>
            </AppCard>
            <div class="flex justify-end gap-2">
                <Link :href="editing ? route('projects.scrap.generations.show', [project.id, generation.id]) : route('projects.scrap.generations.index', project.id)" class="inline-flex h-10 items-center rounded-lg border border-line px-4 text-sm">Cancel</Link>
                <AppButton type="submit" :loading="form.processing">Save draft</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
