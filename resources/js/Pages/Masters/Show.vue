<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import CellValue from '@/Components/Data/CellValue.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import MasterForm from '@/Components/Masters/MasterForm.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef } from 'vue';

const props = defineProps({
    definition: { type: Object, required: true },
    record: { type: Object, required: true },
    options: { type: Object, required: true },
    attachments: { type: Array, required: true },
    attachableType: { type: String, required: true },
    can: { type: Object, required: true },
    locationHistory: { type: Array, default: () => [] },
    locationTypes: { type: Array, default: () => [] },
    quantityMovements: { type: Array, default: () => [] },
    quantityMovementTypes: { type: Array, default: () => [] },
});

const title = computed(() => props.record[props.definition.nameColumn] ?? props.record.code);

const details = computed(() =>
    props.definition.fields
        .filter((f) => f.type !== 'switch' && f.name !== props.definition.nameColumn)
        .map((f) => {
            let value = props.record[f.name];
            if (f.type === 'select' && value !== null && value !== undefined) {
                value = (props.options[f.options] ?? []).find((o) => o.value === value)?.label ?? value;
            }

            return { ...f, value, displayType: { money: 'money', rate: 'rate', qty: 'qty', percent: 'percent' }[f.type] ?? 'text' };
        }),
);

const drawerOpen = ref(false);
const form = shallowRef(useForm({}));

function openEdit() {
    const values = {};
    for (const field of props.definition.fields) {
        const value = props.record[field.name];
        values[field.name] = field.type === 'switch' ? !!value : (value ?? null);
    }
    form.value = useForm(values);
    drawerOpen.value = true;
}

const locationOpen = ref(false);
const locationForm = useForm({
    location_type: props.record.location_type ?? 'central_store',
    location_project_id: props.record.location_project_id ?? null,
    location_name: props.record.location_name ?? '',
    location_notes: props.record.location_notes ?? '',
    remarks: '',
});
function saveLocation() {
    locationForm.post(route('equipment.location', props.record.id), {
        preserveScroll: true,
        onSuccess: () => (locationOpen.value = false),
    });
}

const qtyOpen = ref(false);
const qtyForm = useForm({
    moved_at: new Date().toISOString().slice(0, 10),
    movement_type: 'add',
    quantity: '',
    reason: '',
    remarks: '',
});
function saveQty() {
    qtyForm.post(route('equipment.quantity-movements.store', props.record.id), {
        preserveScroll: true,
        onSuccess: () => (qtyOpen.value = false),
    });
}

function submit() {
    form.value.put(route('masters.update', [props.definition.slug, props.record.id]), {
        preserveScroll: true,
        onSuccess: () => (drawerOpen.value = false),
    });
}
</script>

