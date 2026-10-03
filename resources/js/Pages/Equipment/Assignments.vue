<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import EquipmentNav from '@/Components/Equipment/EquipmentNav.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatRate } from '@/lib/format';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    assignments: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    options: { type: Object, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'equipment', label: 'Equipment' },
    { key: 'status', label: 'Status' },
    { key: 'rate', label: 'Rate', align: 'right' },
    { key: 'site', label: 'Site / task', mobile: false },
    { key: 'operator', label: 'Operator', mobile: false },
    { key: 'usage_logs_count', label: 'Logs', align: 'right', mobile: false },
];

const drawer = ref(false);
const editing = ref(null);
const form = useForm({ equipment_id: null, issue_date: props.today, rate_basis: 'hourly', rate: null, site_id: null, task_id: null, operator_labour_id: null, operator_name: '', remarks: '' });
const selectedEquipment = computed(() => props.options.equipment.find((e) => e.value === form.equipment_id));
watch([() => form.equipment_id, () => form.rate_basis], () => {
    if (!editing.value && selectedEquipment.value) {
        form.rate = (form.rate_basis === 'daily' ? selectedEquipment.value.daily_rate : selectedEquipment.value.hourly_rate) ?? null;
    }
});
function openNew() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.issue_date = props.today;
    drawer.value = true;
}
function openEdit(row) {
    editing.value = row;
    form.clearErrors();
    Object.assign(form, { site_id: row.site_id, task_id: row.task_id, operator_labour_id: row.operator_labour_id, operator_name: row.operator_name ?? '', remarks: row.remarks ?? '' });
    drawer.value = true;
}
function save() {
    const options = { preserveScroll: true, onSuccess: () => (drawer.value = false) };
    const clean = (d) => ({ ...d, operator_name: d.operator_name || null, remarks: d.remarks || null });
    editing.value
        ? form.transform((d) => clean({ site_id: d.site_id, task_id: d.task_id, operator_labour_id: d.operator_labour_id, operator_name: d.operator_name, remarks: d.remarks })).put(route('projects.equipment-assignments.update', [props.project.id, editing.value.id]), options)
        : form.transform(clean).post(route('projects.equipment-assignments.store', props.project.id), options);
}

const returning = ref(null);
const returnForm = useForm({ return_date: props.today, remarks: '' });
function openReturn(row) {
    returning.value = row;
    returnForm.reset();
    returnForm.clearErrors();
    returnForm.return_date = props.today;
}
function submitReturn() {
    returnForm.post(route('projects.equipment-assignments.return', [props.project.id, returning.value.id]), { preserveScroll: true, onSuccess: () => (returning.value = null) });
}
</script>

<template>
    <ProjectLayout :project="project" active="equipment" title="Equipment assignments">
        <EquipmentNav :project-id="project.id" active="assignments" />
        <AppCard title="Equipment on this project" subtitle="An item of equipment can be actively assigned to one project at a time. The assignment rate prices its usage logs." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" @click="openNew">Issue equipment</AppButton>
            </template>
            <FilterBar :filters="{ status: filters.status ?? 'all' }" :selects="[{ key: 'status', options: [{ value: 'all', label: 'All' }, ...statuses] }]" :searchable="false" />
            <DataTable :columns="columns" :rows="assignments.data" empty-icon="wrench" empty-title="No equipment assigned" empty-description="Issue available equipment from the register to this project.">
                <template #cell-equipment="{ row }">
                    <div class="font-medium text-slate-900">{{ row.equipment }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.issue_date) }} → {{ row.return_date ? formatDate(row.return_date) : 'active' }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-rate="{ row }"><span class="tabular">{{ formatRate(row.rate) }}</span> <span class="text-xs text-slate-500">/ {{ row.rate_basis === 'daily' ? 'day' : 'hr' }}</span></template>
                <template #cell-site="{ row }">{{ [row.site, row.task].filter(Boolean).join(' · ') || '—' }}</template>
                <template #cell-operator="{ value }">{{ value ?? '—' }}</template>
                <template #cell-usage_logs_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #actions="{ row }">
                    <template v-if="row.can_update">
                        <AppButton size="sm" variant="ghost" icon="pencil" aria-label="Edit assignment" @click="openEdit(row)" />
                        <AppButton size="sm" variant="secondary" @click="openReturn(row)">Return</AppButton>
                    </template>
                </template>
            </DataTable>
            <Pagination :paginator="assignments" />
        </AppCard>

        <AppDrawer :show="drawer" :title="editing ? 'Edit assignment' : 'Issue equipment'" :subtitle="editing ? editing.equipment : 'Only available equipment can be issued.'" @close="drawer = false">
            <div class="space-y-4">
                <template v-if="!editing">
                    <SearchSelect v-model="form.equipment_id" label="Equipment" required :options="options.equipment" :error="form.errors.equipment_id" />
                    <FormInput v-model="form.issue_date" type="date" label="Issue date" required :max="today" :error="form.errors.issue_date" />
                    <div class="grid grid-cols-2 gap-3">
                        <FormSelect v-model="form.rate_basis" label="Charged" required :options="options.rate_bases" :error="form.errors.rate_basis" />
                        <DecimalInput v-model="form.rate" label="Rate" prefix="₹" :decimals="4" help="Defaults from the register" :error="form.errors.rate" />
                    </div>
                </template>
                <FormSelect v-model="form.site_id" label="Site" placeholder="Whole project" :options="options.sites" :error="form.errors.site_id" />
                <FormSelect v-model="form.task_id" label="Default task" placeholder="No task" :options="options.tasks" :error="form.errors.task_id" />
                <SearchSelect v-model="form.operator_labour_id" label="Operator (labour register)" :options="options.operators" :error="form.errors.operator_labour_id" />
                <FormInput v-model="form.operator_name" label="Operator name (if not in register)" maxlength="150" :error="form.errors.operator_name" />
                <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="500" :error="form.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="drawer = false">Cancel</AppButton>
                <AppButton :loading="form.processing" @click="save">{{ editing ? 'Save' : 'Issue' }}</AppButton>
            </template>
        </AppDrawer>

        <AppModal :show="!!returning" title="Return equipment" @close="returning = null">
            <p class="mb-3 text-sm text-slate-600">{{ returning?.equipment }} becomes available for other projects. The return date cannot be before the last usage log.</p>
            <div class="space-y-4">
                <FormInput v-model="returnForm.return_date" type="date" label="Return date" required :max="today" :error="returnForm.errors.return_date" />
                <FormInput v-model="returnForm.remarks" label="Remarks" maxlength="500" :error="returnForm.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="returning = null">Cancel</AppButton>
                <AppButton :loading="returnForm.processing" @click="submitReturn">Return</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
