<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ProcurementNav from '@/Components/Procurement/ProcurementNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    rfq: { type: Object, default: null },
    preselect: { type: Number, default: null },
    lines: { type: Array, required: true },
    vendors: { type: Array, required: true },
    today: { type: String, required: true },
});

const editing = computed(() => !!props.rfq);
const lineById = Object.fromEntries(props.lines.map((l) => [l.id, l]));
const toItem = (line, existing = null) => ({
    material_request_item_id: line.id,
    quantity: existing?.quantity ?? line.remaining_qty,
    required_date: existing?.required_date ?? line.required_date ?? null,
    specification: existing?.specification ?? '',
});

const initialItems = editing.value
    ? props.rfq.items.filter((i) => lineById[i.material_request_item_id]).map((i) => toItem(lineById[i.material_request_item_id], i))
    : props.preselect
      ? props.lines.filter((l) => l.material_request_id === props.preselect).map((l) => toItem(l))
      : [];

const form = useForm({
    title: props.rfq?.title ?? '',
    rfq_date: props.rfq?.rfq_date ?? props.today,
    due_date: props.rfq?.due_date ?? null,
    required_date: props.rfq?.required_date ?? null,
    terms: props.rfq?.terms ?? '',
    items: initialItems,
    vendor_ids: props.rfq?.vendor_ids ?? [],
});

// ---- Line picker -------------------------------------------------------------------------------
const filter = ref('');
const groups = computed(() => {
    const q = filter.value.trim().toLowerCase();
    const map = new Map();
    props.lines
        .filter((l) => !q || `${l.request_number} ${l.material?.name} ${l.material?.code}`.toLowerCase().includes(q))
        .forEach((l) => {
            if (!map.has(l.request_number)) {
                map.set(l.request_number, []);
            }
            map.get(l.request_number).push(l);
        });

    return [...map.entries()].map(([number, lines]) => ({ number, lines }));
});
const selectedIndex = (lineId) => form.items.findIndex((i) => i.material_request_item_id === lineId);
function toggle(line) {
    const index = selectedIndex(line.id);
    index >= 0 ? form.items.splice(index, 1) : form.items.push(toItem(line));
}
function toggleGroup(group) {
    const allSelected = group.lines.every((l) => selectedIndex(l.id) >= 0);
    group.lines.forEach((l) => {
        const index = selectedIndex(l.id);
        if (allSelected && index >= 0) {
            form.items.splice(index, 1);
        } else if (!allSelected && index < 0) {
            form.items.push(toItem(l));
        }
    });
}
const exceeds = (item) => {
    const line = lineById[item.material_request_item_id];
    try {
        return item.quantity && line && new Decimal(item.quantity).gt(new Decimal(line.remaining_qty));
    } catch {
        return false;
    }
};
const itemError = (index, field) => form.errors[`items.${index}.${field}`];

// ---- Vendors -----------------------------------------------------------------------------------
const vendorToAdd = ref(null);
const availableVendors = computed(() => props.vendors.filter((v) => !form.vendor_ids.includes(v.value)));
const vendorLabel = (id) => props.vendors.find((v) => v.value === id) ?? { label: `Vendor #${id}`, description: '' };
function addVendor(id) {
    if (id && !form.vendor_ids.includes(id)) {
        form.vendor_ids.push(id);
    }
    vendorToAdd.value = null;
}
const vendorErrors = computed(() => Object.entries(form.errors).filter(([k]) => k.startsWith('vendor_ids')).map(([, v]) => v));

function submit() {
    const transform = (d) => ({
        ...d,
        title: d.title || null,
        terms: d.terms || null,
        items: d.items.map((i) => ({ ...i, specification: i.specification || null })),
    });
    if (editing.value) {
        form.transform(transform).put(route('projects.rfqs.update', [props.project.id, props.rfq.id]));
    } else {
        form.transform(transform).post(route('projects.rfqs.store', props.project.id));
    }
}
</script>

