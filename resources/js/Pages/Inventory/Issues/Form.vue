<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    issue: { type: Object, default: null },
    options: { type: Object, required: true },
    stock: { type: [Object, Array], required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.issue);
let tempId = 0;
const blankLine = () => ({ _key: `n${++tempId}`, material_id: null, quantity: null, boq_item_id: null, task_id: null, remarks: '' });
const recipientMode = ref(props.issue?.subcontractor_id ? 'subcontractor' : props.issue?.issued_to_name && !props.issue?.issued_to_user_id ? 'name' : 'member');

const form = useForm({
    warehouse_id: props.issue?.warehouse_id ?? props.options.warehouses[0]?.value ?? null,
    issue_date: props.issue?.issue_date ?? props.today,
    issued_to_user_id: props.issue?.issued_to_user_id ?? null,
    subcontractor_id: props.issue?.subcontractor_id ?? null,
    issued_to_name: props.issue?.issued_to_name ?? '',
    purpose: props.issue?.purpose ?? '',
    remarks: props.issue?.remarks ?? '',
    items: props.issue?.items?.length ? props.issue.items.map((i, n) => ({ _key: `i${n}`, ...i, remarks: i.remarks ?? '' })) : [blankLine()],
});

const D = (v) => {
    try {
        return new Decimal(v === null || v === undefined || v === '' ? 0 : String(v));
    } catch {
        return new Decimal(0);
    }
};
const available = (materialId) => props.stock?.[form.warehouse_id]?.[materialId] ?? '0';
const unitOf = (materialId) => props.options.materials.find((m) => m.value === materialId)?.unit ?? '';
const requested = computed(() => {
    const totals = {};
    form.items.forEach((l) => l.material_id && (totals[l.material_id] = D(totals[l.material_id]).plus(D(l.quantity))));

    return totals;
});
const short = (line) => line.material_id && requested.value[line.material_id]?.gt(D(available(line.material_id)));
const lineError = (index, field) => form.errors[`items.${index}.${field}`];
const generalErrors = computed(() => ['items'].map((k) => form.errors[k]).filter(Boolean));

function submit() {
    const transform = (d) => ({
        ...d,
        issued_to_user_id: recipientMode.value === 'member' ? d.issued_to_user_id : null,
        subcontractor_id: recipientMode.value === 'subcontractor' ? d.subcontractor_id : null,
        issued_to_name: recipientMode.value === 'name' ? d.issued_to_name || null : null,
        purpose: d.purpose || null,
        remarks: d.remarks || null,
        items: d.items.map(({ _key, ...l }) => ({ ...l, remarks: l.remarks || null })),
    });
    editing.value
        ? form.transform(transform).put(route('projects.material-issues.update', [props.project.id, props.issue.id]))
        : form.transform(transform).post(route('projects.material-issues.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="inventory" :title="editing ? issue.issue_number : 'New material issue'">
        <InventoryNav :project-id="project.id" active="issues" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :title="editing ? `Edit ${issue.issue_number}` : 'New material issue'" subtitle="Saved as a draft. Stock leaves the store only when the issue is approved.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SearchSelect v-model="form.warehouse_id" label="Issue from store" required :clearable="false" :options="options.warehouses" class="sm:col-span-2" :error="form.errors.warehouse_id" />
                    <FormInput v-model="form.issue_date" type="date" label="Issue date" required :max="today" :error="form.errors.issue_date" />
                    <div>
                        <span class="mb-1 block text-sm font-medium text-slate-700">Issued to <span class="text-red-500">*</span></span>
                        <div class="flex rounded-lg border border-line p-0.5 text-xs">
                            <button v-for="m in [['member', 'Team'], ['subcontractor', 'Subcontractor'], ['name', 'Other']]" :key="m[0]" type="button" class="flex-1 rounded-md px-2 py-1.5 font-medium" :class="recipientMode === m[0] ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'" @click="recipientMode = m[0]">{{ m[1] }}</button>
                        </div>
                    </div>
                    <SearchSelect v-if="recipientMode === 'member'" v-model="form.issued_to_user_id" label="Team member" required :options="options.members" class="sm:col-span-2" :error="form.errors.issued_to_user_id || form.errors.issued_to_name" />
                    <SearchSelect v-else-if="recipientMode === 'subcontractor'" v-model="form.subcontractor_id" label="Subcontractor" required :options="options.subcontractors" class="sm:col-span-2" :error="form.errors.subcontractor_id || form.errors.issued_to_name" />
                    <FormInput v-else v-model="form.issued_to_name" label="Recipient name" required maxlength="150" class="sm:col-span-2" :error="form.errors.issued_to_name" />
                    <FormInput v-model="form.purpose" label="Purpose" maxlength="500" class="sm:col-span-2" :error="form.errors.purpose" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" class="sm:col-span-2 lg:col-span-4" maxlength="2000" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard title="Items" :subtitle="`${form.items.length} line(s) · link lines to the BOQ item and task the material is used for`" :padded="false">
                <template #actions>
                    <AppButton size="sm" variant="secondary" icon="plus" @click="form.items.push(blankLine())">Add line</AppButton>
                </template>
                <div v-if="generalErrors.length" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                    <p v-for="(e, i) in generalErrors" :key="i">{{ e }}</p>
                </div>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.items" :key="line._key" class="p-4">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-500 uppercase">Line {{ index + 1 }}</span>
                            <button v-if="form.items.length > 1" type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove line ${index + 1}`" @click="form.items.splice(index, 1)">
                                <Icon name="trash" :size="16" />
                            </button>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-6 lg:grid-cols-12">
                            <SearchSelect v-model="line.material_id" label="Item" required :options="options.materials" class="sm:col-span-4 lg:col-span-4" :error="lineError(index, 'material_id')" />
                            <DecimalInput
                                v-model="line.quantity"
                                label="Quantity"
                                required
                                :decimals="4"
                                :suffix="unitOf(line.material_id)"
                                class="sm:col-span-2 lg:col-span-2"
                                :help="line.material_id ? `In store: ${formatQty(available(line.material_id))}` : null"
                                :error="lineError(index, 'quantity')"
                            />
                            <SearchSelect v-model="line.boq_item_id" label="BOQ item" :options="options.boq_items" :disabled="!options.boq_items.length" class="sm:col-span-3 lg:col-span-3" :error="lineError(index, 'boq_item_id')" />
                            <SearchSelect v-model="line.task_id" label="Task" :options="options.tasks" class="sm:col-span-3 lg:col-span-3" :error="lineError(index, 'task_id')" />
                            <FormInput v-model="line.remarks" label="Remarks" maxlength="500" class="sm:col-span-6 lg:col-span-12" :error="lineError(index, 'remarks')" />
                        </div>
                        <p v-if="short(line)" class="mt-1.5 flex items-center gap-1 text-xs text-amber-700">
                            <Icon name="warning" :size="14" />More than the {{ formatQty(available(line.material_id)) }} {{ unitOf(line.material_id) }} in this store; the issue cannot be submitted.
                        </p>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="ghost" icon="plus" @click="form.items.push(blankLine())">Add another line</AppButton>
                </div>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.material-issues.show', [project.id, issue.id]) : route('projects.material-issues.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
