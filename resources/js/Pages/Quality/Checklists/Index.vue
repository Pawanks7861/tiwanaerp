<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    checklists: { type: Object, required: true },
    filters: { type: Object, required: true },
    disciplines: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Checklist' },
    { key: 'discipline_label', label: 'Discipline' },
    { key: 'items_count', label: 'Checkpoints', align: 'right' },
    { key: 'is_active', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Quality checklists">
        <PageHeader title="Quality checklists" subtitle="Inspection templates. Each inspection copies the checkpoints when it is requested, so editing a template never changes past inspections.">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" :href="route('quality.checklists.create')">New checklist</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', discipline: filters.discipline ?? 'all', active: filters.active ?? 'all' }"
                :selects="[
                    { key: 'discipline', options: [{ value: 'all', label: 'All disciplines' }, ...disciplines] },
                    { key: 'active', options: [{ value: 'all', label: 'Active and inactive' }, { value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] },
                ]"
                placeholder="Search name or activity…"
            />
            <DataTable
                :columns="columns"
                :rows="checklists.data"
                :row-href="(row) => route('quality.checklists.show', row.id)"
                empty-icon="check-circle"
                empty-title="No checklists"
                empty-description="Create a checklist template to request inspections against it."
            >
                <template #cell-name="{ row }">
                    <div class="font-medium text-slate-900">{{ row.name }}</div>
                    <div v-if="row.activity" class="text-xs text-slate-500">{{ row.activity }}</div>
                </template>
                <template #cell-is_active="{ value }"><StatusBadge :status="value" /></template>
            </DataTable>
            <Pagination :paginator="checklists" />
        </AppCard>
    </AppLayout>
</template>
