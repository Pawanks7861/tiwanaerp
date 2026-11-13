<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    connection: { type: Object, required: true },
    meters: { type: Array, default: () => [] },
    tariffs: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const meterModal = ref(false);
const editingMeter = ref(null);
const meterForm = useForm({
    meter_number: '',
    meter_type: '',
    make: '',
    model: '',
    multiplication_factor: '1',
    installed_at: null,
    removed_at: null,
    is_active: true,
    notes: '',
});

function openMeter(meter = null) {
    editingMeter.value = meter;
    meterForm.clearErrors();
    if (meter) {
        meterForm.meter_number = meter.meter_number;
        meterForm.meter_type = meter.meter_type ?? '';
        meterForm.make = meter.make ?? '';
        meterForm.model = meter.model ?? '';
        meterForm.multiplication_factor = meter.multiplication_factor ?? '1';
        meterForm.installed_at = meter.installed_at ?? null;
        meterForm.removed_at = meter.removed_at ?? null;
        meterForm.is_active = meter.is_active ?? true;
        meterForm.notes = meter.notes ?? '';
    } else {
        meterForm.reset();
        meterForm.multiplication_factor = props.connection.default_multiplication_factor ?? '1';
    }
    meterModal.value = true;
}

function saveMeter() {
    const transform = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
    const opts = { preserveScroll: true, onSuccess: () => (meterModal.value = false) };
    editingMeter.value
        ? meterForm.transform(transform).put(route('utilities.electricity.meters.update', editingMeter.value.id), opts)
        : meterForm.transform(transform).post(route('utilities.electricity.meters.store', props.connection.id), opts);
}

const deleting = ref(false);
const processing = ref(false);
function destroy() {
    processing.value = true;
    router.delete(route('utilities.electricity.connections.destroy', props.connection.id), { onFinish: () => ((processing.value = false), (deleting.value = false)) });
}
</script>

