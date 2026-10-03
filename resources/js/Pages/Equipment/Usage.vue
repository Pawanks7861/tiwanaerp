<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import EquipmentNav from '@/Components/Equipment/EquipmentNav.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    unpostedTotal: { type: String, required: true },
    options: { type: Object, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const columns = [
    { key: 'equipment', label: 'Equipment' },
    { key: 'hours', label: 'Hours', align: 'right' },
    { key: 'cost', label: 'Cost', align: 'right' },
    { key: 'meter', label: 'Meter', align: 'right', mobile: false },
    { key: 'posted', label: 'State' },
];

const drawer = ref(false);
const editing = ref(null);
const form = useForm({ equipment_assignment_id: null, log_date: props.today, task_id: null, opening_meter: null, closing_meter: null, working_hours: null, idle_hours: null, remarks: '' });
watch(
    () => form.equipment_assignment_id,
    (id) => {
        const a = props.options.assignments.find((o) => o.value === id);
        if (!editing.value && a && !form.task_id) {
            form.task_id = a.task_id;
        }
    },
);
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
        equipment_assignment_id: row.equipment_assignment_id,
        log_date: row.log_date,
        task_id: row.task_id,
        opening_meter: row.opening_meter,
        closing_meter: row.closing_meter,
        working_hours: row.working_hours,
        idle_hours: row.idle_hours,
        remarks: row.remarks ?? '',
    });
    drawer.value = true;
}
function save() {
    const options = { preserveScroll: true, onSuccess: () => (drawer.value = false) };
    const clean = (d) => ({ ...d, remarks: d.remarks || null });
    if (editing.value) {
        form.transform((d) => {
            const { equipment_assignment_id, ...rest } = clean(d);

            return rest;
        }).put(route('projects.equipment-usage.update', [props.project.id, editing.value.id]), options);
    } else {
        form.transform(clean).post(route('projects.equipment-usage.store', props.project.id), options);
    }
}

const unposted = computed(() => props.logs.data.filter((l) => !l.posted));
const posting = ref(false);
function post(ids) {
    posting.value = true;
    router.post(route('projects.equipment-usage.post', props.project.id), { ids }, { preserveScroll: true, onFinish: () => (posting.value = false) });
}