<template>
    <AppLayout :title="title">
        <PageHeader :title="title" :subtitle="`${definition.singular}${record.code ? ' · ' + record.code : ''}`" :back="route('masters.index', definition.slug)">
            <template #actions>
                <StatusBadge :status="!!record.is_active" />
                <AppButton v-if="can.update && definition.slug === 'equipment'" variant="secondary" @click="locationOpen = true">Change location</AppButton>
                <AppButton v-if="can.update" variant="secondary" icon="pencil" @click="openEdit">Edit</AppButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <AppCard title="Details" class="lg:col-span-2">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div v-for="item in details" :key="item.name" :class="item.span === 'full' || item.type === 'textarea' ? 'sm:col-span-2' : ''">
                        <dt class="text-xs font-medium text-slate-500 uppercase">{{ item.label }}</dt>
                        <dd class="mt-0.5 text-sm break-words whitespace-pre-line text-slate-800">
                            <CellValue :type="item.displayType" :value="item.value" />
                        </dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard v-if="definition.slug === 'equipment'" title="Location" class="lg:col-span-2">
                <p class="text-sm text-slate-800">{{ record.location_name || 'Current location is stored on the equipment.' }}</p>
                <ul v-if="locationHistory.length" class="mt-3 divide-y divide-line text-sm">
                    <li v-for="row in locationHistory" :key="row.id" class="py-2">
                        <p>{{ row.from || '—' }} → {{ row.to }}</p>
                        <p class="text-xs text-slate-500">{{ formatDateTime(row.changed_at) }}<template v-if="row.changed_by"> · {{ row.changed_by }}</template><template v-if="row.remarks"> · {{ row.remarks }}</template></p>
                    </li>
                </ul>
                <p v-else class="mt-2 text-xs text-slate-500">No location changes yet.</p>
            </AppCard>

            <AppCard v-if="definition.slug === 'equipment' && record.tracking_mode === 'quantity'" title="Quantity movements" class="lg:col-span-2">
                <template #actions>
                    <AppButton v-if="can.update" size="sm" @click="qtyOpen = true">Add / remove qty</AppButton>
                </template>
                <p class="text-sm text-slate-700">Available: <span class="font-semibold tabular">{{ formatQty(record.available_quantity) }}</span> · Opening: <span class="tabular">{{ formatQty(record.opening_quantity) }}</span></p>
                <ul v-if="quantityMovements.length" class="mt-3 divide-y divide-line text-sm">
                    <li v-for="row in quantityMovements" :key="row.id" class="flex justify-between gap-3 py-2">
                        <div>
                            <p class="font-medium text-slate-900">{{ row.movement_type_label }} · {{ formatQty(row.quantity) }}</p>
                            <p class="text-xs text-slate-500">{{ formatDate(row.moved_at) }}<template v-if="row.created_by"> · {{ row.created_by }}</template><template v-if="row.reason"> · {{ row.reason }}</template></p>
                        </div>
                    </li>
                </ul>
                <p v-else class="mt-2 text-xs text-slate-500">No quantity movements yet. Individual assets use location/status instead of quantity.</p>
            </AppCard>

            <AttachmentPanel
                :key="`${attachableType}-${record.id}`"
                :attachments="attachments"
                :attachable-type="attachableType"
                :attachable-id="record.id"
                :can-upload="can.update"
                :can-delete="can.update"
                :placeholder="definition.slug === 'equipment' ? 'RC, insurance, pollution, invoice, warranty, photo…' : 'GST certificate, PAN, cancelled cheque…'"
            />
        </div>

        <AppDrawer :show="drawerOpen" :title="`Edit ${definition.singular.toLowerCase()}`" @close="drawerOpen = false">
            <form id="master-show-form" @submit.prevent="submit">
                <MasterForm :definition="definition" :form="form" :options="options" editing />
            </form>
            <template #footer>
                <AppButton variant="secondary" class="flex-1 sm:flex-none" @click="drawerOpen = false">Cancel</AppButton>
                <AppButton type="submit" form="master-show-form" class="flex-1 sm:flex-none" :loading="form.processing">Save changes</AppButton>
            </template>
        </AppDrawer>

        <AppDrawer :show="locationOpen" title="Change location" @close="locationOpen = false">
            <div class="space-y-4">
                <FormSelect v-model="locationForm.location_type" label="Location type" required :options="locationTypes" :error="locationForm.errors.location_type" />
                <SearchSelect v-if="locationForm.location_type === 'project_site'" v-model="locationForm.location_project_id" label="Project" required :options="options.projects || []" :error="locationForm.errors.location_project_id" />
                <FormInput v-model="locationForm.location_name" label="Location name" maxlength="150" :error="locationForm.errors.location_name" />
                <FormInput v-model="locationForm.location_notes" label="Notes" multiline :rows="2" :error="locationForm.errors.location_notes" />
                <FormInput v-model="locationForm.remarks" label="Reason" maxlength="500" :error="locationForm.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="locationOpen = false">Cancel</AppButton>
                <AppButton :loading="locationForm.processing" @click="saveLocation">Save location</AppButton>
            </template>
        </AppDrawer>

        <AppDrawer :show="qtyOpen" title="Equipment quantity movement" @close="qtyOpen = false">
            <div class="space-y-4">
                <FormInput v-model="qtyForm.moved_at" type="date" label="Date" required :error="qtyForm.errors.moved_at" />
                <FormSelect v-model="qtyForm.movement_type" label="Type" required :options="quantityMovementTypes" :error="qtyForm.errors.movement_type" />
                <DecimalInput v-model="qtyForm.quantity" label="Quantity" :decimals="4" required :error="qtyForm.errors.quantity" />
                <FormInput v-model="qtyForm.reason" label="Reason" maxlength="255" :error="qtyForm.errors.reason" />
                <FormInput v-model="qtyForm.remarks" label="Remarks" multiline :rows="2" maxlength="500" :error="qtyForm.errors.remarks" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="qtyOpen = false">Cancel</AppButton>
                <AppButton :loading="qtyForm.processing" @click="saveQty">Save movement</AppButton>
            </template>
        </AppDrawer>
    </AppLayout>
</template>
