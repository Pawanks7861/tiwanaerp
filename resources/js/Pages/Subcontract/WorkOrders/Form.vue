<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import SubcontractNav from '@/Components/Subcontract/SubcontractNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatPercent } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    order: { type: Object, default: null },
    options: { type: Object, required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.order);
let tempId = 0;
const form = useForm({
    subcontractor_id: props.order?.subcontractor_id ?? null,
    wo_date: props.order?.wo_date ?? props.today,
    scope: props.order?.scope ?? '',
    start_date: props.order?.start_date ?? null,
    end_date: props.order?.end_date ?? null,
    retention_percent: props.order?.retention_percent ?? null,
    advance_amount: props.order?.advance_amount ?? null,
    tax_rate_id: props.order?.tax_rate_id ?? null,
    tds_percent: props.order?.tds_percent ?? null,
    terms: props.order?.terms ?? '',
    items: (props.order?.items ?? []).map((i) => ({ _key: `i${++tempId}`, ...i })),
    milestones: (props.order?.milestones ?? []).map((m) => ({ _key: `m${++tempId}`, ...m })),
});

const boqById = Object.fromEntries(props.options.boq_items.map((b) => [b.value, b]));
function addItem() {
    form.items.push({ _key: `i${++tempId}`, boq_item_id: null, task_id: null, description: '', unit_id: null, quantity: null, rate: null });
}
function onBoq(item, id) {
    item.boq_item_id = id;
    const boq = boqById[id];
    if (boq) {
        item.unit_id = item.unit_id ?? boq.unit_id;
        item.description = item.description || boq.label;
    }
}
function addMilestone() {
    form.milestones.push({ _key: `m${++tempId}`, name: '', due_date: null, amount_percent: null });
}

const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};
/** Preview only: the server recalculates every amount on save. */
const lineAmount = (i) => dec(i.quantity).times(dec(i.rate)).toDecimalPlaces(2, Decimal.ROUND_HALF_UP);
const subtotal = computed(() => form.items.reduce((s, i) => s.plus(lineAmount(i)), new Decimal(0)));
const taxPercent = computed(() => props.options.tax_rates.find((t) => t.value === form.tax_rate_id)?.rate ?? '0');
const tax = computed(() => subtotal.value.times(dec(taxPercent.value)).dividedBy(100).toDecimalPlaces(2, Decimal.ROUND_HALF_UP));
const total = computed(() => subtotal.value.plus(tax.value));
const milestoneTotal = computed(() => form.milestones.reduce((s, m) => s.plus(dec(m.amount_percent)), new Decimal(0)));
const err = (prefix, index, field) => form.errors[`${prefix}.${index}.${field}`];