const reversing = ref(null);
const deleting = ref(null);
const deleteProcessing = ref(false);
function destroyLog() {
    deleteProcessing.value = true;
    router.delete(route('projects.equipment-usage.destroy', [props.project.id, deleting.value.id]), {
        preserveScroll: true,
        onFinish: () => ((deleteProcessing.value = false), (deleting.value = null)),
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="equipment" title="Equipment usage">
        <EquipmentNav :project-id="project.id" active="usage" />
        <AppCard title="Usage logs" subtitle="Hourly equipment is charged working hours × rate; daily equipment is charged one day's rate per log. Idle hours are recorded but not charged. Posting charges the project." :padded="false">
            <template #actions>
                <AppButton v-if="can.post && unposted.length" size="sm" variant="secondary" :loading="posting" @click="post(unposted.map((l) => l.id))">Post {{ unposted.length }} on page</AppButton>
                <AppButton v-if="can.create" size="sm" icon="plus" @click="openNew">Log usage</AppButton>
            </template>
            <div class="border-b border-line bg-slate-50 px-4 py-2 text-xs text-slate-600 sm:px-5">Unposted usage on this project: <span class="font-semibold tabular text-slate-900">{{ formatMoney(unpostedTotal) }}</span></div>
            <p v-if="page.props.errors?.ids || page.props.errors?.log" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ page.props.errors.ids || page.props.errors.log }}</p>
            <FilterBar
                :filters="{ state: filters.state ?? 'all', assignment_id: filters.assignment_id ? String(filters.assignment_id) : 'all' }"
                :selects="[
                    { key: 'state', options: [{ value: 'all', label: 'Posted & unposted' }, { value: 'unposted', label: 'Unposted' }, { value: 'posted', label: 'Posted' }] },
                    { key: 'assignment_id', options: [{ value: 'all', label: 'All equipment' }, ...options.assignments.map((a) => ({ value: String(a.value), label: a.label }))] },
                ]"
                :searchable="false"
            />
            <DataTable :columns="columns" :rows="logs.data" empty-icon="wrench" empty-title="No usage logged" empty-description="Log daily working hours or meter readings for assigned equipment.">
                <template #cell-equipment="{ row }">
                    <div class="font-medium text-slate-900">{{ row.equipment }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.log_date) }}<template v-if="row.task"> · {{ row.task }}</template></div>
                </template>
                <template #cell-hours="{ row }">
                    <span class="tabular">{{ formatNumber(row.working_hours, 0, 2) }}</span>
                    <span v-if="row.idle_hours !== '0.00'" class="text-xs text-slate-500"> + {{ formatNumber(row.idle_hours, 0, 2) }} idle</span>
                </template>
                <template #cell-cost="{ row }">
                    <span class="font-semibold tabular">{{ formatMoney(row.posted ? row.cost_amount : row.estimated_cost) }}</span>
                    <div class="text-[11px] text-slate-500">{{ row.rate_basis }} · {{ row.rate }}</div>
                </template>
                <template #cell-meter="{ row }"><span class="tabular text-xs">{{ row.opening_meter !== null ? `${formatNumber(row.opening_meter, 0, 2)} → ${formatNumber(row.closing_meter, 0, 2)}` : '—' }}</span></template>
                <template #cell-posted="{ row }"><StatusBadge :status="row.posted ? 'posted' : 'draft'" :label="row.posted ? 'Posted' : 'Unposted'" /></template>
                <template #actions="{ row }">
                    <template v-if="row.can_update">
                        <AppButton v-if="can.post" size="sm" variant="secondary" :loading="posting" @click="post([row.id])">Post</AppButton>
                        <AppButton size="sm" variant="ghost" icon="pencil" aria-label="Edit log" @click="openEdit(row)" />
                        <AppButton size="sm" variant="ghost" icon="trash" aria-label="Delete log" @click="deleting = row" />
                    </template>
                    <AppButton v-if="row.can_reverse" size="sm" variant="ghost" @click="reversing = row">Reverse</AppButton>
                </template>
            </DataTable>
            <Pagination :paginator="logs" />
        </AppCard>

        <AppDrawer :show="drawer" :title="editing ? 'Edit usage log' : 'Log usage'" subtitle="Enter meter readings or working hours; hours are taken from the meter when left empty." @close="drawer = false">
            <div class="space-y-4">
                <SearchSelect v-if="!editing" v-model="form.equipment_assignment_id" label="Equipment" required :options="options.assignments" :error="form.errors.equipment_assignment_id" />
                <p v-else class="text-sm font-medium text-slate-800">{{ editing.equipment }}</p>
                <FormInput v-model="form.log_date" type="date" label="Date" required :max="today" :error="form.errors.log_date" />
                <FormSelect v-model="form.task_id" label="Task" placeholder="No task" :options="options.tasks" :error="form.errors.task_id" />
                <div class="grid grid-cols-2 gap-3">
                    <DecimalInput v-model="form.opening_meter" label="Opening meter" :error="form.errors.opening_meter" />
                    <DecimalInput v-model="form.closing_meter" label="Closing meter" :error="form.errors.closing_meter" />
                    <DecimalInput v-model="form.working_hours" label="Working hours" :error="form.errors.working_hours" />
                    <DecimalInput v-model="form.idle_hours" label="Idle hours" :error="form.errors.idle_hours" />
                </div>
                <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="500" :error="form.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="drawer = false">Cancel</AppButton>
                <AppButton :loading="form.processing" @click="save">Save</AppButton>
            </template>
        </AppDrawer>

        <ReasonDialog
            :show="!!reversing"
            :url="reversing ? route('projects.equipment-usage.reverse', [project.id, reversing.id]) : null"
            title="Reverse usage posting"
            message="The equipment cost is reversed; the log can then be corrected and posted again."
            confirm-label="Reverse"
            @close="reversing = null"
        />
        <ConfirmDialog :show="!!deleting" title="Delete this usage log?" message="Only unposted logs can be deleted." confirm-label="Delete" :processing="deleteProcessing" @close="deleting = null" @confirm="destroyLog" />
    </ProjectLayout>
</template>
