<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    expense: { type: Object, default: null },
    categories: { type: Array, required: true },
    vendors: { type: Array, required: true },
    modes: { type: Array, required: true },
    pettyCashAccounts: { type: Array, required: true },
    tasks: { type: Array, required: true },
    boqItems: { type: Array, required: true },
    today: { type: String, required: true },
});

const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};

const editing = computed(() => !!props.expense);
const e = props.expense;
const form = useForm({
    expense_category_id: e?.expense_category_id ?? null,
    expense_date: e?.expense_date ?? props.today,
    vendor_id: e?.vendor_id ?? null,
    payee_name: e?.payee_name ?? '',
    amount: e?.amount ?? null,
    tax_amount: e && e.tax_amount !== '0.00' ? e.tax_amount : null,
    payment_mode: e?.payment_mode ?? 'cash',
    petty_cash_account_id: e?.petty_cash_account_id ?? null,
    reference_no: e?.reference_no ?? '',
    description: e?.description ?? '',
    task_id: e?.task_id ?? null,
    boq_item_id: e?.boq_item_id ?? null,
});

const isPetty = computed(() => form.payment_mode === 'petty_cash');
const total = computed(() => dec(form.amount).plus(dec(form.tax_amount)).toDecimalPlaces(2, Decimal.ROUND_HALF_UP));

function submit() {
    const transform = (d) => ({
        ...d,
        payee_name: d.payee_name || null,
        reference_no: d.reference_no || null,
        petty_cash_account_id: d.payment_mode === 'petty_cash' ? d.petty_cash_account_id : null,
    });
    editing.value
        ? form.transform(transform).put(route('projects.expenses.update', [props.project.id, props.expense.id]))
        : form.transform(transform).post(route('projects.expenses.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="finance" :title="editing ? expense.expense_number : 'New expense'">
        <FinanceNav :project-id="project.id" active="expenses" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.expenses.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Expenses</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit ${expense.expense_number}` : 'New expense' }}</h2>
                    <p class="text-xs text-slate-500">The category decides the cost head. On approval the amount excluding GST is posted to the project cost once; paying it later posts nothing.</p>
                </div>
                <p v-if="form.errors.expense" class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ form.errors.expense }}</p>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <SearchSelect v-model="form.expense_category_id" label="Category" required :options="categories" class="sm:col-span-2" :error="form.errors.expense_category_id" />
                    <FormInput v-model="form.expense_date" type="date" label="Expense date" required :max="today" :error="form.errors.expense_date" />
                    <FormSelect v-model="form.payment_mode" label="Paid by" required :options="modes" :error="form.errors.payment_mode" />
                    <SearchSelect v-model="form.vendor_id" label="Vendor" :options="vendors" class="sm:col-span-2" help="Optional. Leave empty for a one-off payee." :error="form.errors.vendor_id" />
                    <FormInput v-model="form.payee_name" label="Payee name" maxlength="150" class="sm:col-span-2" :help="form.vendor_id ? null : 'Required when no vendor is chosen.'" :error="form.errors.payee_name" />
                    <SearchSelect v-if="isPetty" v-model="form.petty_cash_account_id" label="Petty cash account" required :options="pettyCashAccounts" class="sm:col-span-2" help="Approval spends from this account and marks the expense paid." :error="form.errors.petty_cash_account_id" />
                    <FormInput v-model="form.reference_no" label="Bill / receipt no." maxlength="100" :error="form.errors.reference_no" />
                    <FormInput v-model="form.description" label="Description" required multiline :rows="2" maxlength="1000" class="sm:col-span-2 lg:col-span-4" :error="form.errors.description" />
                </div>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Amount">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <DecimalInput v-model="form.amount" label="Amount (excl. GST)" required prefix="₹" :error="form.errors.amount" />
                        <DecimalInput v-model="form.tax_amount" label="GST" prefix="₹" help="Input tax; not part of the project cost." :error="form.errors.tax_amount" />
                    </div>
                    <div class="mt-4 flex justify-between border-t border-line pt-3 text-base font-semibold">
                        <span>Total</span><span class="tabular">{{ formatMoney(total.toFixed(2)) }}</span>
                    </div>
                </AppCard>
                <AppCard title="Cost allocation" subtitle="Optional: link the cost to a task or BOQ item.">
                    <div class="grid gap-4">
                        <SearchSelect v-model="form.task_id" label="Task" :options="tasks" :error="form.errors.task_id" />
                        <SearchSelect v-model="form.boq_item_id" label="BOQ item" :options="boqItems" :error="form.errors.boq_item_id" />
                    </div>
                </AppCard>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.expenses.show', [project.id, expense.id]) : route('projects.expenses.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
