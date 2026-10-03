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
import { computed, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    type: { type: String, required: true },
    type_label: { type: String, required: true },
    return: { type: Object, default: null },
    options: { type: Object, required: true },
    stock: { type: [Object, Array], required: true },
    today: { type: String, required: true },
});

const doc = computed(() => props.return);
const editing = computed(() => !!doc.value);
const site = props.type === 'site_to_store';
let tempId = 0;
const blankLine = () => ({ _key: `n${++tempId}`, material_issue_item_id: null, grn_item_id: null, material_id: null, quantity: null, remarks: '' });

const form = useForm({
    return_type: props.type,
    return_date: doc.value?.return_date ?? props.today,
    warehouse_id: doc.value?.warehouse_id ?? null,
    grn_id: doc.value?.grn_id ?? null,
    vendor_id: doc.value?.vendor_id ?? null,
    reason: doc.value?.reason ?? '',
    remarks: doc.value?.remarks ?? '',
    items: doc.value?.items?.length ? doc.value.items.map((i, n) => ({ _key: `i${n}`, ...i, remarks: i.remarks ?? '' })) : [blankLine()],
});

const grn = computed(() => props.options.grns.find((g) => g.value === form.grn_id) ?? null);
const grnLines = computed(() => (grn.value?.items ?? []).map((i) => ({ value: i.grn_item_id, label: i.material, description: `Accepted ${formatQty(i.accepted_qty)} ${i.unit ?? ''} · returnable ${formatQty(i.returnable_qty)}` })));
const issueLine = (id) => props.options.issue_lines.find((l) => l.value === id);
const grnLine = (id) => grn.value?.items.find((i) => i.grn_item_id === id);
const unitOf = (line) =>
    site ? issueLine(line.material_issue_item_id)?.unit : form.grn_id ? grnLine(line.grn_item_id)?.unit : props.options.materials.find((m) => m.value === line.material_id)?.unit;
const hint = (line) => {
    if (site && line.material_issue_item_id) {
        return `Returnable ${formatQty(issueLine(line.material_issue_item_id)?.returnable_qty ?? '0')}`;
    }
    const materialId = form.grn_id ? grnLine(line.grn_item_id)?.material_id : line.material_id;

    return !site && materialId && form.warehouse_id ? `In store: ${formatQty(props.stock?.[form.warehouse_id]?.[materialId] ?? '0')}` : null;
};

watch(
    () => form.grn_id,
    (id, old) => {
        if (id && old !== undefined && id !== old) {
            form.items = [blankLine()];
            form.warehouse_id = grn.value?.warehouse_id ?? form.warehouse_id;
        }
    },
);

const lineError = (index, field) => form.errors[`items.${index}.${field}`];
const generalErrors = computed(() => ['items'].map((k) => form.errors[k]).filter(Boolean));

function submit() {
    const transform = (d) => ({
        ...d,
        grn_id: site ? undefined : d.grn_id,
        vendor_id: site || d.grn_id ? undefined : d.vendor_id,
        remarks: d.remarks || null,
        items: d.items.map(({ _key, ...l }) =>
            site
                ? { material_issue_item_id: l.material_issue_item_id, quantity: l.quantity, remarks: l.remarks || null }
                : { grn_item_id: d.grn_id ? l.grn_item_id : null, material_id: d.grn_id ? null : l.material_id, quantity: l.quantity, remarks: l.remarks || null },
        ),
    });
    editing.value
        ? form.transform(transform).put(route('projects.material-returns.update', [props.project.id, doc.value.id]))
        : form.transform(transform).post(route('projects.material-returns.store', props.project.id));
}
</script>

<template>
    <ProjectLayout :project="project" active="inventory" :title="editing ? doc.return_number : `New ${type_label.toLowerCase()}`">
        <InventoryNav :project-id="project.id" active="returns" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard
                :title="editing ? `Edit ${doc.return_number}` : `New ${type_label.toLowerCase()}`"
                :subtitle="site ? 'Material comes back into the store at its original issue cost and the project cost is credited.' : 'Goods leave the store at the weighted average cost. Debit notes are not raised here.'"
            >
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <template v-if="!site">
                        <SearchSelect v-model="form.grn_id" label="Against GRN" :options="options.grns" class="sm:col-span-2" help="Optional. Limits each line to the quantity accepted on the GRN." :error="form.errors.grn_id" />
                        <SearchSelect v-if="!form.grn_id" v-model="form.vendor_id" label="Vendor" required :options="options.vendors" class="sm:col-span-2" :error="form.errors.vendor_id" />
                        <div v-else class="sm:col-span-2">
                            <span class="mb-1 block text-sm font-medium text-slate-700">Vendor</span>
                            <p class="py-2 text-sm text-slate-900">{{ grn?.description }}</p>
                        </div>
                    </template>
                    <SearchSelect v-model="form.warehouse_id" :label="site ? 'Return into store' : 'Return from store'" required :options="options.warehouses" class="sm:col-span-2" :error="form.errors.warehouse_id" />
                    <FormInput v-model="form.return_date" type="date" label="Return date" required :max="today" :error="form.errors.return_date" />
                    <FormInput v-model="form.reason" label="Reason" required maxlength="500" :error="form.errors.reason" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="2000" class="sm:col-span-2 lg:col-span-4" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard title="Items" :subtitle="`${form.items.length} line(s)`" :padded="false">
                <template #actions>
                    <AppButton size="sm" variant="secondary" icon="plus" @click="form.items.push(blankLine())">Add line</AppButton>
                </template>
                <div v-if="generalErrors.length" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                    <p v-for="(e, i) in generalErrors" :key="i">{{ e }}</p>
                </div>
                <p v-if="site && !options.issue_lines.length" class="border-b border-line bg-slate-50 px-4 py-2 text-xs text-slate-600">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />No posted issue of this project has quantity left to return.
                </p>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.items" :key="line._key" class="p-4">
                        <div class="grid items-start gap-3 sm:grid-cols-12">
                            <SearchSelect v-if="site" v-model="line.material_issue_item_id" label="Issue line" required :options="options.issue_lines" class="sm:col-span-5" :error="lineError(index, 'material_issue_item_id')" />
                            <SearchSelect v-else-if="form.grn_id" v-model="line.grn_item_id" label="GRN line" required :options="grnLines" class="sm:col-span-5" :error="lineError(index, 'grn_item_id')" />
                            <SearchSelect v-else v-model="line.material_id" label="Item" required :options="options.materials" class="sm:col-span-5" :error="lineError(index, 'material_id')" />
                            <DecimalInput v-model="line.quantity" label="Quantity" required :decimals="4" :suffix="unitOf(line) ?? ''" class="sm:col-span-3" :help="hint(line)" :error="lineError(index, 'quantity')" />
                            <FormInput v-model="line.remarks" label="Remarks" maxlength="500" class="sm:col-span-3" :error="lineError(index, 'remarks')" />
                            <div class="flex justify-end sm:col-span-1 sm:pt-7">
                                <button v-if="form.items.length > 1" type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove line ${index + 1}`" @click="form.items.splice(index, 1)">
                                    <Icon name="trash" :size="16" />
                                </button>
                            </div>
                        </div>
                    </li>
                </ol>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.material-returns.show', [project.id, doc.id]) : route('projects.material-returns.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