<template>
    <AppLayout :title="connection.connection_number">
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('utilities.electricity.connections.index')" class="text-xs font-medium text-slate-500 hover:text-slate-700">Connections</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h1 class="text-lg font-semibold text-slate-900">{{ connection.connection_number }}</h1>
                            <StatusBadge :status="connection.is_active ? 'active' : 'inactive'" :label="connection.is_active ? 'Active' : 'Inactive'" />
                        </div>
                        <p v-if="connection.site" class="mt-1 text-sm text-slate-800">{{ connection.site.name }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div v-if="connection.consumer_number"><dt class="text-slate-500">Consumer</dt><dd class="font-mono">{{ connection.consumer_number }}</dd></div>
                            <div v-if="connection.discom_name"><dt class="text-slate-500">DISCOM</dt><dd>{{ connection.discom_name }}</dd></div>
                            <div v-if="connection.tariff_category"><dt class="text-slate-500">Category</dt><dd>{{ connection.tariff_category }}</dd></div>
                            <div v-if="connection.contract_demand"><dt class="text-slate-500">Contract demand</dt><dd class="tabular">{{ formatNumber(connection.contract_demand, 2) }} {{ connection.demand_unit }}</dd></div>
                            <div v-if="connection.sanctioned_demand"><dt class="text-slate-500">Sanctioned</dt><dd class="tabular">{{ formatNumber(connection.sanctioned_demand, 2) }} {{ connection.demand_unit }}</dd></div>
                            <div><dt class="text-slate-500">TOD</dt><dd>{{ connection.tod_enabled ? 'Yes' : 'No' }}</dd></div>
                            <div><dt class="text-slate-500">Default MF</dt><dd class="tabular">{{ connection.default_multiplication_factor ?? '1' }}</dd></div>
                        </dl>
                        <p v-if="connection.notes" class="mt-2 text-sm whitespace-pre-line text-slate-700">{{ connection.notes }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.create_tariff" size="sm" icon="plus" :href="route('utilities.electricity.tariffs.create', { electricity_connection_id: connection.id })">Tariff</AppButton>
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('utilities.electricity.connections.edit', connection.id)">Edit</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete connection" @click="deleting = true" />
                    </div>
                </div>
            </AppCard>

            <AppCard title="Meters" :padded="false">
                <div v-if="can.create_meter" class="flex justify-end border-b border-line px-4 py-2">
                    <AppButton size="sm" icon="plus" @click="openMeter()">Add meter</AppButton>
                </div>
                <table v-if="meters.length" class="min-w-full text-sm">
                    <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Meter</th>
                            <th class="hidden px-4 py-2 font-medium sm:table-cell">MF</th>
                            <th class="hidden px-4 py-2 font-medium md:table-cell">Installed</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="m in meters" :key="m.id">
                            <td class="px-4 py-2"><span class="font-mono text-xs font-medium">{{ m.meter_number }}</span><span v-if="m.meter_type" class="ml-2 text-xs text-slate-500">{{ m.meter_type }}</span></td>
                            <td class="hidden px-4 py-2 tabular sm:table-cell">{{ m.multiplication_factor }}</td>
                            <td class="hidden px-4 py-2 md:table-cell">{{ m.installed_at ? formatDate(m.installed_at) : '—' }}</td>
                            <td class="px-4 py-2"><StatusBadge :status="m.is_active ? 'active' : 'inactive'" :label="m.is_active ? 'Active' : 'Inactive'" /></td>
                            <td class="px-4 py-2 text-right"><AppButton v-if="can.update_meter" size="sm" variant="ghost" @click="openMeter(m)">Edit</AppButton></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-4 py-3 text-sm text-slate-500">No meters registered on this connection.</p>
            </AppCard>

            <AppCard title="Tariffs" :padded="false">
                <ul v-if="tariffs.length" class="divide-y divide-line">
                    <li v-for="t in tariffs" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <div>
                            <Link :href="route('utilities.electricity.tariffs.show', t.id)" class="text-sm font-medium text-brand-700 hover:underline">{{ t.name }}</Link>
                            <p class="text-xs text-slate-500">{{ formatDate(t.effective_from) }}<span v-if="t.effective_to"> – {{ formatDate(t.effective_to) }}</span></p>
                        </div>
                        <StatusBadge :status="t.is_active ? 'active' : 'inactive'" :label="t.is_active ? 'Active' : 'Inactive'" />
                    </li>
                </ul>
                <p v-else class="px-4 py-3 text-sm text-slate-500">No tariffs yet.</p>
            </AppCard>
        </div>

        <AppModal :show="meterModal" :title="editingMeter ? 'Edit meter' : 'Add meter'" @close="meterModal = false">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput v-model="meterForm.meter_number" label="Meter number" required maxlength="80" :error="meterForm.errors.meter_number" />
                <FormInput v-model="meterForm.meter_type" label="Type" maxlength="40" :error="meterForm.errors.meter_type" />
                <FormInput v-model="meterForm.make" label="Make" maxlength="80" :error="meterForm.errors.make" />
                <FormInput v-model="meterForm.model" label="Model" maxlength="80" :error="meterForm.errors.model" />
                <DecimalInput v-model="meterForm.multiplication_factor" label="MF" :error="meterForm.errors.multiplication_factor" />
                <FormInput v-model="meterForm.installed_at" type="date" label="Installed" :error="meterForm.errors.installed_at" />
                <FormSwitch v-model="meterForm.is_active" label="Active" :error="meterForm.errors.is_active" />
                <FormInput v-model="meterForm.notes" label="Notes" multiline :rows="2" class="sm:col-span-2" :error="meterForm.errors.notes" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="meterModal = false">Cancel</AppButton>
                <AppButton :loading="meterForm.processing" @click="saveMeter">Save</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog :show="deleting" title="Delete this connection?" message="Meters and tariffs must be removed or reassigned first if the server blocks deletion." confirm-label="Delete" danger :processing="processing" @close="deleting = false" @confirm="destroy" />
    </AppLayout>
</template>
