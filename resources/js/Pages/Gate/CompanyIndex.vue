<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    passes: { type: Object, required: true },
});

const columns = [
    { key: 'pass_number', label: 'Pass #' },
    { key: 'project', label: 'Project' },
    { key: 'direction', label: 'Direction', mobile: false },
    { key: 'party', label: 'Party' },
    { key: 'when', label: 'Date', mobile: false },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Gate Passes">
        <AppCard title="Gate Passes" subtitle="All gate passes in your company. Create and approval actions stay on the project Gate Pass screens." :padded="false">
            <DataTable
                :columns="columns"
                :rows="passes.data"
                empty-icon="truck"
                empty-title="No gate passes"
                empty-description="Gate passes are created from a project. Viewing is available company-wide."
                :row-href="(row) => row.show_url"
            >
                <template #cell-pass_number="{ row }">
                    <Link :href="row.show_url" class="font-mono font-medium text-brand-700 hover:underline">{{ row.pass_number }}</Link>
                </template>
                <template #cell-project="{ row }">
                    <div class="text-sm text-slate-800">{{ row.project?.name ?? '—' }}</div>
                    <div v-if="row.project?.code" class="font-mono text-xs text-slate-500">{{ row.project.code }}</div>
                </template>
                <template #cell-direction="{ row }">{{ row.direction_label }}</template>
                <template #cell-party="{ row }">
                    <div class="text-sm text-slate-800">{{ row.party_name || '—' }}</div>
                    <div v-if="row.vehicle_no" class="text-xs text-slate-500">{{ row.vehicle_no }}</div>
                </template>
                <template #cell-when="{ row }">{{ row.passed_at ? formatDateTime(row.passed_at) : '—' }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
            </DataTable>
            <Pagination :paginator="passes" />
        </AppCard>
    </AppLayout>
</template>
