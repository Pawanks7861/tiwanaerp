<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';

defineProps({
    imports: { type: Object, required: true },
    modules: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const columns = [
    { key: 'module_label', label: 'Module' },
    { key: 'original_filename', label: 'File' },
    { key: 'status', label: 'Status' },
    { key: 'mode', label: 'Mode', mobile: false },
    { key: 'counts', label: 'Rows', mobile: false },
    { key: 'uploaded_by', label: 'Uploaded by', mobile: false },
    { key: 'created_at', label: 'When', mobile: false },
    { key: 'actions', label: '', align: 'right' },
];

function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <AppLayout title="Import history">
        <PageHeader
            title="Import history"
            subtitle="Excel and CSV imports for modules you can access. Large jobs process in the background."
        />

        <div class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
            <FilterBar
                :filters="{ module: filters.module || 'all' }"
                :searchable="false"
                :selects="[
                    {
                        key: 'module',
                        options: [
                            { value: 'all', label: 'All modules' },
                            ...modules.map((m) => ({ value: m.key, label: m.label })),
                        ],
                    },
                ]"
            />
            <DataTable
                :columns="columns"
                :rows="imports.data"
                empty-icon="document"
                empty-title="No imports yet"
                empty-description="Use Import on a supported module list to upload a spreadsheet."
            >
                <template #cell-original_filename="{ row }">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-slate-900">{{ row.original_filename }}</div>
                        <div class="text-xs text-slate-500">
                            <span class="font-mono text-[11px]">{{ row.uuid?.slice(0, 8) }}</span>
                            <template v-if="row.size_bytes"> · {{ formatBytes(row.size_bytes) }}</template>
                            <template v-if="row.project"> · {{ row.project.code }}</template>
                        </div>
                    </div>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="row.status" :label="row.status_label || row.status" />
                    <p v-if="row.failure_message" class="mt-1 max-w-xs text-xs text-rose-600">{{ row.failure_message }}</p>
                </template>
                <template #cell-mode="{ value }">
                    <span class="capitalize">{{ value || '—' }}</span>
                </template>
                <template #cell-counts="{ row }">
                    <span class="tabular text-slate-700">
                        {{ row.total_rows ?? 0 }}
                        <span class="text-slate-400">·</span>
                        <span class="text-emerald-700">+{{ row.create_count ?? 0 }}</span>
                        <span class="text-slate-400">/</span>
                        <span class="text-sky-700">~{{ row.update_count ?? 0 }}</span>
                        <span v-if="row.fail_count" class="text-rose-600"> / !{{ row.fail_count }}</span>
                    </span>
                </template>
                <template #cell-uploaded_by="{ row }">
                    {{ row.uploaded_by?.name || '—' }}
                </template>
                <template #cell-created_at="{ value }">
                    {{ formatDateTime(value) }}
                </template>
                <template #cell-actions="{ row }">
                    <AppButton
                        v-if="row.has_error_report"
                        variant="secondary"
                        size="sm"
                        :href="route('imports.errors', row.id)"
                    >
                        Errors
                    </AppButton>
                </template>
            </DataTable>
            <Pagination :paginator="imports" />
        </div>
    </AppLayout>
</template>
