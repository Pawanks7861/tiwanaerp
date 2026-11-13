<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    reading: { type: Object, required: true },
    tod_readings: { type: Array, default: () => [] },
    bill: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});
</script>

<template>
    <AppLayout title="Meter reading">
        <AppCard :padded="false" class="mb-4">
            <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <Link :href="route('utilities.electricity.readings.index')" class="text-xs font-medium text-slate-500 hover:text-slate-700">Readings</Link>
                    <div class="mt-0.5 flex flex-wrap items-center gap-2">
                        <h1 class="text-lg font-semibold text-slate-900">{{ formatDate(reading.billing_month) }}</h1>
                        <StatusBadge :status="reading.status" :label="reading.status_label ?? reading.status" />
                    </div>
                    <p class="text-sm text-slate-600">{{ reading.site?.name }} · {{ reading.connection?.connection_number }} · {{ reading.meter?.meter_number }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
                        <div><dt class="text-slate-500">Period</dt><dd>{{ formatDate(reading.from_date) }} – {{ formatDate(reading.to_date) }} ({{ reading.days }} d)</dd></div>
                        <div><dt class="text-slate-500">kWh</dt><dd class="tabular font-medium">{{ formatNumber(reading.kwh_consumption, 0, 2) }}</dd></div>
                        <div><dt class="text-slate-500">kVAh</dt><dd class="tabular">{{ reading.kvah_consumption ? formatNumber(reading.kvah_consumption, 0, 2) : '—' }}</dd></div>
                        <div><dt class="text-slate-500">Demand</dt><dd class="tabular">{{ reading.billing_demand ? formatNumber(reading.billing_demand, 0, 2) : '—' }}</dd></div>
                        <div><dt class="text-slate-500">PF</dt><dd class="tabular">{{ reading.power_factor ?? '—' }}</dd></div>
                        <div v-if="reading.confirmed_at"><dt class="text-slate-500">Confirmed</dt><dd>{{ formatDateTime(reading.confirmed_at) }}</dd></div>
                    </dl>
                </div>
                <div class="flex flex-wrap gap-2">
                    <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('utilities.electricity.readings.edit', reading.id)">Edit</AppButton>
                    <AppButton v-if="bill" size="sm" :href="route('utilities.electricity.bills.show', bill.id)">View bill</AppButton>
                    <AppButton v-else-if="can.create_bill" size="sm" icon="plus" :href="route('utilities.electricity.bills.create', { electricity_meter_reading_id: reading.id })">Create bill</AppButton>
                </div>
            </div>
        </AppCard>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard title="Registers">
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500">Opening kWh</dt><dd class="tabular">{{ formatNumber(reading.opening_kwh, 0, 4) }}</dd></div>
                    <div><dt class="text-slate-500">Closing kWh</dt><dd class="tabular">{{ formatNumber(reading.closing_kwh, 0, 4) }}</dd></div>
                    <div><dt class="text-slate-500">Opening kVAh</dt><dd class="tabular">{{ reading.opening_kvah != null ? formatNumber(reading.opening_kvah, 0, 4) : '—' }}</dd></div>
                    <div><dt class="text-slate-500">Closing kVAh</dt><dd class="tabular">{{ reading.closing_kvah != null ? formatNumber(reading.closing_kvah, 0, 4) : '—' }}</dd></div>
                    <div><dt class="text-slate-500">MDI</dt><dd class="tabular">{{ reading.mdi_reading != null ? formatNumber(reading.mdi_reading, 0, 4) : '—' }}</dd></div>
                    <div><dt class="text-slate-500">MF</dt><dd class="tabular">{{ reading.multiplication_factor }}</dd></div>
                </dl>
            </AppCard>
            <AppCard v-if="tod_readings.length" title="TOD slots" :padded="false">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Slot</th>
                            <th class="px-4 py-2 text-right font-medium">Units</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="slot in tod_readings" :key="slot.id">
                            <td class="px-4 py-2">{{ slot.slot_name ?? slot.slot_code }}</td>
                            <td class="px-4 py-2 text-right tabular">{{ formatNumber(slot.units, 0, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </AppCard>
        </div>
        <p v-if="reading.remarks" class="mt-4 text-sm whitespace-pre-line text-slate-700">{{ reading.remarks }}</p>
    </AppLayout>
</template>