<template>
    <ProjectLayout :project="project" active="procurement" :title="editing ? rfq.rfq_number : 'New RFQ'">
        <ProcurementNav :project-id="project.id" active="rfqs" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard :title="editing ? `Edit ${rfq.rfq_number}` : 'New request for quotation'" subtitle="The RFQ number is assigned automatically. Quantities cannot exceed what is still open on each material request line.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormInput v-model="form.title" label="Title" maxlength="200" class="sm:col-span-2 lg:col-span-4" placeholder="e.g. Cement & steel for Block A" :error="form.errors.title" />
                    <FormInput v-model="form.rfq_date" type="date" label="RFQ date" required :error="form.errors.rfq_date" />
                    <FormInput v-model="form.due_date" type="date" label="Quotes due by" :min="form.rfq_date" :error="form.errors.due_date" />
                    <FormInput v-model="form.required_date" type="date" label="Material required by" :min="form.rfq_date" :error="form.errors.required_date" />
                    <FormInput v-model="form.terms" label="Terms / instructions to vendors" multiline :rows="3" class="sm:col-span-2 lg:col-span-4" :error="form.errors.terms" />
                </div>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-5">
                <AppCard title="Approved material request lines" subtitle="Tick the lines to include" :padded="false" class="lg:col-span-2">
                    <div class="border-b border-line p-3">
                        <input v-model="filter" type="search" placeholder="Filter by request or material…" class="w-full rounded-lg border-line text-sm" />
                    </div>
                    <EmptyState v-if="!lines.length" icon="document" title="Nothing to procure" description="There are no approved material request lines with open quantity in this project." />
                    <div v-else class="max-h-[28rem] overflow-y-auto">
                        <section v-for="group in groups" :key="group.number">
                            <button type="button" class="flex w-full items-center justify-between bg-slate-50 px-4 py-2 text-left text-xs font-semibold text-slate-700" @click="toggleGroup(group)">
                                <span class="font-mono">{{ group.number }}</span>
                                <span class="font-normal text-brand-700">{{ group.lines.every((l) => selectedIndex(l.id) >= 0) ? 'Clear' : 'Select all' }}</span>
                            </button>
                            <label v-for="line in group.lines" :key="line.id" class="flex cursor-pointer items-start gap-3 border-b border-line px-4 py-2.5 hover:bg-slate-50">
                                <input type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600" :checked="selectedIndex(line.id) >= 0" @change="toggle(line)" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-slate-900">{{ line.material?.name }}</span>
                                    <span class="block text-xs text-slate-500">Open {{ formatQty(line.remaining_qty) }} of {{ formatQty(line.quantity) }} {{ line.unit }}</span>
                                </span>
                            </label>
                        </section>
                    </div>
                </AppCard>

                <AppCard title="RFQ items" :subtitle="`${form.items.length} selected`" :padded="false" class="lg:col-span-3">
                    <p v-if="form.errors.items" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items }}</p>
                    <EmptyState v-if="!form.items.length" icon="cube" title="No items selected" description="Tick material request lines on the left to add them." />
                    <ol v-else class="divide-y divide-line">
                        <li v-for="(item, index) in form.items" :key="item.material_request_item_id" class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-900">{{ lineById[item.material_request_item_id]?.material?.name }}</p>
                                    <p class="text-xs text-slate-500">
                                        <span class="font-mono">{{ lineById[item.material_request_item_id]?.request_number }}</span> · open
                                        {{ formatQty(lineById[item.material_request_item_id]?.remaining_qty) }} {{ lineById[item.material_request_item_id]?.unit }}
                                    </p>
                                </div>
                                <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove item" @click="form.items.splice(index, 1)">
                                    <Icon name="trash" :size="16" />
                                </button>
                            </div>
                            <div class="mt-2 grid gap-3 sm:grid-cols-6">
                                <DecimalInput
                                    v-model="item.quantity"
                                    label="Quantity"
                                    required
                                    :decimals="4"
                                    :suffix="lineById[item.material_request_item_id]?.unit"
                                    class="sm:col-span-2"
                                    :error="itemError(index, 'quantity') || (exceeds(item) ? 'More than the open quantity.' : null)"
                                />
                                <FormInput v-model="item.required_date" type="date" label="Required by" class="sm:col-span-2" :error="itemError(index, 'required_date')" />
                                <FormInput v-model="item.specification" label="Specification" maxlength="2000" class="sm:col-span-2" :error="itemError(index, 'specification') || itemError(index, 'material_request_item_id')" />
                            </div>
                        </li>
                    </ol>
                </AppCard>
            </div>

            <AppCard title="Vendors to invite" subtitle="Only active vendors can be invited. You can also change vendors later, until quotations arrive.">
                <div class="space-y-3">
                    <SearchSelect :model-value="vendorToAdd" label="Add vendor" placeholder="Search vendors…" :options="availableVendors" @update:model-value="addVendor" />
                    <p v-for="(e, i) in vendorErrors" :key="i" class="text-sm text-red-600">{{ e }}</p>
                    <ul v-if="form.vendor_ids.length" class="flex flex-wrap gap-2">
                        <li v-for="id in form.vendor_ids" :key="id" class="inline-flex items-center gap-2 rounded-full border border-line bg-slate-50 py-1 pr-1 pl-3 text-sm">
                            <span>{{ vendorLabel(id).label }}</span>
                            <span class="hidden text-xs text-slate-500 sm:inline">{{ vendorLabel(id).description }}</span>
                            <button type="button" class="rounded-full p-1 text-slate-400 hover:bg-white hover:text-red-600" :aria-label="`Remove ${vendorLabel(id).label}`" @click="form.vendor_ids.splice(form.vendor_ids.indexOf(id), 1)">
                                <Icon name="close" :size="14" />
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-500">No vendors selected yet.</p>
                </div>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('projects.rfqs.show', [project.id, rfq.id]) : route('projects.rfqs.index', project.id)"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" :disabled="!form.items.length" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
