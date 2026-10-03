<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import LabourNav from '@/Components/Labour/LabourNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney, formatRate } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    crew: { type: Array, required: true },
    advances: { type: Array, required: true },
    labourOptions: { type: Array, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const crewColumns = [
    { key: 'name', label: 'Labourer' },
    { key: 'trade', label: 'Trade' },
    { key: 'daily_wage', label: 'Daily wage', align: 'right' },
    { key: 'ot_rate_per_hour', label: 'OT / hr', align: 'right', mobile: false },
    { key: 'advance_outstanding', label: 'Advance due', align: 'right' },
    { key: 'is_active', label: 'Status', mobile: false },
];
const advanceColumns = [
    { key: 'labour', label: 'Labourer' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'recovered_amount', label: 'Recovered', align: 'right' },
    { key: 'outstanding', label: 'Outstanding', align: 'right' },
];

const drawer = ref(false);
const form = useForm({ labour_id: null, advance_date: props.today, amount: null, remarks: '' });
function openAdvance(labourId = null) {
    form.reset();
    form.clearErrors();
    form.labour_id = labourId;
    form.advance_date = props.today;
    drawer.value = true;
}
function saveAdvance() {
    form.post(route('projects.labour.advances.store', props.project.id), { preserveScroll: true, onSuccess: () => (drawer.value = false) });
}

const deleting = ref(null);
const processing = ref(false);
function destroyAdvance() {
    processing.value = true;
    router.delete(route('projects.labour.advances.destroy', [props.project.id, deleting.value.id]), {
        preserveScroll: true,
        onFinish: () => ((processing.value = false), (deleting.value = null)),
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="labour" title="Labour">
        <LabourNav :project-id="project.id" active="crew" />
        <div class="space-y-4">
            <AppCard title="Project crew" subtitle="Labourers whose current project is this one. Wages and OT rates come from the labour register." :padded="false">
                <template #actions>
                    <AppButton v-if="can.manage_register" size="sm" variant="secondary" :href="route('masters.index', 'labour')">Labour register</AppButton>
                </template>
                <DataTable
                    :columns="crewColumns"
                    :rows="crew"
                    empty-icon="users"
                    empty-title="No crew assigned"
                    empty-description="Set this project as the current project of labourers in the labour register."
                >
                    <template #cell-name="{ row }">
                        <div class="font-medium text-slate-900">{{ row.name }}</div>
                        <div class="text-xs text-slate-500"><span class="font-mono">{{ row.code }}</span><template v-if="row.subcontractor"> · {{ row.subcontractor }}</template></div>
                    </template>
                    <template #cell-trade="{ value }">{{ value ?? '—' }}</template>
                    <template #cell-daily_wage="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                    <template #cell-ot_rate_per_hour="{ value }"><span class="tabular">{{ formatRate(value) }}</span></template>
                    <template #cell-advance_outstanding="{ value }"><span class="tabular" :class="value !== '0.00' ? 'font-semibold text-amber-700' : ''">{{ formatMoney(value) }}</span></template>
                    <template #cell-is_active="{ value }"><StatusBadge :status="value" /></template>
                    <template v-if="can.create_advance" #actions="{ row }">
                        <AppButton v-if="row.is_active" size="sm" variant="ghost" @click="openAdvance(row.id)">Advance</AppButton>
                    </template>
                </DataTable>
            </AppCard>

            <AppCard title="Advances" subtitle="Cash advanced to labourers. Advances are not project cost; they are recovered through labour payments." :padded="false">
                <template #actions>
                    <AppButton v-if="can.create_advance" size="sm" icon="plus" @click="openAdvance()">New advance</AppButton>
                </template>
                <DataTable :columns="advanceColumns" :rows="advances" empty-icon="banknotes" empty-title="No advances" empty-description="Advances given to labourers appear here.">
                    <template #cell-labour="{ row }">
                        <div class="font-medium text-slate-900">{{ row.labour }}</div>
                        <div class="text-xs text-slate-500">{{ formatDate(row.advance_date) }}<template v-if="row.remarks"> · {{ row.remarks }}</template></div>
                    </template>
                    <template #cell-amount="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                    <template #cell-recovered_amount="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                    <template #cell-outstanding="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
                    <template #actions="{ row }">
                        <AppButton v-if="row.can_delete" size="sm" variant="ghost" icon="trash" aria-label="Delete advance" @click="deleting = row" />
                    </template>
                </DataTable>
            </AppCard>
        </div>

        <AppDrawer :show="drawer" title="Record advance" subtitle="Recovered from future labour payments." @close="drawer = false">
            <div class="space-y-4">
                <FormSelect v-model="form.labour_id" label="Labourer" required :options="labourOptions" :error="form.errors.labour_id" />
                <FormInput v-model="form.advance_date" type="date" label="Date" required :max="today" :error="form.errors.advance_date" />
                <DecimalInput v-model="form.amount" label="Amount" prefix="₹" required :error="form.errors.amount" />
                <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="500" :error="form.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="drawer = false">Cancel</AppButton>
                <AppButton :loading="form.processing" @click="saveAdvance">Save advance</AppButton>
            </template>
        </AppDrawer>

        <ConfirmDialog
            :show="!!deleting"
            title="Delete this advance?"
            message="Only advances with nothing recovered yet can be deleted."
            confirm-label="Delete"
            :processing="processing"
            @close="deleting = null"
            @confirm="destroyAdvance"
        />
    </ProjectLayout>
</template>
