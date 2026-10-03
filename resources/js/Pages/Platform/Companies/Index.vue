<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    companies: { type: Array, required: true },
});

const columns = [
    { key: 'name', label: 'Company' },
    { key: 'gstin', label: 'GSTIN', type: 'code', mobile: false },
    { key: 'city', label: 'City', mobile: false },
    { key: 'users_count', label: 'Users', align: 'right' },
    { key: 'projects_count', label: 'Projects', align: 'right' },
    { key: 'is_active', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Companies">
        <PageHeader title="Companies" subtitle="Platform administration · every company on this installation.">
            <template #actions>
                <AppButton :href="route('platform.companies.create')" icon="plus">New company</AppButton>
            </template>
        </PageHeader>

        <div class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
            <DataTable :columns="columns" :rows="companies" :row-href="(row) => route('platform.companies.edit', row.id)" empty-icon="globe" empty-title="No companies yet">
                <template #cell-name="{ row }">
                    <div class="font-medium text-slate-900">{{ row.name }}</div>
                    <div class="font-mono text-xs text-slate-500">{{ row.code }}</div>
                </template>
                <template #cell-users_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #cell-projects_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #cell-is_active="{ value }"><StatusBadge :status="!!value" /></template>
            </DataTable>
        </div>
    </AppLayout>
</template>
