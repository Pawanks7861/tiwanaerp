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
import { Link, router, useForm } from '@inertiajs/vue3';
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
    { key: 'cost', label: 'Value', align: 'right' },
    { key: 'issue', label: 'Issue', mobile: false },
];

const drawer = ref(false);
const editing = ref(null);
const form = useForm({
    equipment_id: null,
    log_date: props.today,
    opening_fuel: null,
    fuel_added: null,
    fuel_consumed: null,
    material_id: null,
    warehouse_id: null,
    site_id: null,
    operator_name: '',
    meter_reading: null,
    remarks: '',
});
const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};
/** Preview only; the server computes closing fuel. Added fuel is valued by the inventory issue. */
const closing = computed(() => dec(form.opening_fuel).plus(dec(form.fuel_added)).minus(dec(form.fuel_consumed)));

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
    Object.assign(form, {
        equipment_id: row.equipment_id,
        log_date: row.log_date,
        opening_fuel: row.opening_fuel,
        fuel_added: row.fuel_added,
        fuel_consumed: row.fuel_consumed,
        material_id: row.material_id,
        warehouse_id: row.warehouse_id,
        site_id: row.site_id,
        operator_name: row.operator_name ?? '',
        meter_reading: row.meter_reading,
        remarks: row.remarks ?? '',
    });
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
        <AppCard title="Fuel logs" subtitle="Fuel added to a machine is issued from inventory. The store issue carries the cost once." :padded="false">
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
                    <div v-if="row.fuel_rate && row.fuel_rate !== '0.0000'" class="text-[11px] text-slate-500">{{ formatRate(row.fuel_rate) }} from stock</div>
                </template>
                <template #cell-issue="{ row }">
                    <Link v-if="row.issue_url" :href="row.issue_url" class="font-mono text-brand-700 hover:underline">{{ row.issue_number }}</Link>
                    <span v-else class="text-slate-400">{{ row.issue_number || '—' }}</span>
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

        <AppDrawer :show="drawer" :title="editing ? 'Edit fuel log' : 'Log fuel'" subtitle="Added fuel is issued from a store. Cost comes from inventory, not from a typed rate." @close="drawer = false">
            <div class="space-y-4">
                <SearchSelect v-model="form.equipment_id" label="Equipment" required :options="options.equipment" :error="form.errors.equipment_id" />
                <FormInput v-model="form.log_date" type="date" label="Date" required :max="today" :error="form.errors.log_date" />
                <SearchSelect v-model="form.material_id" label="Fuel material" :options="options.materials" :error="form.errors.material_id" />
                <SearchSelect v-model="form.warehouse_id" label="Issue from store" :options="options.warehouses" :error="form.errors.warehouse_id" />
                <SearchSelect v-model="form.site_id" label="Site" :options="options.sites" :error="form.errors.site_id" />
                <div class="grid grid-cols-2 gap-3">
                    <DecimalInput v-model="form.opening_fuel" label="Opening" :error="form.errors.opening_fuel" />
                    <DecimalInput v-model="form.fuel_added" label="Added" :error="form.errors.fuel_added" />
                    <DecimalInput v-model="form.fuel_consumed" label="Consumed" :error="form.errors.fuel_consumed" />
                    <DecimalInput v-model="form.meter_reading" label="Meter / hours" :error="form.errors.meter_reading" />
                </div>
                <FormInput v-model="form.operator_name" label="Operator" maxlength="120" :error="form.errors.operator_name" />
                <dl class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Closing</dt><dd class="tabular" :class="closing.isNegative() ? 'text-red-700' : ''">{{ formatNumber(closing.toFixed(2), 0, 2) }}</dd></div>
                    <div class="text-xs text-slate-500">Value is taken from the store issue after save.</div>
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
