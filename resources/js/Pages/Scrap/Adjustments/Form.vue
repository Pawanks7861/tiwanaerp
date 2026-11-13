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
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    adjustment: { type: Object, default: null },
    options: { type: Object, required: true },
    balances: { type: Object, required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.adjustment);
let tempId = 0;
const blank = () => ({ _key: `n${++tempId}`, scrap_category_id: null, scrap_type_id: null, quantity: null, unit_id: null, unit: '', remarks: '' });

const form = useForm({
    adjustment_date: props.adjustment?.adjustment_date ?? props.today,
    direction: props.adjustment?.direction ?? 'in',
    reason: props.adjustment?.reason ?? '',
    remarks: props.adjustment?.remarks ?? '',
    items: props.adjustment?.items?.length
        ? props.adjustment.items.map((i, n) => {
              const type = props.options.scrapTypes.find((t) => t.value === i.scrap_type_id);

              return { _key: `i${n}`, scrap_category_id: type?.scrap_category_id ?? null, ...i, unit: type?.unit ?? '' };
          })
        : [blank()],
});

const filteredTypes = (line) => (line.scrap_category_id ? props.options.scrapTypes.filter((t) => t.scrap_category_id === line.scrap_category_id) : props.options.scrapTypes);

const available = (line) => {
    if (!line.scrap_type_id || !line.unit_id) {
        return null;
    }

    return props.balances[`${line.scrap_type_id}|${line.unit_id}`] ?? '0';
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
        remarks: d.remarks || null,
        items: d.items.map(({ _key, scrap_category_id, unit, ...line }) => ({ ...line, remarks: line.remarks || null })),
    });
    editing.value
        ? form.transform(transform).put(route('projects.scrap.adjustments.update', [props.project.id, props.adjustment.id]))
        : form.transform(transform).post(route('projects.scrap.adjustments.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="scrap" :title="editing ? adjustment.adjustment_number : 'New scrap adjustment'">
        <ScrapNav active="adjustments" scope="project" :project-id="project.id" />
        <form class="space-y-4 pb-20" @submit.prevent="submit">
            <AppCard title="Header">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormInput v-model="form.adjustment_date" type="date" label="Date" required :max="today" :error="form.errors.adjustment_date" />
                    <FormSelect v-model="form.direction" label="Direction" required :options="[{ value: 'in', label: 'In (add)' }, { value: 'out', label: 'Out (remove)' }]" :error="form.errors.direction" />
                    <FormInput v-model="form.reason" label="Reason" required maxlength="500" class="sm:col-span-2" :error="form.errors.reason" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" class="sm:col-span-2 lg:col-span-4" :error="form.errors.remarks" />
                </div>
            </AppCard>
            <AppCard title="Lines" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.items" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
                        <FormSelect v-model="line.scrap_category_id" label="Category" :options="options.categories" />
                        <SearchSelect v-model="line.scrap_type_id" label="Scrap type" required :options="filteredTypes(line)" :error="form.errors[`items.${index}.scrap_type_id`]" @update:model-value="onTypeChange(line)" />
                        <DecimalInput v-model="line.quantity" label="Qty" required :decimals="4" :error="form.errors[`items.${index}.quantity`]" />
                        <FormInput :model-value="line.unit" label="Unit" readonly />
                        <p v-if="form.direction === 'out' && line.scrap_type_id" class="text-xs text-slate-500 lg:col-span-2">Available: {{ available(line) }}</p>
                        <FormInput v-model="line.remarks" label="Remarks" maxlength="500" class="lg:col-span-2" />
                        <button v-if="form.items.length > 1" type="button" class="text-xs text-red-600" @click="form.items.splice(index, 1)">Remove</button>
                    </li>
                </ol>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="ghost" icon="plus" @click="form.items.push(blank())">Add line</AppButton></div>
            </AppCard>
            <div class="flex justify-end gap-2">
                <Link :href="editing ? route('projects.scrap.adjustments.show', [project.id, adjustment.id]) : route('projects.scrap.adjustments.index', project.id)" class="inline-flex h-10 items-center rounded-lg border border-line px-4 text-sm">Cancel</Link>
                <AppButton type="submit" :loading="form.processing">Save</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
