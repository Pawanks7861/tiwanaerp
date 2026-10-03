<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import EquipmentNav from '@/Components/Equipment/EquipmentNav.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    repairs: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    options: { type: Object, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'equipment', label: 'Equipment' },
    { key: 'status', label: 'Status' },
    { key: 'vendor', label: 'Vendor', mobile: false },
    { key: 'cost', label: 'Cost', align: 'right' },
];

const drawer = ref(false);
const editing = ref(null);
const form = useForm({ equipment_id: null, repair_date: props.today, description: '', vendor_id: null, cost: null, remarks: '' });
function openNew() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.repair_date = props.today;
    drawer.value = true;
}
function openEdit(row) {
    editing.value = row;
    form.clearErrors();
    Object.assign(form, { equipment_id: row.equipment_id, repair_date: row.repair_date, description: row.description, vendor_id: row.vendor_id, cost: row.cost, remarks: row.remarks ?? '' });
    drawer.value = true;
}
function save() {
    const options = { preserveScroll: true, onSuccess: () => (drawer.value = false) };
    if (editing.value) {
        form.transform(({ equipment_id, ...d }) => ({ ...d, remarks: d.remarks || null })).put(route('projects.equipment-repairs.update', [props.project.id, editing.value.id]), options);
    } else {
        form.transform((d) => ({ ...d, remarks: d.remarks || null })).post(route('projects.equipment-repairs.store', props.project.id), options);
    }
}

const completing = ref(null);
const completeForm = useForm({ completed_date: props.today, cost: null, remarks: '' });
function openComplete(row) {
    completing.value = row;
    completeForm.reset();
    completeForm.clearErrors();
    completeForm.completed_date = props.today;
    completeForm.cost = row.cost;
}
function submitComplete() {
    completeForm.post(route('projects.equipment-repairs.complete', [props.project.id, completing.value.id]), { preserveScroll: true, onSuccess: () => (completing.value = null) });
}

const cancelling = ref(null);
const viewing = ref(null);
const viewingRow = computed(() => (viewing.value ? props.repairs.data.find((r) => r.id === viewing.value) : null));
</script>

<template>
    <ProjectLayout :project="project" active="equipment" title="Equipment repairs">
        <EquipmentNav :project-id="project.id" active="repairs" />
        <AppCard title="Repairs" subtitle="Equipment under repair cannot be used. Repair cost is recorded for reference only; it reaches project cost through a vendor bill or expense, not here." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" @click="openNew">Record repair</AppButton>
            </template>
            <FilterBar :filters="{ status: filters.status ?? 'all' }" :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]" :searchable="false" />
            <DataTable :columns="columns" :rows="repairs.data" :row-href="null" empty-icon="wrench" empty-title="No repairs" empty-description="Record breakdowns and repairs of equipment used on this project.">
                <template #cell-equipment="{ row }">
                    <button type="button" class="text-left" @click="viewing = row.id">
                        <span class="block font-medium text-slate-900 hover:underline">{{ row.equipment }}</span>
                        <span class="block text-xs text-slate-500">{{ formatDate(row.repair_date) }}<template v-if="row.completed_date"> → {{ formatDate(row.completed_date) }}</template> · {{ row.description }}</span>
                    </button>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-vendor="{ value }">{{ value ?? '—' }}</template>
                <template #cell-cost="{ value }"><span class="tabular">{{ value ? formatMoney(value) : '—' }}</span></template>
                <template #actions="{ row }">
                    <template v-if="row.can_update">
                        <AppButton size="sm" variant="ghost" icon="pencil" aria-label="Edit repair" @click="openEdit(row)" />
                        <AppButton size="sm" variant="secondary" @click="openComplete(row)">Complete</AppButton>
                        <AppButton size="sm" variant="ghost" @click="cancelling = row">Cancel</AppButton>
                    </template>
                </template>
            </DataTable>
            <Pagination :paginator="repairs" />
        </AppCard>

        <AppDrawer :show="drawer" :title="editing ? 'Edit repair' : 'Record repair'" subtitle="The equipment is marked under repair until the repair is completed or cancelled." @close="drawer = false">
            <div class="space-y-4">
                <SearchSelect v-if="!editing" v-model="form.equipment_id" label="Equipment" required :options="options.equipment" :error="form.errors.equipment_id" />
                <p v-else class="text-sm font-medium text-slate-800">{{ editing.equipment }}</p>
                <FormInput v-model="form.repair_date" type="date" label="Repair date" required :max="today" :error="form.errors.repair_date" />
                <FormInput v-model="form.description" label="Problem / work" required multiline :rows="3" maxlength="1000" :error="form.errors.description" />
                <SearchSelect v-model="form.vendor_id" label="Repair vendor" :options="options.vendors" :error="form.errors.vendor_id" />
                <DecimalInput v-model="form.cost" label="Estimated cost" prefix="₹" :error="form.errors.cost" />
                <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="500" :error="form.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="drawer = false">Cancel</AppButton>
                <AppButton :loading="form.processing" @click="save">Save</AppButton>
            </template>
        </AppDrawer>

        <AppModal :show="!!completing" title="Complete repair" @close="completing = null">
            <p class="mb-3 text-sm text-slate-600">{{ completing?.equipment }} returns to service (assigned if it still has an active assignment, otherwise available).</p>
            <div class="space-y-4">
                <FormInput v-model="completeForm.completed_date" type="date" label="Completed on" required :min="completing?.repair_date" :max="today" :error="completeForm.errors.completed_date" />
                <DecimalInput v-model="completeForm.cost" label="Final cost" prefix="₹" :error="completeForm.errors.cost" />
                <FormInput v-model="completeForm.remarks" label="Remarks" maxlength="500" :error="completeForm.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="completing = null">Back</AppButton>
                <AppButton :loading="completeForm.processing" @click="submitComplete">Complete</AppButton>
            </template>
        </AppModal>

        <ReasonDialog
            :show="!!cancelling"
            :url="cancelling ? route('projects.equipment-repairs.cancel', [project.id, cancelling.id]) : null"
            title="Cancel repair"
            message="The equipment returns to service."
            confirm-label="Cancel repair"
            @close="cancelling = null"
        />

        <AppDrawer :show="!!viewingRow" :title="viewingRow?.equipment" :subtitle="viewingRow ? `${formatDate(viewingRow.repair_date)} · ${viewingRow.status_label}` : ''" @close="viewing = null">
            <template v-if="viewingRow">
                <p class="text-sm whitespace-pre-line text-slate-700">{{ viewingRow.description }}</p>
                <p v-if="viewingRow.remarks" class="mt-2 text-sm text-slate-500">{{ viewingRow.remarks }}</p>
                <div class="mt-4">
                    <AttachmentPanel :attachments="viewingRow.attachments" attachable-type="equipment_repair" :attachable-id="viewingRow.id" :can-upload="viewingRow.can_update" :can-delete="viewingRow.can_update" placeholder="Job card, invoice, photos…" />
                </div>
            </template>
        </AppDrawer>
    </ProjectLayout>
</template>
