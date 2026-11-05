<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    variation: { type: Object, default: null },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const isEdit = computed(() => !!props.variation?.id);

const form = useForm({
    title: props.variation?.title ?? '',
    description: props.variation?.description ?? '',
    change_order_type: props.variation?.change_order_type ?? 'quantity_increase',
    source_type: props.variation?.source_type ?? 'quantity_correction',
    source_reference: props.variation?.source_reference ?? '',
    drawing_revision_id: props.variation?.drawing_revision_id ?? null,
    client_instruction_reference: props.variation?.client_instruction_reference ?? '',
    client_instruction_date: props.variation?.client_instruction_date ?? '',
    reason: props.variation?.reason ?? '',
    items: (props.variation?.items ?? []).map((i) => ({
        change_type: i.change_type,
        original_boq_item_id: i.original_boq_item_id,
        variation_item_uid: i.variation_item_uid,
        name: i.name,
        item_code: i.item_code,
        description: i.description,
        hsn_sac: i.hsn_sac,
        unit_id: i.unit_id,
        boq_section_id: i.boq_section_id,
        quantity_source: i.quantity_source ?? 'direct',
        quantity_change: Math.abs(Number(i.quantity_change || 0)),
        proposed_selling_rate: i.proposed_selling_rate,
        proposed_cost_rate: i.proposed_cost_rate,
        gst_rate: i.gst_rate ?? '0',
        notes: i.notes,
        measurement_payload: i.measurement_payload,
    })),
});

if (!form.items.length) {
    form.items.push(blankItem('quantity_increase'));
}

function blankItem(type = 'quantity_increase') {
    return {
        change_type: type,
        original_boq_item_id: null,
        variation_item_uid: null,
        name: '',
        item_code: '',
        description: '',
        hsn_sac: '',
        unit_id: null,
        boq_section_id: null,
        quantity_source: 'direct',
        quantity_change: '',
        proposed_selling_rate: '',
        proposed_cost_rate: '',
        gst_rate: '0',
        notes: '',
        measurement_payload: null,
    };
}

function addItem() {
    form.items.push(blankItem(form.change_order_type));
}

function removeItem(idx) {
    form.items.splice(idx, 1);
}

function onOriginalPick(idx) {
    const item = form.items[idx];
    const opt = props.options.boq_items.find((o) => o.value === item.original_boq_item_id);
    if (!opt) return;
    item.name = opt.label;
    item.unit_id = opt.unit_id;
    item.boq_section_id = opt.boq_section_id;
    item.hsn_sac = opt.hsn_sac;
    item.proposed_selling_rate = opt.client_rate;
    item.proposed_cost_rate = opt.cost_rate;
}

function onChangeType(idx) {
    const item = form.items[idx];
    if (item.change_type === 'new_item') {
        item.original_boq_item_id = null;
        item.name = item.name || '';
    }
}

function submit() {
    const payload = {
        ...form.data(),
        items: form.items.map((i) => ({
            ...i,
            quantity_change: i.change_type === 'quantity_decrease' || i.change_type === 'omitted_item'
                ? -Math.abs(Number(i.quantity_change || 0))
                : Math.abs(Number(i.quantity_change || 0)),
        })),
    };

    if (isEdit.value) {
        form.transform(() => payload).put(route('projects.variations.update', [props.project.id, props.variation.id]));
    } else {
        form.transform(() => payload).post(route('projects.variations.store', props.project.id));
    }
}
</script>

<template>
    <ProjectLayout :project="project" title="Variation" active="variations">
        <div class="mb-4">
            <Link :href="route('projects.variations.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">
                ← Variation Register
            </Link>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">
                {{ isEdit ? `Edit ${variation.variation_number}` : 'New Variation' }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">BOQ {{ options.boq.boq_number }} v{{ options.boq.version }}. Original approved lines are never overwritten.</p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Header">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormInput v-model="form.title" label="Title" required />
                    <FormSelect v-model="form.change_order_type" label="Change order type" :options="options.change_types" />
                    <FormSelect v-model="form.source_type" label="Source" :options="options.source_types" />
                    <FormInput v-model="form.source_reference" label="Source / client reference" />
                    <FormInput v-model="form.client_instruction_reference" label="Client instruction no." />
                    <FormInput v-model="form.client_instruction_date" label="Client instruction date" type="date" />
                    <div class="sm:col-span-2">
                        <FormInput v-model="form.reason" label="Reason" required multiline />
                    </div>
                    <div class="sm:col-span-2">
                        <FormInput v-model="form.description" label="Description" multiline />
                    </div>
                </div>
            </AppCard>

            <AppCard title="Items">
                <div class="mb-3 flex justify-end">
                    <AppButton type="button" variant="secondary" @click="addItem">Add line</AppButton>
                </div>
                <div v-for="(item, idx) in form.items" :key="idx" class="mb-4 rounded-xl border border-line p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase text-slate-500">Line {{ idx + 1 }}</p>
                        <button v-if="form.items.length > 1" type="button" class="text-xs text-rose-600" @click="removeItem(idx)">Remove</button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <FormSelect
                            v-model="item.change_type"
                            label="Change type"
                            :options="options.change_types"
                            @update:model-value="onChangeType(idx)"
                        />
                        <FormSelect
                            v-if="item.change_type !== 'new_item'"
                            v-model="item.original_boq_item_id"
                            label="Original BOQ item"
                            :options="[{ value: null, label: '— Select —' }, ...options.boq_items]"
                            @update:model-value="onOriginalPick(idx)"
                        />
                        <FormInput v-model="item.name" label="Description / name" />
                        <FormInput v-model="item.item_code" label="Item code" />
                        <FormSelect
                            v-if="item.change_type === 'new_item'"
                            v-model="item.unit_id"
                            label="Unit"
                            :options="[{ value: null, label: '— Select —' }, ...options.units]"
                        />
                        <FormSelect
                            v-if="item.change_type === 'new_item'"
                            v-model="item.boq_section_id"
                            label="BOQ head"
                            :options="[{ value: null, label: '— Auto Variation head —' }, ...options.sections]"
                        />
                        <FormInput
                            v-if="item.change_type !== 'rate_change' && item.change_type !== 'omitted_item'"
                            v-model="item.quantity_change"
                            label="Quantity change"
                            type="number"
                            step="0.0001"
                        />
                        <FormInput
                            v-if="item.change_type === 'new_item' || item.change_type === 'rate_change' || can.view_costs"
                            v-model="item.proposed_selling_rate"
                            label="Proposed selling rate"
                            type="number"
                            step="0.0001"
                        />
                        <FormInput
                            v-if="can.view_costs && (item.change_type === 'new_item' || item.change_type === 'rate_change')"
                            v-model="item.proposed_cost_rate"
                            label="Proposed cost rate"
                            type="number"
                            step="0.0001"
                        />
                        <FormInput v-model="item.gst_rate" label="GST %" type="number" step="0.0001" />
                        <div class="sm:col-span-2 lg:col-span-3">
                            <FormInput v-model="item.notes" label="Notes" />
                        </div>
                    </div>
                </div>
            </AppCard>

            <div class="flex gap-2">
                <AppButton type="submit" :loading="form.processing">Save draft</AppButton>
                <Link :href="route('projects.variations.index', project.id)" class="inline-flex items-center rounded-lg border border-line bg-white px-3 py-2 text-sm">
                    Cancel
                </Link>
            </div>
        </form>
    </ProjectLayout>
</template>
