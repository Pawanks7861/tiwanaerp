<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    groups: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'label', label: 'Process' },
    { key: 'summary', label: 'Approvers' },
    { key: 'is_active', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Approval Workflows">
        <PageHeader
            title="Approval Workflows"
            subtitle="Set who must approve each process. You can name a specific user, a company role, or a project role. Documents already waiting for approval keep their original steps."
        />

        <div class="space-y-6">
            <section v-for="group in groups" :key="group.group" class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
                <header class="border-b border-line bg-slate-50 px-4 py-2.5 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    {{ group.group }}
                </header>
                <DataTable
                    :columns="columns"
                    :rows="group.processes"
                    row-key="type"
                    :row-href="(row) => route('admin.workflows.edit', row.type)"
                    empty-title="No processes"
                >
                    <template #cell-label="{ row }">
                        <div class="font-medium text-slate-900">{{ row.label }}</div>
                        <div class="text-xs text-slate-500">{{ row.steps_count }} step{{ row.steps_count === 1 ? '' : 's' }}</div>
                    </template>
                    <template #cell-summary="{ row }">
                        <span v-if="row.configured" class="text-slate-700">{{ row.summary.join(' → ') }}</span>
                        <span v-else class="text-slate-400">Not configured</span>
                    </template>
                    <template #cell-is_active="{ row }">
                        <AppBadge v-if="row.configured && row.is_active" color="green" dot>Active</AppBadge>
                        <AppBadge v-else-if="row.configured" color="amber" dot>Off</AppBadge>
                        <AppBadge v-else color="slate">Unset</AppBadge>
                    </template>
                    <template #actions="{ row }">
                        <AppButton variant="ghost" size="sm" :icon="can.manage ? 'pencil' : null" :href="route('admin.workflows.edit', row.type)">
                            {{ can.manage ? 'Edit' : 'View' }}
                        </AppButton>
                    </template>
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>
