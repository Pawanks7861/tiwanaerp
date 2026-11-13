<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    dprs: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'dpr_number', label: 'DPR' },
    { key: 'dpr_date', label: 'Date', mobile: false },
    { key: 'status', label: 'Status' },
    { key: 'source', label: 'Source', mobile: false },
    { key: 'engineer', label: 'Engineer', mobile: false },
    { key: 'items_count', label: 'Work lines', align: 'right', mobile: false },
];

const deleting = ref(null);
const processing = ref(false);

function runDelete() {
    if (!deleting.value) return;
    processing.value = true;
    router.delete(route('projects.dprs.destroy', [props.project.id, deleting.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="dprs" title="Daily progress reports">
        <AppCard title="Daily progress reports" subtitle="One DPR per project per day. Filled from WBS activities; approval posts progress to the ledger." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.dprs.create', project.id)">New DPR</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search DPR number…"
            />
            <DataTable
                :columns="columns"
                :rows="dprs.data"
                :row-href="(row) => route('projects.dprs.show', [project.id, row.id])"
                empty-icon="clipboard"
                empty-title="No DPRs yet"
                empty-description="Create today's DPR to record site work and progress."
            >
                <template #cell-dpr_number="{ row }">
                    <div class="font-mono font-medium text-slate-900">{{ row.dpr_number }}</div>
                    <div class="text-xs text-slate-500 md:hidden">{{ formatDate(row.dpr_date) }} · {{ row.engineer ?? '—' }}</div>
                </template>
                <template #cell-dpr_date="{ row }">{{ formatDate(row.dpr_date) }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-source="{ row }"><span class="text-xs text-slate-600">{{ row.source_label }}</span></template>
                <template #cell-engineer="{ value }">{{ value ?? '—' }}</template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #actions="{ row }">
                    <AppButton
                        v-if="row.can?.delete"
                        variant="ghost"
                        size="sm"
                        icon="trash"
                        aria-label="Delete DPR"
                        @click="deleting = row"
                    />
                </template>
            </DataTable>
            <Pagination :paginator="dprs" />
        </AppCard>

        <ConfirmDialog
            :show="!!deleting"
            title="Delete this DPR?"
            message="This permanently removes the DPR and all related work lines, labour, equipment, materials, constraints, attachments, and approval history for this report."
            confirm-label="Delete"
            danger
            :processing="processing"
            @confirm="runDelete"
            @close="deleting = null"
        />
    </ProjectLayout>
</template>
