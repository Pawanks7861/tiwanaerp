<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    account: { type: Object, required: true },
    transactions: { type: Array, required: true },
    can: { type: Object, required: true },
    today: { type: String, required: true },
});

const columns = [
    { key: 'date', label: 'Date' },
    { key: 'type_label', label: 'Entry' },
    { key: 'in', label: 'In', align: 'right' },
    { key: 'out', label: 'Out', align: 'right' },
    { key: 'balance', label: 'Balance', align: 'right', mobile: false },
];

const movement = ref(null);
const moveForm = useForm({ amount: null, txn_date: props.today, remarks: '' });
function openMovement(kind) {
    moveForm.reset();
    moveForm.clearErrors();
    movement.value = kind;
}
function saveMovement() {
    const name = movement.value === 'fund' ? 'projects.petty-cash.fund' : 'projects.petty-cash.return';
    moveForm
        .transform((d) => ({ ...d, remarks: d.remarks || null }))
        .post(route(name, [props.project.id, props.account.id]), { preserveScroll: true, onSuccess: () => (movement.value = null) });
}

const editing = ref(false);
const editForm = useForm({ name: props.account.name, limit_amount: props.account.limit_amount, is_active: props.account.is_active });
function saveAccount() {
    editForm.put(route('projects.petty-cash.update', [props.project.id, props.account.id]), { preserveScroll: true, onSuccess: () => (editing.value = false) });
}
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="account.name">
        <FinanceNav :project-id="project.id" active="petty-cash" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.petty-cash.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Petty cash</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-900">{{ account.name }}</h2>
                            <StatusBadge :status="account.is_active" :label="account.is_active ? 'Active' : 'Closed'" />
                        </div>
                        <p class="mt-1 text-sm text-slate-700">Held by {{ account.holder ?? '—' }} · limit {{ account.limit_amount ? formatMoney(account.limit_amount) : 'none' }}</p>
                        <p class="mt-2 text-2xl font-semibold tabular text-slate-900">{{ formatMoney(account.balance) }}</p>
                        <p class="text-xs text-slate-500">Balance = funding − expenses paid − cash returned (from the ledger below).</p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.fund" size="sm" icon="plus" @click="openMovement('fund')">Fund float</AppButton>
                        <AppButton v-if="can.update" size="sm" variant="secondary" @click="openMovement('return')">Return cash</AppButton>
                        <AppButton v-if="can.update" size="sm" variant="ghost" icon="pencil" aria-label="Edit float" @click="editing = true" />
                    </div>
                </div>
            </AppCard>

            <AppCard title="Ledger" subtitle="Append-only. Expense reversals add a compensating entry." :padded="false">
                <DataTable :columns="columns" :rows="transactions" empty-icon="banknotes" empty-title="No entries yet" empty-description="Fund the float to start.">
                    <template #cell-date="{ row }">
                        <div class="text-sm">{{ formatDate(row.date) }}</div>
                        <div class="text-xs text-slate-500">{{ row.by }}</div>
                    </template>
                    <template #cell-type_label="{ row }">
                        <div class="text-sm">{{ row.type_label }}</div>
                        <Link v-if="row.expense" :href="route('projects.expenses.show', [project.id, row.expense.id])" class="font-mono text-xs text-brand-700 hover:underline">{{ row.expense.number }}</Link>
                        <div v-if="row.remarks" class="text-xs text-slate-500">{{ row.remarks }}</div>
                        <div v-if="row.tally" class="mt-1 flex flex-wrap items-center gap-2">
                            <StatusBadge :status="row.tally.status" :label="`Tally: ${row.tally.status_label}`" />
                            <Link v-if="row.tally.can_preview" :href="row.tally.preview_url" class="text-xs text-brand-700">Preview</Link>
                        </div>
                    </template>
                    <template #cell-in="{ value }"><span class="text-emerald-700 tabular">{{ value ? formatMoney(value) : '' }}</span></template>
                    <template #cell-out="{ value }"><span class="text-red-700 tabular">{{ value ? formatMoney(value) : '' }}</span></template>
                    <template #cell-balance="{ value }"><span class="font-medium tabular">{{ formatMoney(value) }}</span></template>
                </DataTable>
            </AppCard>
        </div>

        <AppModal :show="!!movement" :title="movement === 'fund' ? 'Fund float' : 'Return cash'" @close="movement = null">
            <p class="mb-3 text-sm text-slate-600">{{ movement === 'fund' ? 'Cash handed to the holder. This is a cash transfer, not a project cost.' : 'Unused cash handed back by the holder.' }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <DecimalInput v-model="moveForm.amount" label="Amount" required prefix="₹" :error="moveForm.errors.amount" />
                <FormInput v-model="moveForm.txn_date" type="date" label="Date" required :max="today" :error="moveForm.errors.txn_date" />
                <FormInput v-model="moveForm.remarks" label="Remarks" maxlength="500" class="sm:col-span-2" :error="moveForm.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="movement = null">Cancel</AppButton>
                <AppButton :loading="moveForm.processing" @click="saveMovement">{{ movement === 'fund' ? 'Fund' : 'Return' }}</AppButton>
            </template>
        </AppModal>

        <AppModal :show="editing" title="Edit float" @close="editing = false">
            <div class="grid gap-4">
                <FormInput v-model="editForm.name" label="Name" required maxlength="100" :error="editForm.errors.name" />
                <DecimalInput v-model="editForm.limit_amount" label="Limit" prefix="₹" :error="editForm.errors.limit_amount" />
                <FormSwitch v-model="editForm.is_active" label="Active" description="A closed float cannot be funded or spent from." />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="editing = false">Cancel</AppButton>
                <AppButton :loading="editForm.processing" @click="saveAccount">Save</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
