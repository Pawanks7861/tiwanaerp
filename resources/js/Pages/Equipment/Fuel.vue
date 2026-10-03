<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import EquipmentNav from '@/Components/Equipment/EquipmentNav.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney, formatNumber, formatRate } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    logs: { type: Object, required: true },
    options: { type: Object, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'equipment', label: 'Equipment' },
    { key: 'fuel_added', label: 'Added (L)', align: 'right' },
    { key: 'fuel_consumed', label: 'Used (L)', align: 'right', mobile: false },
    { key: 'closing_fuel', label: 'Closing (L)', align: 'right', mobile: false },
    { key: 'cost', label: 'Cost', align: 'right' },
];

const drawer = ref(false);
const editing = ref(null);
const form = useForm({ equipment_id: null, log_date: props.today, opening_fuel: null, fuel_added: null, fuel_consumed: null, fuel_rate: null, remarks: '' });
const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};
/** Preview only; the server computes closing fuel and cost. */
const closing = computed(() => dec(form.opening_fuel).plus(dec(form.fuel_added)).minus(dec(form.fuel_consumed)));
const cost = computed(() => dec(form.fuel_added).times(dec(form.fuel_rate)).toDecimalPlaces(2, Decimal.ROUND_HALF_UP));

function openNew() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.log_date = props.today;
    drawer.value = true;
}
function openEdit(row) {
    editing.value = row;
    form.clearErrors();
    Object.assign(form, { equipment_id: row.equipment_id, log_date: row.log_date, opening_fuel: row.opening_fuel, fuel_added: row.fuel_added, fuel_consumed: row.fuel_consumed, fuel_rate: row.fuel_rate, remarks: row.remarks ?? '' });
    drawer.value = true;
}
function save() {
    const options = { preserveScroll: true, onSuccess: () => (drawer.value = false) };
    const t = form.transform((d) => ({ ...d, remarks: d.remarks || null }));
    editing.value ? t.put(route('projects.equipment-fuel.update', [props.project.id, editing.value.id]), options) : t.post(route('projects.equipment-fuel.store', props.project.id), options);
}

const deleting = ref(null);
const processing = ref(false);
function destroyLog() {
    processing.value = true;
    router.delete(route('projects.equipment-fuel.destroy', [props.project.id, deleting.value.id]), {
        preserveScroll: true,
        onFinish: () => ((processing.value = false), (deleting.value = null)),
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="equipment" title="Equipment fuel">
        <EquipmentNav :project-id="project.id" active="fuel" />
        <AppCard title="Fuel logs" subtitle="Fuel records track consumption only and are not charged to the project here: diesel reaches project cost through store issues or expenses, never twice." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :disabled="!options.equipment.length" @click="openNew">Log fuel</AppButton>
            </template>
            <DataTable :columns="columns" :rows="logs.data" empty-icon="truck" empty-title="No fuel logged" empty-description="Record fuel filled and consumed by equipment assigned to this project.">
                <template #cell-equipment="{ row }">
                    <div class="font-medium text-slate-900">{{ row.equipment }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.log_date) }}<template v-if="row.remarks"> · {{ row.remarks }}</template></div>
                </template>
                <template #cell-fuel_added="{ value }"><span class="tabular">{{ formatNumber(value, 0, 2) }}</span></template>
                <template #cell-fuel_consumed="{ value }"><span class="tabular">{{ formatNumber(value, 0, 2) }}</span></template>
                <template #cell-closing_fuel="{ value }"><span class="tabular">{{ formatNumber(value, 0, 2) }}</span></template>
                <template #cell-cost="{ row }">
                    <span class="font-semibold tabular">{{ formatMoney(row.cost) }}</span>
                    <div v-if="row.fuel_rate" class="text-[11px] text-slate-500">{{ formatRate(row.fuel_rate) }}/L</div>
                </template>
                <template #actions="{ row }">
                    <template v-if="row.can_update">
                        <AppButton size="sm" variant="ghost" icon="pencil" aria-label="Edit fuel log" @click="openEdit(row)" />
                        <AppButton size="sm" variant="ghost" icon="trash" aria-label="Delete fuel log" @click="deleting = row" />
                    </template>
                </template>
            </DataTable>
            <Pagination :paginator="logs" />
        </AppCard>

        <AppDrawer :show="drawer" :title="editing ? 'Edit fuel log' : 'Log fuel'" subtitle="Equipment must be assigned to this project on the date." @close="drawer = false">
            <div class="space-y-4">
                <SearchSelect v-model="form.equipment_id" label="Equipment" required :options="options.equipment" :error="form.errors.equipment_id" />
                <FormInput v-model="form.log_date" type="date" label="Date" required :max="today" :error="form.errors.log_date" />
                <div class="grid grid-cols-2 gap-3">
                    <DecimalInput v-model="form.opening_fuel" label="Opening (L)" :error="form.errors.opening_fuel" />
                    <DecimalInput v-model="form.fuel_added" label="Added (L)" :error="form.errors.fuel_added" />
                    <DecimalInput v-model="form.fuel_consumed" label="Consumed (L)" :error="form.errors.fuel_consumed" />
                    <DecimalInput v-model="form.fuel_rate" label="Rate / L" prefix="₹" :decimals="4" :error="form.errors.fuel_rate" />
                </div>
                <dl class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Closing</dt><dd class="tabular" :class="closing.isNegative() ? 'text-red-700' : ''">{{ formatNumber(closing.toFixed(2), 0, 2) }} L</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Cost (not posted)</dt><dd class="tabular">{{ formatMoney(cost.toFixed(2)) }}</dd></div>
                </dl>
                <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="500" :error="form.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="drawer = false">Cancel</AppButton>
                <AppButton :loading="form.processing" @click="save">Save</AppButton>
            </template>
        </AppDrawer>
        <ConfirmDialog :show="!!deleting" title="Delete this fuel log?" confirm-label="Delete" :processing="processing" @close="deleting = null" @confirm="destroyLog" />
    </ProjectLayout>
</template>
