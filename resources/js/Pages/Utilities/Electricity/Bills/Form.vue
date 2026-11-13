<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatCost } from '@/lib/electricity';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    bill: { type: Object, default: null },
    readings: { type: Array, default: () => [] },
    tariffs: { type: Array, default: () => [] },
    can_view_costs: { type: Boolean, default: false },
});

const editing = computed(() => !!props.bill);
const b = props.bill;

const form = useForm({
    electricity_meter_reading_id: b?.electricity_meter_reading_id ?? null,
    electricity_tariff_id: b?.electricity_tariff_id ?? null,
    bill_number: b?.bill_number ?? '',
    billing_month: b?.billing_month ?? '',
    bill_date: b?.bill_date ?? null,
    due_date: b?.due_date ?? null,
    actual_bill_total: b?.actual_bill_total ?? null,
    remarks: b?.remarks ?? '',
    component_actuals: b?.components?.map((c) => ({ id: c.id, tariff_component_id: c.tariff_component_id, actual_amount: c.actual_amount })) ?? [],
});

function submit() {
    const transform = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
    editing.value
        ? form.transform(transform).put(route('utilities.electricity.bills.update', props.bill.id))
        : form.transform(transform).post(route('utilities.electricity.bills.store'));
}
</script>

<template>
    <AppLayout :title="editing ? bill.bill_number : 'New bill'">
        <PageHeader
            :title="editing ? `Edit ${bill.bill_number}` : 'New electricity bill'"
            :back="editing ? route('utilities.electricity.bills.show', bill.id) : route('utilities.electricity.bills.index')"
        />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard title="Bill header">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <SearchSelect v-model="form.electricity_meter_reading_id" label="Meter reading" required :options="readings" :disabled="editing" :error="form.errors.electricity_meter_reading_id" />
                    <SearchSelect v-model="form.electricity_tariff_id" label="Tariff" :options="tariffs" :error="form.errors.electricity_tariff_id" />
                    <FormInput v-model="form.bill_number" label="Bill number" required maxlength="60" :error="form.errors.bill_number" />
                    <FormInput v-model="form.billing_month" type="date" label="Billing month" required :error="form.errors.billing_month" />
                    <FormInput v-model="form.bill_date" type="date" label="Bill date" :error="form.errors.bill_date" />
                    <FormInput v-model="form.due_date" type="date" label="Due date" :error="form.errors.due_date" />
                    <DecimalInput v-if="can_view_costs" v-model="form.actual_bill_total" label="Actual bill total (DISCOM)" prefix="₹" :error="form.errors.actual_bill_total" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" class="sm:col-span-2 lg:col-span-3" :error="form.errors.remarks" />
                </div>
            </AppCard>
            <AppCard v-if="editing && bill.components?.length" title="Component actuals" subtitle="Enter DISCOM line amounts to compare with calculation.">
                <div v-if="can_view_costs" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">Component</th>
                                <th class="px-3 py-2 text-right font-medium">Calculated</th>
                                <th class="px-3 py-2 text-right font-medium">Actual</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(comp, index) in form.component_actuals" :key="comp.id ?? index">
                                <td class="px-3 py-2">{{ bill.components[index]?.component_name ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatCost(bill.components[index]?.calculated_amount, true) }}</td>
                                <td class="px-3 py-2">
                                    <DecimalInput v-model="comp.actual_amount" label="Actual" prefix="₹" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-slate-500">You do not have permission to view or edit bill amounts.</p>
            </AppCard>
            <p v-else-if="!editing" class="text-sm text-slate-500">Line-level actuals can be entered after the bill is calculated on save.</p>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('utilities.electricity.bills.show', bill.id) : route('utilities.electricity.bills.index')"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save bill' : 'Create bill' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
