<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import PlanningNav from '@/Components/Planning/PlanningNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatPercent } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    milestones: { type: Array, required: true },
    billing_total: { type: String, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Milestone' },
    { key: 'due_date', label: 'Due', type: 'date' },
    { key: 'billing_percent', label: 'Billing', align: 'right' },
    { key: 'tasks_count', label: 'Tasks', align: 'right' },
    { key: 'status', label: 'Status' },
];

const modal = ref(null);
const form = useForm({ name: '', due_date: null, billing_percent: null, sort_order: 0 });
function open(milestone = null) {
    form.clearErrors();
    form.name = milestone?.name ?? '';
    form.due_date = milestone?.due_date ?? null;
    form.billing_percent = milestone?.billing_percent ?? null;
    form.sort_order = milestone?.sort_order ?? props.milestones.length;
    modal.value = { milestone };
}
function save() {
    const m = modal.value.milestone;
    const url = m ? route('projects.planning.milestones.update', [props.project.id, m.id]) : route('projects.planning.milestones.store', props.project.id);
    form[m ? 'put' : 'post'](url, { preserveScroll: true, onSuccess: () => (modal.value = null) });
}

function toggleComplete(m) {
    router.patch(route('projects.planning.milestones.complete', [props.project.id, m.id]), { completed: m.status !== 'completed' }, { preserveScroll: true });
}

const deleting = ref(null);
const processing = ref(false);
function destroy() {
    processing.value = true;
    router.delete(route('projects.planning.milestones.destroy', [props.project.id, deleting.value.id]), {
        preserveScroll: true,
        onFinish: () => ((processing.value = false), (deleting.value = null)),
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="planning" title="Milestones">
        <PlanningNav :project-id="project.id" active="milestones" />

        <AppCard :padded="false">
            <template #header>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Milestones</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Billing allocated: <span class="font-medium tabular" :class="Number(billing_total) > 100 ? 'text-red-600' : 'text-slate-700'">{{ formatPercent(billing_total) }}</span> of 100%. Billing itself happens in a later phase.
                    </p>
                </div>
            </template>
            <template v-if="can.create" #actions>
                <AppButton size="sm" icon="plus" @click="open()">Milestone</AppButton>
            </template>

            <DataTable :columns="columns" :rows="milestones" empty-icon="calendar" empty-title="No milestones yet" empty-description="Add key dates such as foundation complete or handover.">
                <template #cell-name="{ row }">
                    <div class="font-medium text-slate-900">{{ row.name }}</div>
                    <div v-if="row.completed_at" class="text-xs text-slate-500">Completed {{ formatDate(row.completed_at) }}</div>
                </template>
                <template #cell-billing_percent="{ value }">{{ Number(value) ? formatPercent(value) : '—' }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template v-if="can.update || can.delete" #actions="{ row }">
                    <AppButton v-if="can.update" variant="ghost" size="sm" @click="toggleComplete(row)">{{ row.status === 'completed' ? 'Reopen' : 'Complete' }}</AppButton>
                    <AppButton v-if="can.update" variant="ghost" size="sm" icon="pencil" @click="open(row)">Edit</AppButton>
                    <AppButton v-if="can.delete" variant="ghost" size="sm" icon="trash" :aria-label="`Delete ${row.name}`" @click="deleting = row" />
                </template>
            </DataTable>
        </AppCard>

        <AppModal :show="!!modal" :title="modal?.milestone ? 'Edit milestone' : 'Add milestone'" @close="modal = null">
            <form id="milestone-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                <FormInput v-model="form.name" label="Name" required maxlength="200" class="sm:col-span-2" :error="form.errors.name" />
                <FormInput v-model="form.due_date" type="date" label="Due date" :error="form.errors.due_date" />
                <DecimalInput v-model="form.billing_percent" label="Billing %" suffix="%" :decimals="4" :error="form.errors.billing_percent" help="Share of contract value billed at this milestone" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="modal = null">Cancel</AppButton>
                <AppButton type="submit" form="milestone-form" :loading="form.processing">Save</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :show="!!deleting"
            :title="`Delete milestone ${deleting?.name ?? ''}?`"
            message="Milestones linked to tasks cannot be deleted; unlink the tasks first."
            confirm-label="Delete"
            :processing="processing"
            @close="deleting = null"
            @confirm="destroy"
        />
    </ProjectLayout>
</template>
