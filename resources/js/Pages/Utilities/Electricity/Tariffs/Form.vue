<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    tariff: { type: Object, default: null },
    connections: { type: Array, required: true },
    component_types: { type: Array, default: () => [] },
    calculation_bases: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
});

const editing = computed(() => !!props.tariff);
const t = props.tariff;

const blankComponent = () => ({
    id: null,
    component_code: '',
    component_name: '',
    component_type: 'energy',
    calculation_basis: 'kwh',
    rate: '0',
    percentage: '0',
    unit: '',
    sort_order: 0,
    is_rebate: false,
    is_tax: false,
    is_penalty: false,
    is_active: true,
});

const form = useForm({
    electricity_connection_id: t?.electricity_connection_id ?? null,
    name: t?.name ?? '',
    effective_from: t?.effective_from ?? '',
    effective_to: t?.effective_to ?? null,
    tariff_category: t?.tariff_category ?? '',
    state_code: t?.state_code ?? null,
    description: t?.description ?? '',
    is_active: t?.is_active ?? true,
    components: (t?.components?.length ? t.components : [blankComponent()]).map((c) => ({ ...blankComponent(), ...c })),
});

function addComponent() {
    form.components.push({ ...blankComponent(), sort_order: form.components.length });
}

function removeComponent(index) {
    if (form.components.length > 1) {
        form.components.splice(index, 1);
    }
}

function submit() {
    const transform = (d) => ({
        ...Object.fromEntries(Object.entries(d).filter(([k]) => k !== 'components').map(([k, v]) => [k, v === '' ? null : v])),
        components: d.components.map((c, i) => ({ ...c, sort_order: c.sort_order ?? i })),
    });
    editing.value
        ? form.transform(transform).put(route('utilities.electricity.tariffs.update', props.tariff.id))
        : form.transform(transform).post(route('utilities.electricity.tariffs.store'));
}
</script>

<template>
    <AppLayout :title="editing ? tariff.name : 'New tariff'">
        <PageHeader
            :title="editing ? `Edit ${tariff.name}` : 'New tariff'"
            :back="editing ? route('utilities.electricity.tariffs.show', tariff.id) : route('utilities.electricity.tariffs.index')"
        />
        <form class="space-y-4 pb-24 md:pb-0" @submit.prevent="submit">
            <AppCard title="Tariff header">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <SearchSelect v-model="form.electricity_connection_id" label="Connection" required :options="connections" :error="form.errors.electricity_connection_id" />
                    <FormInput v-model="form.name" label="Name" required maxlength="160" :error="form.errors.name" class="sm:col-span-2" />
                    <FormInput v-model="form.effective_from" type="date" label="Effective from" required :error="form.errors.effective_from" />
                    <FormInput v-model="form.effective_to" type="date" label="Effective to" :error="form.errors.effective_to" />
                    <FormInput v-model="form.tariff_category" label="Category" maxlength="80" :error="form.errors.tariff_category" />
                    <SearchSelect v-model="form.state_code" label="State" :options="states" :error="form.errors.state_code" />
                    <FormInput v-model="form.description" label="Description" multiline :rows="2" class="sm:col-span-2 lg:col-span-3" :error="form.errors.description" />
                    <FormSwitch v-model="form.is_active" label="Active" :error="form.errors.is_active" />
                </div>
            </AppCard>

            <AppCard title="Bill components" subtitle="Define charges as typed lines—rates and percentages, not raw JSON.">
                <div class="space-y-4">
                    <section
                        v-for="(comp, index) in form.components"
                        :key="index"
                        class="rounded-lg border border-line p-4"
                    >
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-slate-800">Component {{ index + 1 }}</h3>
                            <AppButton v-if="form.components.length > 1" type="button" size="sm" variant="ghost" @click="removeComponent(index)">Remove</AppButton>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <FormInput v-model="comp.component_code" label="Code" required maxlength="60" :error="form.errors[`components.${index}.component_code`]" />
                            <FormInput v-model="comp.component_name" label="Name" required maxlength="160" :error="form.errors[`components.${index}.component_name`]" />
                            <FormSelect v-model="comp.component_type" label="Type" required :options="component_types" :error="form.errors[`components.${index}.component_type`]" />
                            <FormSelect v-model="comp.calculation_basis" label="Basis" required :options="calculation_bases" :error="form.errors[`components.${index}.calculation_basis`]" />
                            <DecimalInput v-model="comp.rate" label="Rate" :error="form.errors[`components.${index}.rate`]" />
                            <DecimalInput v-model="comp.percentage" label="Percentage" suffix="%" :error="form.errors[`components.${index}.percentage`]" />
                            <FormInput v-model="comp.unit" label="Unit label" maxlength="20" placeholder="kWh, kVA…" :error="form.errors[`components.${index}.unit`]" />
                            <div class="flex flex-wrap gap-4 sm:col-span-2 lg:col-span-3">
                                <FormSwitch v-model="comp.is_rebate" label="Rebate" />
                                <FormSwitch v-model="comp.is_penalty" label="Penalty" />
                                <FormSwitch v-model="comp.is_tax" label="Tax / duty" />
                                <FormSwitch v-model="comp.is_active" label="Active" />
                            </div>
                        </div>
                    </section>
                    <AppButton type="button" variant="secondary" size="sm" icon="plus" @click="addComponent">Add component</AppButton>
                </div>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('utilities.electricity.tariffs.show', tariff.id) : route('utilities.electricity.tariffs.index')"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save tariff' : 'Create tariff' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
