<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    pass: { type: Object, default: null },
    items: { type: Array, default: () => [] },
    weighbridge: { type: Object, default: null },
    options: { type: Object, required: true },
    scrapBalances: { type: Object, default: () => ({}) },
});

const editing = computed(() => !!props.pass);
let temp = 0;
const blank = () => ({
    _key: `n${++temp}`,
    item_type: 'material',
    material_id: null,
    equipment_id: null,
    description: '',
    quantity: null,
    unit_id: null,
    weight: null,
    scrap_category_id: null,
    scrap_type_id: null,
    rate: null,
    destination: '',
    remarks: '',
});
const form = useForm({
    site_id: props.pass?.site_id ?? null,
    direction: props.pass?.direction ?? 'out',
    returnable: props.pass?.returnable ?? false,
    passed_at: props.pass?.passed_at ?? new Date().toISOString().slice(0, 16),
    expected_return_date: props.pass?.expected_return_date ?? null,
    party_name: props.pass?.party_name ?? '',
    vendor_id: props.pass?.vendor_id ?? null,
    vehicle_no: props.pass?.vehicle_no ?? '',
    driver_name: props.pass?.driver_name ?? '',
    driver_mobile: props.pass?.driver_mobile ?? '',
    purpose: props.pass?.purpose ?? '',
    reference_type: props.pass?.reference_type ?? '',
    reference_no: props.pass?.reference_no ?? '',
    remarks: props.pass?.remarks ?? '',
    items: props.items.length ? props.items.map((i) => ({ _key: `i${++temp}`, ...i, scrap_category_id: i.scrap_category_id ?? null, scrap_type_id: i.scrap_type_id ?? null, destination: i.destination ?? '', remarks: i.remarks ?? '' })) : [blank()],
    weighbridge: {
        name: props.weighbridge?.name ?? '',
        slip_no: props.weighbridge?.slip_no ?? '',
        weighed_at: props.weighbridge?.weighed_at ?? '',
        vehicle_no: props.weighbridge?.vehicle_no ?? '',
        gross_weight: props.weighbridge?.gross_weight ?? null,
        tare_weight: props.weighbridge?.tare_weight ?? null,
        net_weight: props.weighbridge?.net_weight ?? null,
        weight_unit: props.weighbridge?.weight_unit ?? 'kg',
        manual_reason: props.weighbridge?.manual_reason ?? '',
        operator_name: props.weighbridge?.operator_name ?? '',
        remarks: props.weighbridge?.remarks ?? '',
    },
});

function submit() {
    const transform = (d) => ({
        ...d,
        returnable: !!d.returnable,
        party_name: d.party_name || null,
        vehicle_no: d.vehicle_no || null,
        driver_name: d.driver_name || null,
        driver_mobile: d.driver_mobile || null,
        reference_type: d.reference_type || null,
        reference_no: d.reference_no || null,
        remarks: d.remarks || null,
        items: d.items.map(({ _key, scrap_category_id, ...line }) => ({ ...line, scrap_type_id: line.scrap_type_id || null, destination: line.destination || null, remarks: line.remarks || null, rate: line.rate || null, weight: line.weight || null })),
    });
    const options = { preserveScroll: true };
    editing.value
        ? form.transform(transform).put(route('projects.gate-passes.update', [props.project.id, props.pass.id]), options)
        : form.transform(transform).post(route('projects.gate-passes.store', props.project.id), options);
}

const scrapTypesFor = (line) => {
    const cat = line.scrap_category_id;

    return cat ? props.options.scrapTypes.filter((t) => t.scrap_category_id === cat) : props.options.scrapTypes;
};

const onScrapType = (line) => {
    const type = props.options.scrapTypes.find((t) => t.value === line.scrap_type_id);
    if (type) {
        line.unit_id = type.unit_id;
        line.scrap_category_id = type.scrap_category_id;
        if (!line.description) {
            line.description = type.label;
        }
    }
};

const scrapAvailable = (line) => {
    if (!line.scrap_type_id || !line.unit_id) {
        return null;
    }

    return props.scrapBalances[`${line.scrap_type_id}|${line.unit_id}`] ?? '0';
};
</script>

