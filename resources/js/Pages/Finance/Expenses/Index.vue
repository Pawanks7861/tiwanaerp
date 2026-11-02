<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import ImportTemplateDownload from '@/Components/Imports/ImportTemplateDownload.vue';
import UniversalImportModal from '@/Components/Imports/UniversalImportModal.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    project: { type: Object, required: true },
    expenses: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const showImport = ref(false);

const columns = [
    { key: 'expense_number', label: 'Expense' },
    { key: 'category', label: 'Category', mobile: false },
    { key: 'payee', label: 'Payee' },
    { key: 'status', label: 'Status' },
    { key: 'total_amount', label: 'Amount', align: 'right' },
];
</script>

<template>
    <ProjectLayout :project="project" active="finance" title="Expenses">
        <FinanceNav :project-id="project.id" active="expenses" />
        <AppCard title="Expenses" subtitle="Site and overhead expenses. Approval posts the amount (excluding GST) to the project cost." :padded="false">
            <template #actions>
                <ImportTemplateDownload v-if="can.import" module="expenses" :project-id="project.id" size="sm" />
                <AppButton v-if="can.import" size="sm" variant="secondary" @click="showImport = true">Import</AppButton>
                <AppButton v-if="can.create" size="sm" icon="plus" :href="route('projects.expenses.create', project.id)">New expense</AppButton>
            </template>
            <FilterBar
                :filters="{ search: filters.search ?? '', status: filters.status ?? 'all' }"
                :selects="[{ key: 'status', options: [{ value: 'all', label: 'All statuses' }, ...statuses] }]"
                placeholder="Search number, payee or description…"
            />
            <DataTable
                :columns="columns"
                :rows="expenses.data"
                :row-href="(row) => route('projects.expenses.show', [project.id, row.id])"
                empty-icon="receipt"
                empty-title="No expenses"
                empty-description="Record a site expense, then submit it for approval."
            >
                <template #cell-expense_number="{ row }">
                    <div class="font-mono text-xs font-medium text-slate-900">{{ row.expense_number }}</div>
                    <div class="text-xs text-slate-500">{{ formatDate(row.expense_date) }} · {{ row.payment_mode_label }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #cell-total_amount="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
            <Pagination :paginator="expenses" />
        </AppCard>

        <UniversalImportModal
            :show="showImport"
            module="expenses"
            :project-id="project.id"
            @close="showImport = false"
            @imported="() => ((showImport = false), router.reload())"
        />
    </ProjectLayout>
</template>