function submit() {
    const transform = (d) => ({
        ...d,
        scope: d.scope || null,
        terms: d.terms || null,
        items: d.items.map(({ _key, ...i }) => ({ ...i, description: i.description || null })),
        milestones: d.milestones.map(({ _key, ...m }) => m),
    });
    editing.value
        ? form.transform(transform).put(route('projects.work-orders.update', [props.project.id, props.order.id]))
        : form.transform(transform).post(route('projects.work-orders.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="subcontract" :title="editing ? order.wo_number : 'New work order'">
        <SubcontractNav :project-id="project.id" active="work-orders" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :padded="false">
                <div class="p-4 sm:p-5">
                    <Link :href="route('projects.work-orders.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Work orders</Link>
                    <h2 class="text-lg font-semibold text-slate-900">{{ editing ? `Edit ${order.wo_number}` : 'New work order' }}</h2>
                    <p class="text-xs text-slate-500">Items link to the current approved BOQ so certified work is costed against the right BOQ line.</p>
                </div>
                <p v-if="form.errors.work_order" class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-5">{{ form.errors.work_order }}</p>
                <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-4">
                    <SearchSelect v-model="form.subcontractor_id" label="Subcontractor" required :options="options.subcontractors" class="sm:col-span-2" :error="form.errors.subcontractor_id" />
                    <FormInput v-model="form.wo_date" type="date" label="WO date" required :error="form.errors.wo_date" />
                    <div />
                    <FormInput v-model="form.start_date" type="date" label="Start" :error="form.errors.start_date" />
                    <FormInput v-model="form.end_date" type="date" label="End" :min="form.start_date" :error="form.errors.end_date" />
                    <FormSelect v-model="form.tax_rate_id" label="GST" placeholder="No GST" :options="options.tax_rates" :error="form.errors.tax_rate_id" />
                    <DecimalInput v-model="form.tds_percent" label="TDS" suffix="%" :decimals="4" :error="form.errors.tds_percent" />
                    <DecimalInput v-model="form.retention_percent" label="Retention" suffix="%" :decimals="4" :error="form.errors.retention_percent" />
                    <DecimalInput v-model="form.advance_amount" label="Mobilisation advance" prefix="₹" :error="form.errors.advance_amount" />
                    <FormInput v-model="form.scope" label="Scope of work" multiline :rows="3" maxlength="5000" class="sm:col-span-2 lg:col-span-4" :error="form.errors.scope" />
                </div>
            </AppCard>

            <AppCard title="Items" :subtitle="`${form.items.length} item(s) · amounts are a preview`" :padded="false">
                <template #actions><AppButton size="sm" variant="secondary" icon="plus" @click="addItem">Add item</AppButton></template>
                <p v-if="form.errors.items" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items }}</p>
                <EmptyState v-if="!form.items.length" icon="clipboard" title="No items" description="Add the work items this subcontractor will execute." />
                <ol v-else class="divide-y divide-line">
                    <li v-for="(item, index) in form.items" :key="item._key" class="p-4">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-6 lg:grid-cols-12">
                            <SearchSelect :model-value="item.boq_item_id" label="BOQ item" :options="options.boq_items" placeholder="Not linked" class="col-span-2 sm:col-span-3 lg:col-span-4" :error="err('items', index, 'boq_item_id')" @update:model-value="(v) => onBoq(item, v)" />
                            <FormSelect v-model="item.task_id" label="Task" placeholder="No task" :options="options.tasks" class="col-span-2 sm:col-span-3 lg:col-span-3" :error="err('items', index, 'task_id')" />
                            <FormInput v-model="item.description" label="Description" maxlength="500" class="col-span-2 sm:col-span-6 lg:col-span-5" :error="err('items', index, 'description')" />
                            <FormSelect v-model="item.unit_id" label="Unit" :options="options.units" class="lg:col-span-2" :error="err('items', index, 'unit_id')" />
                            <DecimalInput v-model="item.quantity" label="Quantity" required :decimals="4" class="sm:col-span-2 lg:col-span-3" :error="err('items', index, 'quantity')" />
                            <DecimalInput v-model="item.rate" label="Rate" required :decimals="4" prefix="₹" class="sm:col-span-2 lg:col-span-3" :error="err('items', index, 'rate')" />
                            <div class="col-span-2 flex items-end justify-between gap-2 sm:col-span-6 lg:col-span-4">
                                <p class="text-sm text-slate-600">Amount <span class="font-semibold text-slate-900 tabular">{{ formatMoney(lineAmount(item).toFixed(2)) }}</span></p>
                                <button type="button" class="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove item" @click="form.items.splice(index, 1)">
                                    <Icon name="trash" :size="16" />
                                </button>
                            </div>
                        </div>
                    </li>
                </ol>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Payment milestones" :subtitle="`Total ${formatPercent(milestoneTotal.toString())} of 100%`" :padded="false">
                    <template #actions><AppButton size="sm" variant="secondary" icon="plus" @click="addMilestone">Add</AppButton></template>
                    <p v-if="form.errors.milestones" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.milestones }}</p>
                    <p v-if="!form.milestones.length" class="px-4 py-6 text-center text-sm text-slate-500">Optional. Milestones are tracked for information; billing is by certified quantity.</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="(m, index) in form.milestones" :key="m._key" class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-6">
                            <FormInput v-model="m.name" label="Milestone" required maxlength="200" class="col-span-2 sm:col-span-3" :error="err('milestones', index, 'name')" />
                            <FormInput v-model="m.due_date" type="date" label="Due" class="sm:col-span-1" :error="err('milestones', index, 'due_date')" />
                            <div class="flex items-end gap-2 sm:col-span-2">
                                <DecimalInput v-model="m.amount_percent" label="Share" required suffix="%" :decimals="4" class="flex-1" :error="err('milestones', index, 'amount_percent')" />
                                <button type="button" class="mb-1 rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove milestone" @click="form.milestones.splice(index, 1)"><Icon name="trash" :size="16" /></button>
                            </div>
                        </li>
                    </ul>
                </AppCard>
                <AppCard title="Totals" subtitle="Preview; the server recalculates on save.">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Sub total</dt><dd class="tabular">{{ formatMoney(subtotal.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">GST {{ formatPercent(taxPercent) }}</dt><dd class="tabular">{{ formatMoney(tax.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Work order value</dt><dd class="tabular">{{ formatMoney(total.toFixed(2)) }}</dd></div>
                    </dl>
                    <FormInput v-model="form.terms" label="Terms & conditions" multiline :rows="4" maxlength="5000" class="mt-4" :error="form.errors.terms" />
                </AppCard>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.work-orders.show', [project.id, order.id]) : route('projects.work-orders.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!form.items.length" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
