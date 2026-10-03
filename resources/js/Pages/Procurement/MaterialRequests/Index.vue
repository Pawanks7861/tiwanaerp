<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    requests: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    priorities: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'request_number', label: 'Request' },
    { key: 'status', label: 'Status' },
    { key: 'site', label: 'Site', mobile: false },
    { key: 'required_date', label: 'Required by' },
    { key: 'priority', label: 'Priority' },
    { key: 'items_count', label: 'Items', align: 'right', mobile: false },
    { key: 'ordered_percent', label: 'Ordered' },
];
const priorityTone = { low: 'text-slate-500', medium: 'text-slate-700', high: 'text-amber-700', urgent: 'text-red-700 font-semibold' };
</script>

<template>
    <ProjectLayout :project="project" active="procurement" title="Material requests">
        <ProcurementNav :project-id="project.id" active="material-requests" />
        <AppCard title="Material requests" subtitle="Site requirements raised against the approved BOQ. Approved requests can be sent for quotation." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.material-requests.create', project.id)">New request</AppButton>
            </template>

            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all', priority: filters.priority ?? 'all' }"
                :selects="[
                    { key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] },
                    { key: 'priority', options: [{ value: 'all', label: 'All priorities' }, ...priorities] },
                ]"
                placeholder="Search request number…"
            />

            <DataTable
                :columns="columns"
                :rows="requests.data"
                :row-href="(row) => route('projects.material-requests.show', [project.id, row.id])"
                empty-icon="document"
                empty-title="No material requests"
                empty-description="Raise a request for the materials a site needs. Once approved it can go out for quotation."
            >
                <template #cell-request_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.request_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.request_date) }}<template v-if="row.requested_by"> · {{ row.requested_by }}</template></div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-site="{ value }">{{ value ?? '—' }}</template>
                <template #cell-required_date="{ value }">{{ formatDate(value) }}</template>
                <template #cell-priority="{ row }"><span class="text-xs" :class="priorityTone[row.priority]">{{ row.priority_label }}</span></template>
                <template #cell-items_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #cell-ordered_percent="{ row }">
                    <div class="flex items-center justify-end gap-2 md:justify-start">
                        <div class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full bg-brand-500" :style="{ width: `${row.ordered_percent}%` }" />
                        </div>
                        <span class="text-xs text-slate-600 tabular">{{ row.ordered_percent }}%</span>
                    </div>
                    <div v-if="row.received_percent !== '0'" class="text-[11px] text-slate-400 tabular">{{ row.received_percent }}% received</div>
                </template>
            </DataTable>
            <Pagination :paginator="requests" />
        </AppCard>
    </ProjectLayout>
</template>
