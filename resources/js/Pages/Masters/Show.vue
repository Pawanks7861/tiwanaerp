<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import CellValue from '@/Components/Data/CellValue.vue';
import MasterForm from '@/Components/Masters/MasterForm.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef } from 'vue';

const props = defineProps({
    definition: { type: Object, required: true },
    record: { type: Object, required: true },
    options: { type: Object, required: true },
    attachments: { type: Array, required: true },
    attachableType: { type: String, required: true },
    can: { type: Object, required: true },
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

            <AttachmentPanel
                :key="`${attachableType}-${record.id}`"
                :attachments="attachments"
                :attachable-type="attachableType"
                :attachable-id="record.id"
                :can-upload="can.update"
                :can-delete="can.update"
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
    </AppLayout>
</template>
