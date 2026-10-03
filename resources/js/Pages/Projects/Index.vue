<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatMoneyShort } from '@/lib/format';
import { usePermissions } from '@/lib/permissions';

defineProps({
    projects: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const permissions = usePermissions();

const columns = [
    { key: 'name', label: 'Project' },
    { key: 'status', label: 'Status' },
    { key: 'client_name', label: 'Client', mobile: false },
    { key: 'manager_name', label: 'Project manager' },
    { key: 'expected_end_date', label: 'Due', type: 'date', mobile: false },
    ...(permissions.can('dashboard.view_financials') ? [{ key: 'contract_value', label: 'Contract value', align: 'right' }] : []),
];
</script>

<template>
    <AppLayout title="Projects">
        <PageHeader title="Projects" subtitle="All construction projects you can access.">
            <template #actions>
                <AppButton v-if="can.create" :href="route('projects.create')" icon="plus">New project</AppButton>
            </template>
        </PageHeader>

        <div class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
            <FilterBar
                :filters="filters"
                placeholder="Search by name, code, number or city"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
            />
            <DataTable
                :columns="columns"
                :rows="projects.data"
                :row-href="(row) => route('projects.show', row.id)"
                empty-icon="building"
                empty-title="No projects found"
                :empty-description="filters.search || filters.status !== 'all' ? 'Try a different search or filter.' : 'Projects you create or are assigned to appear here.'"
            >
                <template #cell-name="{ row }">
                    <div class="min-w-0">
                        <div class="font-medium text-slate-900">{{ row.name }}</div>
                        <div class="text-xs text-slate-500">
                            <span class="font-mono">{{ row.code }}</span> · {{ row.project_number }}<template v-if="row.city"> · {{ row.city }}</template>
                        </div>
                    </div>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="row.status" :label="row.status_label" />
                </template>
                <template #cell-contract_value="{ value }">
                    <span class="tabular">{{ formatMoneyShort(value) }}</span>
                </template>
            </DataTable>
            <Pagination :paginator="projects" />
        </div>
    </AppLayout>
</template>
