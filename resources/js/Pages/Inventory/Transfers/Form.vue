<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
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
    transfer: { type: Object, default: null },
    options: { type: Object, required: true },
    stock: { type: [Object, Array], required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.transfer);
let tempId = 0;
const blankLine = () => ({ _key: `n${++tempId}`, material_id: null, quantity: null, remarks: '' });

const form = useForm({
    transfer_date: props.transfer?.transfer_date ?? props.today,
    from_warehouse_id: props.transfer?.from_warehouse_id ?? null,
    to_warehouse_id: props.transfer?.to_warehouse_id ?? null,
    vehicle_no: props.transfer?.vehicle_no ?? '',
    remarks: props.transfer?.remarks ?? '',
    items: props.transfer?.items?.length ? props.transfer.items.map((i, n) => ({ _key: `i${n}`, ...i, remarks: i.remarks ?? '' })) : [blankLine()],
});

const available = (materialId) => props.stock?.[form.from_warehouse_id]?.[materialId] ?? '0';
const unitOf = (materialId) => props.options.materials.find((m) => m.value === materialId)?.unit ?? '';
const short = (line) => {
    try {
        return line.material_id && line.quantity && new Decimal(line.quantity).gt(new Decimal(available(line.material_id)));
    } catch {
        return false;
    }
};
const destinations = computed(() => props.options.warehouses.filter((w) => w.value !== form.from_warehouse_id));
const lineError = (index, field) => form.errors[`items.${index}.${field}`];

function submit() {
    const transform = (d) => ({
        ...d,
        vehicle_no: d.vehicle_no || null,
        remarks: d.remarks || null,
        items: d.items.map(({ _key, ...l }) => ({ ...l, remarks: l.remarks || null })),
    });
    editing.value
        ? form.transform(transform).put(route('projects.stock-transfers.update', [props.project.id, props.transfer.id]))
        : form.transform(transform).post(route('projects.stock-transfers.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="inventory" :title="editing ? transfer.transfer_number : 'New stock transfer'">
        <InventoryNav :project-id="project.id" active="transfers" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :title="editing ? `Edit ${transfer.transfer_number}` : 'New stock transfer'" subtitle="Saved as a draft. Stock leaves the source store when you dispatch it.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SearchSelect v-model="form.from_warehouse_id" label="From store" required :options="options.warehouses" :error="form.errors.from_warehouse_id" />
                    <SearchSelect v-model="form.to_warehouse_id" label="To store" required :options="destinations" :error="form.errors.to_warehouse_id" />
                    <FormInput v-model="form.transfer_date" type="date" label="Transfer date" required :max="today" :error="form.errors.transfer_date" />
                    <FormInput v-model="form.vehicle_no" label="Vehicle no." uppercase maxlength="30" :error="form.errors.vehicle_no" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="2000" class="sm:col-span-2 lg:col-span-4" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard title="Items" :subtitle="`${form.items.length} line(s)`" :padded="false">
                <template #actions>
                    <AppButton size="sm" variant="secondary" icon="plus" @click="form.items.push(blankLine())">Add line</AppButton>
                </template>
                <p v-if="form.errors.items" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items }}</p>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.items" :key="line._key" class="p-4">
                        <div class="grid items-start gap-3 sm:grid-cols-12">
                            <SearchSelect v-model="line.material_id" label="Item" required :options="options.materials" class="sm:col-span-5" :error="lineError(index, 'material_id')" />
                            <DecimalInput
                                v-model="line.quantity"
                                label="Quantity"
                                required
                                :decimals="4"
                                :suffix="unitOf(line.material_id)"
                                class="sm:col-span-3"
                                :help="line.material_id && form.from_warehouse_id ? `In source: ${formatQty(available(line.material_id))}` : null"
                                :error="lineError(index, 'quantity')"
                            />
                            <FormInput v-model="line.remarks" label="Remarks" maxlength="500" class="sm:col-span-3" :error="lineError(index, 'remarks')" />
                            <div class="flex justify-end sm:col-span-1 sm:pt-7">
                                <button v-if="form.items.length > 1" type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove line ${index + 1}`" @click="form.items.splice(index, 1)">
                                    <Icon name="trash" :size="16" />
                                </button>
                            </div>
                        </div>
                        <p v-if="short(line)" class="mt-1.5 flex items-center gap-1 text-xs text-amber-700"><Icon name="warning" :size="14" />More than the source store holds; dispatch will be refused.</p>
                    </li>
                </ol>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.stock-transfers.show', [project.id, transfer.id]) : route('projects.stock-transfers.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
