<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    materialRequest: { type: Object, default: null },
    materials: { type: Array, required: true },
    units: { type: Array, required: true },
    sites: { type: Array, required: true },
    boqItems: { type: Array, required: true },
    tasks: { type: Array, required: true },
    priorities: { type: Array, required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.materialRequest);
let tempId = 0;
const blankLine = () => ({ _key: `n${++tempId}`, id: null, material_id: null, unit_id: null, quantity: null, boq_item_id: null, task_id: null, remarks: '' });

const form = useForm({
    site_id: props.materialRequest?.site_id ?? null,
    request_date: props.materialRequest?.request_date ?? props.today,
    required_date: props.materialRequest?.required_date ?? null,
    priority: props.materialRequest?.priority ?? 'medium',
    remarks: props.materialRequest?.remarks ?? '',
    items: props.materialRequest?.items?.length
        ? props.materialRequest.items.map((i) => ({ _key: `i${i.id}`, ...i, remarks: i.remarks ?? '' }))
        : [blankLine()],
});

function pickMaterial(line, id) {
    line.material_id = id;
    const material = props.materials.find((m) => m.value === id);
    if (material?.unit_id) {
        line.unit_id = material.unit_id;
    }
}
function pickBoqItem(line, id) {
    line.boq_item_id = id;
    const item = props.boqItems.find((b) => b.value === id);
    if (item?.unit_id && !line.unit_id) {
        line.unit_id = item.unit_id;
    }
}
const removeLine = (index) => form.items.splice(index, 1);
const lineError = (index, field) => form.errors[`items.${index}.${field}`];
const generalErrors = computed(() => Object.entries(form.errors).filter(([k]) => k === 'items' || k === 'material_request').map(([, v]) => v));

function submit() {
    const transform = (data) => ({
        ...data,
        remarks: data.remarks || null,
        items: data.items.map(({ _key, ...line }) => ({ ...line, remarks: line.remarks || null })),
    });
    if (editing.value) {
        form.transform(transform).put(route('projects.material-requests.update', [props.project.id, props.materialRequest.id]));
    } else {
        form.transform(transform).post(route('projects.material-requests.store', props.project.id));
    }
}
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="editing ? materialRequest.request_number : 'New material request'">
        <ProcurementNav :project-id="project.id" active="material-requests" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :title="editing ? `Edit ${materialRequest.request_number}` : 'New material request'" subtitle="The request number is assigned automatically. Saved requests stay in draft until you submit them.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormSelect v-model="form.site_id" label="Site" placeholder="— Whole project —" :options="sites" :error="form.errors.site_id" />
                    <FormInput v-model="form.request_date" type="date" label="Request date" required :error="form.errors.request_date" />
                    <FormInput v-model="form.required_date" type="date" label="Required by" :min="form.request_date" :error="form.errors.required_date" />
                    <FormSelect v-model="form.priority" label="Priority" required :options="priorities" :error="form.errors.priority" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" class="sm:col-span-2 lg:col-span-4" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard title="Items" :subtitle="`${form.items.length} line(s)`" :padded="false">
                <template #actions>
                    <AppButton size="sm" variant="secondary" icon="plus" @click="form.items.push(blankLine())">Add line</AppButton>
                </template>
                <div v-if="generalErrors.length" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                    <p v-for="(e, i) in generalErrors" :key="i">{{ e }}</p>
                </div>
                <div v-if="!boqItems.length" class="border-b border-line bg-slate-50 px-4 py-2 text-xs text-slate-600">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />This project has no approved BOQ yet, so lines cannot be linked to BOQ items.
                </div>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.items" :key="line._key" class="p-4">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-500 uppercase">Line {{ index + 1 }}</span>
                            <button v-if="form.items.length > 1" type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove line ${index + 1}`" @click="removeLine(index)">
                                <Icon name="trash" :size="16" />
                            </button>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-6 lg:grid-cols-12">
                            <SearchSelect
                                :model-value="line.material_id"
                                label="Material"
                                required
                                :options="materials"
                                class="sm:col-span-6 lg:col-span-4"
                                :error="lineError(index, 'material_id')"
                                @update:model-value="(id) => pickMaterial(line, id)"
                            />
                            <DecimalInput v-model="line.quantity" label="Quantity" required :decimals="4" class="sm:col-span-3 lg:col-span-2" :error="lineError(index, 'quantity')" />
                            <FormSelect v-model="line.unit_id" label="Unit" required :options="units" class="sm:col-span-3 lg:col-span-2" :error="lineError(index, 'unit_id')" />
                            <SearchSelect
                                :model-value="line.boq_item_id"
                                label="BOQ item"
                                :options="boqItems"
                                :disabled="!boqItems.length"
                                class="sm:col-span-6 lg:col-span-4"
                                :error="lineError(index, 'boq_item_id')"
                                @update:model-value="(id) => pickBoqItem(line, id)"
                            />
                            <SearchSelect v-model="line.task_id" label="Task" :options="tasks" class="sm:col-span-3 lg:col-span-4" :error="lineError(index, 'task_id')" />
                            <FormInput v-model="line.remarks" label="Remarks / specification" maxlength="500" class="sm:col-span-3 lg:col-span-8" :error="lineError(index, 'remarks')" />
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="ghost" icon="plus" @click="form.items.push(blankLine())">Add another line</AppButton>
                </div>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.material-requests.show', [project.id, materialRequest.id]) : route('projects.material-requests.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
