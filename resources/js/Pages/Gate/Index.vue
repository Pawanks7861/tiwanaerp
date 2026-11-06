<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDateTime } from '@/lib/format';

defineProps({
    project: { type: Object, required: true },
    passes: { type: Object, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'pass_number', label: 'Pass' },
    { key: 'direction_label', label: 'In / Out' },
    { key: 'party', label: 'Party / vehicle' },
    { key: 'status_label', label: 'Status' },
];
</script>

<template>
    <ProjectLayout :project="project" active="gate" title="Gate passes">
        <AppCard title="Gate passes" subtitle="Movement of material, steel, scrap and equipment through the gate. Stock is not changed here." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.gate-passes.create', project.id)">New gate pass</AppButton>
            </template>
            <DataTable
                :columns="columns"
                :rows="passes.data"
                :row-href="(row) => route('projects.gate-passes.show', [project.id, row.id])"
                empty-icon="truck"
                empty-title="No gate passes"
                empty-description="Record material, steel, scrap or equipment leaving or entering the site."
            >
                <template #cell-pass_number="{ row }">
                    <div class="font-mono font-medium">{{ row.pass_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDateTime(row.passed_at) }}</div>
                </template>
                <template #cell-party="{ row }">
                    <div>{{ row.party_name || '—' }}</div>
                    <div class="text-xs text-slate-500">{{ row.vehicle_no }}<template v-if="row.returnable"> · Returnable</template></div>
                </template>
                <template #cell-status_label="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
            </DataTable>
            <Pagination :paginator="passes" />
        </AppCard>
    </ProjectLayout>
</template>
