<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    adjustment: { type: Object, default: null },
    options: { type: Object, required: true },
    stock: { type: [Object, Array], required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.adjustment);
let tempId = 0;
const blankLine = () => ({ _key: `n${++tempId}`, material_id: null, physical_qty: null, unit_cost: null, remarks: '' });

const form = useForm({
    warehouse_id: props.adjustment?.warehouse_id ?? null,
    adjustment_date: props.adjustment?.adjustment_date ?? props.today,
    reason: props.adjustment?.reason ?? 'count_correction',
    remarks: props.adjustment?.remarks ?? '',
    items: props.adjustment?.items?.length ? props.adjustment.items.map((i, n) => ({ _key: `i${n}`, ...i, remarks: i.remarks ?? '' })) : [blankLine()],
});

const D = (v) => {
    try {
        return new Decimal(v === null || v === undefined || v === '' ? 0 : String(v));
    } catch {
        return new Decimal(0);
    }
};
const book = (materialId) => props.stock?.[form.warehouse_id]?.[materialId] ?? '0';
const unitOf = (materialId) => props.options.materials.find((m) => m.value === materialId)?.unit ?? '';
const difference = (line) => (line.material_id && line.physical_qty !== null ? D(line.physical_qty).minus(D(book(line.material_id))) : null);
const needsCost = (line) => {
    const diff = difference(line);

    return form.reason === 'opening' || (diff?.isPositive() && D(book(line.material_id)).isZero());
};
const lossOnly = computed(() => ['damage', 'theft'].includes(form.reason));
const lineError = (index, field) => form.errors[`items.${index}.${field}`];
const generalErrors = computed(() => ['items'].map((k) => form.errors[k]).filter(Boolean));

function submit() {
    const transform = (d) => ({
        ...d,
        items: d.items.map(({ _key, ...l }) => ({ ...l, unit_cost: l.unit_cost || null, remarks: l.remarks || null })),
    });
    editing.value
        ? form.transform(transform).put(route('projects.stock-adjustments.update', [props.project.id, props.adjustment.id]))
        : form.transform(transform).post(route('projects.stock-adjustments.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="inventory" :title="editing ? adjustment.adjustment_number : 'New stock adjustment'">
        <InventoryNav :project-id="project.id" active="adjustments" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :title="editing ? `Edit ${adjustment.adjustment_number}` : 'New stock adjustment'" subtitle="Enter the physical quantity; the system records the book quantity now and posts the difference on approval.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SearchSelect v-model="form.warehouse_id" label="Store" required :options="options.warehouses" class="sm:col-span-2" :error="form.errors.warehouse_id" />
                    <FormSelect v-model="form.reason" label="Reason" required :options="options.reasons" :error="form.errors.reason" />
                    <FormInput v-model="form.adjustment_date" type="date" label="Date" required :max="today" :error="form.errors.adjustment_date" />
                    <FormInput v-model="form.remarks" label="Explanation" required multiline :rows="2" maxlength="2000" class="sm:col-span-2 lg:col-span-4" :error="form.errors.remarks" />
                </div>
                <p v-if="form.reason === 'opening'" class="mt-3 text-xs text-slate-600"><Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />Opening stock is only for items that have never moved in this store, and needs a unit cost.</p>
                <p v-else-if="lossOnly" class="mt-3 text-xs text-slate-600"><Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ form.reason === 'damage' ? 'Damage' : 'Theft' }} can only reduce stock. Losses are valued at the weighted average.</p>
            </AppCard>

            <AppCard title="Counted items" :subtitle="`${form.items.length} line(s)`" :padded="false">
                <template #actions>
                    <AppButton size="sm" variant="secondary" icon="plus" @click="form.items.push(blankLine())">Add line</AppButton>
                </template>
                <div v-if="generalErrors.length" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                    <p v-for="(e, i) in generalErrors" :key="i">{{ e }}</p>
                </div>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.items" :key="line._key" class="p-4">
                        <div class="grid items-start gap-3 sm:grid-cols-12">
                            <SearchSelect v-model="line.material_id" label="Item" required :options="options.materials" class="sm:col-span-4" :error="lineError(index, 'material_id')" />
                            <div class="sm:col-span-2">
                                <span class="mb-1 block text-sm font-medium text-slate-700">Book qty</span>
                                <p class="py-2 text-right text-sm text-slate-600 tabular">{{ line.material_id && form.warehouse_id ? formatQty(book(line.material_id)) : '—' }}</p>
                            </div>
                            <DecimalInput v-model="line.physical_qty" label="Physical qty" required :decimals="4" :suffix="unitOf(line.material_id)" class="sm:col-span-2" :error="lineError(index, 'physical_qty')" />
                            <div class="sm:col-span-1">
                                <span class="mb-1 block text-sm font-medium text-slate-700">Diff.</span>
                                <p class="py-2 text-right text-sm font-semibold tabular" :class="difference(line)?.isNegative() ? 'text-red-700' : difference(line)?.isPositive() ? 'text-emerald-700' : 'text-slate-400'">
                                    {{ difference(line) === null ? '—' : (difference(line).isPositive() ? '+' : '') + formatQty(difference(line).toString()) }}
                                </p>
                            </div>
                            <DecimalInput v-if="needsCost(line)" v-model="line.unit_cost" label="Unit cost" required prefix="₹" :decimals="4" class="sm:col-span-2" :error="lineError(index, 'unit_cost')" />
                            <div v-else class="sm:col-span-2" />
                            <div class="flex justify-end sm:col-span-1 sm:pt-7">
                                <button v-if="form.items.length > 1" type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove line ${index + 1}`" @click="form.items.splice(index, 1)">
                                    <Icon name="trash" :size="16" />
                                </button>
                            </div>
                            <FormInput v-model="line.remarks" label="Remarks" maxlength="500" class="sm:col-span-12" :error="lineError(index, 'remarks')" />
                        </div>
                        <p v-if="lossOnly && difference(line)?.isPositive()" class="mt-1.5 flex items-center gap-1 text-xs text-amber-700"><Icon name="warning" :size="14" />This reason cannot add stock.</p>
                    </li>
                </ol>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.stock-adjustments.show', [project.id, adjustment.id]) : route('projects.stock-adjustments.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
