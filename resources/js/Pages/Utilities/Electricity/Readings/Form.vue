<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { previewReading } from '@/lib/electricity';
import { formatNumber } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    reading: { type: Object, default: null },
    sites: { type: Array, default: () => [] },
    connections: { type: Array, default: () => [] },
    meters: { type: Array, default: () => [] },
    tod_slots: { type: Array, default: () => [] },
});

const editing = computed(() => !!props.reading);
const r = props.reading;

const form = useForm({
    utility_site_id: r?.utility_site_id ?? null,
    electricity_connection_id: r?.electricity_connection_id ?? null,
    electricity_meter_id: r?.electricity_meter_id ?? null,
    billing_month: r?.billing_month ?? '',
    from_date: r?.from_date ?? '',
    to_date: r?.to_date ?? '',
    opening_kwh: r?.opening_kwh ?? '0',
    closing_kwh: r?.closing_kwh ?? '',
    opening_kvah: r?.opening_kvah ?? '',
    closing_kvah: r?.closing_kvah ?? '',
    mdi_reading: r?.mdi_reading ?? '',
    multiplication_factor: r?.multiplication_factor ?? '1',
    remarks: r?.remarks ?? '',
    tod_readings: r?.tod_readings ?? [],
});

const preview = computed(() =>
    previewReading({
        from_date: form.from_date,
        to_date: form.to_date,
        opening_kwh: form.opening_kwh,
        closing_kwh: form.closing_kwh,
        opening_kvah: form.opening_kvah,
        closing_kvah: form.closing_kvah,
        mdi_reading: form.mdi_reading,
        multiplication_factor: form.multiplication_factor,
    }),
);

const filteredConnections = computed(() => {
    if (!form.utility_site_id) {
        return props.connections;
    }

    return props.connections.filter((c) => c.utility_site_id == form.utility_site_id);
});

const filteredMeters = computed(() => {
    if (!form.electricity_connection_id) {
        return props.meters;
    }

    return props.meters.filter((m) => m.electricity_connection_id == form.electricity_connection_id);
});

const selectedMeter = computed(() => props.meters.find((m) => m.value == form.electricity_meter_id) ?? null);

watch(
    () => form.utility_site_id,
    () => {
        if (!filteredConnections.value.some((c) => c.value == form.electricity_connection_id)) {
            form.electricity_connection_id = null;
            form.electricity_meter_id = null;
        }
    },
);

watch(
    () => form.electricity_connection_id,
    () => {
        if (!filteredMeters.value.some((m) => m.value == form.electricity_meter_id)) {
            form.electricity_meter_id = null;
        }
    },
);

watch(selectedMeter, (meter) => {
    if (!meter || editing.value) {
        return;
    }
    form.multiplication_factor = meter.multiplication_factor ?? form.multiplication_factor;
    form.opening_kwh = meter.opening_kwh ?? '0';
    form.opening_kvah = meter.opening_kvah ?? '';
});

function submit() {
    const transform = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
    editing.value
        ? form.transform(transform).put(route('utilities.electricity.readings.update', props.reading.id))
        : form.transform(transform).post(route('utilities.electricity.readings.store'));
}
</script>

<template>
    <AppLayout :title="editing ? 'Edit reading' : 'New reading'">
        <PageHeader
            :title="editing ? 'Edit meter reading' : 'New meter reading'"
            :back="editing ? route('utilities.electricity.readings.show', reading.id) : route('utilities.electricity.readings.index')"
        />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-2">
                    <AppCard title="Billing period">
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <SearchSelect v-model="form.utility_site_id" label="Site" required :options="sites" :error="form.errors.utility_site_id" />
                            <SearchSelect v-model="form.electricity_connection_id" label="Connection" required :options="filteredConnections" :error="form.errors.electricity_connection_id" />
                            <SearchSelect v-model="form.electricity_meter_id" label="Meter" required :options="filteredMeters" :error="form.errors.electricity_meter_id" />
                            <FormInput v-model="form.billing_month" type="date" label="Billing month" required :error="form.errors.billing_month" />
                            <FormInput v-model="form.from_date" type="date" label="From date" required :error="form.errors.from_date" />
                            <FormInput v-model="form.to_date" type="date" label="To date" required :error="form.errors.to_date" />
                            <DecimalInput v-model="form.multiplication_factor" label="MF" required :error="form.errors.multiplication_factor" />
                        </div>
                    </AppCard>
                    <AppCard title="Registers" subtitle="Enter the current meter reading. Opening is taken from the previous confirmed closing automatically.">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <DecimalInput v-model="form.closing_kwh" label="kWh" required :error="form.errors.closing_kwh" help="Current meter reading (closing)." />
                            <DecimalInput v-model="form.closing_kvah" label="kVAh" :error="form.errors.closing_kvah" help="Optional current kVAh reading." />
                            <DecimalInput v-model="form.mdi_reading" label="MDI (demand)" :error="form.errors.mdi_reading" />
                            <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" class="sm:col-span-2" :error="form.errors.remarks" />
                        </div>
                        <p v-if="form.errors.opening_kwh" class="mt-2 text-sm text-red-600">{{ form.errors.opening_kwh }}</p>
                    </AppCard>
                    <AppCard v-if="tod_slots.length" title="TOD slots" subtitle="Optional time-of-day unit splits.">
                        <p class="text-sm text-slate-500">TOD rows are edited on the show page after save when enabled on the connection.</p>
                    </AppCard>
                </div>
                <AppCard title="Calculated (preview)" subtitle="Saved values are computed on the server when you submit.">
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Opening kWh</dt><dd class="font-medium tabular">{{ form.opening_kwh !== '' && form.opening_kwh != null ? formatNumber(form.opening_kwh, 0, 4) : '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Days</dt><dd class="font-medium tabular">{{ preview.days ?? (reading?.days ?? '—') }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">kWh</dt><dd class="font-medium tabular">{{ preview.kwh_consumption ? formatNumber(preview.kwh_consumption, 0, 4) : (reading?.kwh_consumption ? formatNumber(reading.kwh_consumption, 0, 4) : '—') }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">kVAh</dt><dd class="font-medium tabular">{{ preview.kvah_consumption ? formatNumber(preview.kvah_consumption, 0, 4) : (reading?.kvah_consumption ? formatNumber(reading.kvah_consumption, 0, 4) : '—') }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Billing demand</dt><dd class="font-medium tabular">{{ preview.billing_demand ? formatNumber(preview.billing_demand, 0, 4) : (reading?.billing_demand ? formatNumber(reading.billing_demand, 0, 4) : '—') }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Power factor</dt><dd class="font-medium tabular">{{ preview.power_factor ?? reading?.power_factor ?? '—' }}</dd></div>
                    </dl>
                </AppCard>
            </div>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('utilities.electricity.readings.show', reading.id) : route('utilities.electricity.readings.index')"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save reading' : 'Create reading' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