<template>
    <ProjectLayout :project="project" active="gate" :title="editing ? pass.pass_number : 'New gate pass'">
        <form class="space-y-4 pb-24 md:pb-0" @submit.prevent="submit">
            <AppCard :title="editing ? pass.pass_number : 'New gate pass'" subtitle="A control document for the gate. It does not post stock unless you already have an inventory transaction.">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormSelect v-model="form.direction" label="Direction" required :options="options.directions" :error="form.errors.direction" />
                    <FormSelect v-model="form.site_id" label="Site" placeholder="— Project —" :options="options.sites" :error="form.errors.site_id" />
                    <FormInput v-model="form.passed_at" type="datetime-local" label="Date and time" required :error="form.errors.passed_at" />
                    <label class="flex items-end gap-2 pb-2 text-sm"><input v-model="form.returnable" type="checkbox" /> Returnable</label>
                    <FormInput v-if="form.returnable" v-model="form.expected_return_date" type="date" label="Expected return" required :error="form.errors.expected_return_date" />
                    <FormInput v-model="form.party_name" label="Party" maxlength="150" :error="form.errors.party_name" />
                    <SearchSelect v-model="form.vendor_id" label="Vendor" :options="options.vendors" :error="form.errors.vendor_id" />
                    <FormInput v-model="form.vehicle_no" label="Vehicle no." maxlength="30" :error="form.errors.vehicle_no" />
                    <FormInput v-model="form.driver_name" label="Driver" maxlength="120" :error="form.errors.driver_name" />
                    <FormInput v-model="form.driver_mobile" label="Driver mobile" maxlength="20" :error="form.errors.driver_mobile" />
                    <FormInput v-model="form.purpose" label="Purpose" required maxlength="500" class="sm:col-span-2" :error="form.errors.purpose" />
                    <FormInput v-model="form.reference_no" label="Reference no." maxlength="60" :error="form.errors.reference_no" />
                </div>
            </AppCard>
            <AppCard title="Lines" :padded="false">
                <p v-if="form.errors.items" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items }}</p>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.items" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
                        <FormSelect v-model="line.item_type" label="Type" :options="options.itemTypes" :error="form.errors[`items.${index}.item_type`]" />
                        <SearchSelect v-if="line.item_type === 'equipment'" v-model="line.equipment_id" label="Equipment" :options="options.equipment" :error="form.errors[`items.${index}.equipment_id`]" />
                        <SearchSelect v-else v-model="line.material_id" label="Material" :options="options.materials" :error="form.errors[`items.${index}.material_id`]" />
                        <FormInput v-model="line.description" label="Description" required maxlength="300" class="lg:col-span-2" :error="form.errors[`items.${index}.description`]" />
                        <DecimalInput v-model="line.quantity" label="Qty" required :decimals="4" :error="form.errors[`items.${index}.quantity`]" />
                        <FormSelect v-model="line.unit_id" label="Unit" :options="options.units" :error="form.errors[`items.${index}.unit_id`]" />
                        <DecimalInput v-model="line.weight" label="Weight" :decimals="4" :error="form.errors[`items.${index}.weight`]" />
                        <template v-if="line.item_type === 'scrap'">
                            <FormSelect v-model="line.scrap_category_id" label="Scrap category" :options="options.scrapCategories" :error="form.errors[`items.${index}.scrap_category_id`]" />
                            <SearchSelect v-model="line.scrap_type_id" label="Scrap type" required :options="scrapTypesFor(line)" :error="form.errors[`items.${index}.scrap_type_id`]" @update:model-value="onScrapType(line)" />
                            <p v-if="line.scrap_type_id" class="text-xs text-slate-500">Available: {{ scrapAvailable(line) ?? '—' }}</p>
                        </template>
                        <FormInput v-if="line.item_type === 'steel' || line.item_type === 'scrap'" v-model="line.destination" label="Destination" :error="form.errors[`items.${index}.destination`]" />
                        <button v-if="form.items.length > 1" type="button" class="text-xs text-red-600" @click="form.items.splice(index, 1)">Remove</button>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="ghost" icon="plus" @click="form.items.push(blank())">Add line</AppButton>
                </div>
            </AppCard>
            <AppCard title="Truck scale / weighbridge" subtitle="Optional for scrap, steel and bulk loads.">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormInput v-model="form.weighbridge.name" label="Weighbridge" :error="form.errors['weighbridge.name']" />
                    <FormInput v-model="form.weighbridge.slip_no" label="Slip no." :error="form.errors['weighbridge.slip_no']" />
                    <FormInput v-model="form.weighbridge.gross_weight" label="Gross" :error="form.errors['weighbridge.gross_weight']" />
                    <FormInput v-model="form.weighbridge.tare_weight" label="Tare" :error="form.errors['weighbridge.tare_weight']" />
                    <FormInput v-model="form.weighbridge.net_weight" label="Net (if no gross/tare)" :error="form.errors['weighbridge.net_weight']" />
                    <FormInput v-model="form.weighbridge.manual_reason" label="Net source" class="sm:col-span-2" :error="form.errors['weighbridge.manual_reason']" />
                </div>
                <p class="mt-2 flex items-center gap-1 text-xs text-slate-500"><Icon name="info" :size="14" />Net weight is calculated on the server when gross and tare are both entered.</p>
            </AppCard>
            <div class="flex justify-end gap-2">
                <Link :href="editing ? route('projects.gate-passes.show', [project.id, pass.id]) : route('projects.gate-passes.index', project.id)" class="inline-flex h-10 items-center rounded-lg border border-line px-4 text-sm">Cancel</Link>
                <AppButton type="submit" :loading="form.processing">Save</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
