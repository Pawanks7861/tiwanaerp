<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    boqs: { type: Array, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = computed(() => [
    { key: 'boq_number', label: 'BOQ' },
    { key: 'version', label: 'Version' },
    { key: 'status', label: 'Status' },
    props.can.view_costs && { key: 'total_cost_amount', label: 'Cost', type: 'money', mobile: false },
    { key: 'total_client_amount', label: 'Client value', type: 'money' },
    { key: 'dates', label: 'Updated', mobile: false },
].filter(Boolean));

const showCreate = ref(false);
const createForm = useForm({ title: '' });
const confirming = ref(null);
const processing = ref(false);

function create() {
    createForm.post(route('projects.boqs.store', props.project.id), { onSuccess: () => (showCreate.value = false) });
}

const ACTIONS = {
    submit: { title: 'Submit for approval?', message: 'The BOQ is locked while it is being approved.', label: 'Submit', route: 'projects.boqs.submit', danger: false },
    revise: { title: 'Create a revision?', message: 'A new draft version is created from this approved BOQ. The approved version stays unchanged until the revision is approved.', label: 'Create revision', route: 'projects.boqs.revise', danger: false },
    delete: { title: 'Delete this BOQ?', message: 'Only draft or rejected BOQs can be deleted.', label: 'Delete', route: 'projects.boqs.destroy', danger: true },
};

function confirmAction() {
    const { action, boq } = confirming.value;
    const url = route(ACTIONS[action].route, [props.project.id, boq.id]);
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    action === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}
</script>

<template>
    <ProjectLayout :project="project" active="boq" title="BOQ">
        <AppCard title="Bills of quantities" subtitle="Each BOQ is versioned. Approved versions are locked; changes go through a revision." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" @click="(createForm.reset(), (showCreate = true))">New BOQ</AppButton>
            </template>

            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search BOQ number or title…"
            />

            <DataTable
                :columns="columns"
                :rows="boqs"
                :row-href="(row) => route('projects.boqs.show', [project.id, row.id])"
                empty-icon="document"
                empty-title="No BOQs yet"
                empty-description="Create a BOQ and add its sections and items, or import them from Excel."
            >
                <template #cell-boq_number="{ row }">
                    <div class="font-medium text-slate-900">
                        <span class="font-mono text-xs">{{ row.boq_number }}</span>
                        <span v-if="row.is_current" class="ml-1.5 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 uppercase">Current</span>
                    </div>
                    <div class="text-xs text-slate-500">{{ row.title }}</div>
                </template>
                <template #cell-version="{ value }"><span class="tabular">v{{ value }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-total_cost_amount="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                <template #cell-total_client_amount="{ value }"><span class="tabular font-medium">{{ formatMoney(value) }}</span></template>
                <template #cell-dates="{ row }">
                    <div class="text-xs text-slate-600">{{ formatDate(row.updated_at) }}</div>
                    <div v-if="row.approved_at" class="text-xs text-slate-400">Approved {{ formatDate(row.approved_at) }}</div>
                </template>
                <template #actions="{ row }">
                    <AppButton variant="ghost" size="sm" :href="route('projects.boqs.show', [project.id, row.id])">{{ row.can.update ? 'Edit' : 'View' }}</AppButton>
                    <AppButton v-if="row.can.submit" variant="ghost" size="sm" @click="confirming = { action: 'submit', boq: row }">Submit</AppButton>
                    <AppButton v-if="row.can.revise" variant="ghost" size="sm" @click="confirming = { action: 'revise', boq: row }">Revise</AppButton>
                    <a
                        v-if="row.can.export"
                        :href="route('projects.boqs.export', [project.id, row.id])"
                        class="inline-flex h-8 items-center gap-1 rounded-lg px-3 text-xs font-medium text-slate-600 hover:bg-slate-100"
                    >Export</a>
                    <AppButton v-if="row.can.delete" variant="ghost" size="sm" icon="trash" @click="confirming = { action: 'delete', boq: row }" />
                </template>
            </DataTable>
        </AppCard>

        <AppModal :show="showCreate" title="New BOQ" @close="showCreate = false">
            <form id="boq-create" @submit.prevent="create">
                <FormInput v-model="createForm.title" label="Title" required autofocus maxlength="200" :error="createForm.errors.title" placeholder="e.g. Civil & structural works" />
                <p class="mt-2 text-xs text-slate-500">The BOQ number is assigned automatically.</p>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="showCreate = false">Cancel</AppButton>
                <AppButton type="submit" form="boq-create" :loading="createForm.processing">Create BOQ</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? ACTIONS[confirming.action].title : ''"
            :message="confirming ? ACTIONS[confirming.action].message : ''"
            :confirm-label="confirming ? ACTIONS[confirming.action].label : ''"
            :danger="confirming ? ACTIONS[confirming.action].danger : true"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
    </ProjectLayout>
</template>
