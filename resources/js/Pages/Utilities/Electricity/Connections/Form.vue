<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    connection: { type: Object, default: null },
    sites: { type: Array, required: true },
    vendors: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
});

const editing = computed(() => !!props.connection);
const c = props.connection;
const form = useForm({
    utility_site_id: c?.utility_site_id ?? null,
    vendor_id: c?.vendor_id ?? null,
    connection_number: c?.connection_number ?? '',
    consumer_number: c?.consumer_number ?? '',
    discom_name: c?.discom_name ?? '',
    state_code: c?.state_code ?? null,
    tariff_category: c?.tariff_category ?? '',
    voltage_level: c?.voltage_level ?? '',
    sanctioned_demand: c?.sanctioned_demand ?? null,
    contract_demand: c?.contract_demand ?? null,
    demand_unit: c?.demand_unit ?? 'kVA',
    metering_type: c?.metering_type ?? '',
    billing_cycle: c?.billing_cycle ?? '',
    default_multiplication_factor: c?.default_multiplication_factor ?? '1',
    low_pf_threshold: c?.low_pf_threshold ?? null,
    tod_enabled: c?.tod_enabled ?? false,
    kwh_enabled: c?.kwh_enabled ?? true,
    kvah_enabled: c?.kvah_enabled ?? true,
    is_active: c?.is_active ?? true,
    notes: c?.notes ?? '',
});

function submit() {
    const transform = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
    editing.value
        ? form.transform(transform).put(route('utilities.electricity.connections.update', props.connection.id))
        : form.transform(transform).post(route('utilities.electricity.connections.store'));
}
</script>

<template>
    <AppLayout :title="editing ? connection.connection_number : 'New connection'">
        <PageHeader
            :title="editing ? `Edit ${connection.connection_number}` : 'New connection'"
            :back="editing ? route('utilities.electricity.connections.show', connection.id) : route('utilities.electricity.connections.index')"
        />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard title="Account">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <SearchSelect v-model="form.utility_site_id" label="Utility site" required :options="sites" :error="form.errors.utility_site_id" />
                    <SearchSelect v-model="form.vendor_id" label="Billing vendor" :options="vendors" :error="form.errors.vendor_id" />
                    <FormInput v-model="form.connection_number" label="Connection number" required maxlength="80" :error="form.errors.connection_number" />
                    <FormInput v-model="form.consumer_number" label="Consumer number" maxlength="80" :error="form.errors.consumer_number" />
                    <FormInput v-model="form.discom_name" label="DISCOM" maxlength="160" :error="form.errors.discom_name" />
                    <SearchSelect v-model="form.state_code" label="State" :options="states" :error="form.errors.state_code" />
                    <FormInput v-model="form.tariff_category" label="Tariff category" maxlength="80" :error="form.errors.tariff_category" />
                    <FormInput v-model="form.voltage_level" label="Voltage level" maxlength="40" placeholder="11 kV, 33 kV…" :error="form.errors.voltage_level" />
                    <FormInput v-model="form.billing_cycle" label="Billing cycle" maxlength="40" :error="form.errors.billing_cycle" />
                </div>
            </AppCard>
            <AppCard title="Demand & metering">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <DecimalInput v-model="form.sanctioned_demand" label="Sanctioned demand" :suffix="form.demand_unit" :error="form.errors.sanctioned_demand" />
                    <DecimalInput v-model="form.contract_demand" label="Contract demand" :suffix="form.demand_unit" :error="form.errors.contract_demand" />
                    <FormInput v-model="form.metering_type" label="Metering type" maxlength="40" :error="form.errors.metering_type" />
                    <DecimalInput v-model="form.default_multiplication_factor" label="Default MF" :error="form.errors.default_multiplication_factor" />
                    <DecimalInput v-model="form.low_pf_threshold" label="Low PF threshold" :error="form.errors.low_pf_threshold" />
                    <FormSwitch v-model="form.tod_enabled" label="TOD enabled" :error="form.errors.tod_enabled" />
                    <FormSwitch v-model="form.kwh_enabled" label="kWh metering" :error="form.errors.kwh_enabled" />
                    <FormSwitch v-model="form.kvah_enabled" label="kVAh metering" :error="form.errors.kvah_enabled" />
                    <FormSwitch v-model="form.is_active" label="Active" :error="form.errors.is_active" />
                    <FormInput v-model="form.notes" label="Notes" multiline :rows="3" class="sm:col-span-2 lg:col-span-3" :error="form.errors.notes" />
                </div>
            </AppCard>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('utilities.electricity.connections.show', connection.id) : route('utilities.electricity.connections.index')"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Create connection' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
