<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import MasterForm from '@/Components/Masters/MasterForm.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { masters } from '@/lib/navigation';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef } from 'vue';

const props = defineProps({
    definition: { type: Object, required: true },
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const icon = computed(() => masters.find((m) => m.slug === props.definition.slug)?.icon ?? 'grid');
const drawerOpen = ref(false);
const editing = ref(null);
const form = shallowRef(useForm({}));
const deleting = ref(null);
const deleteProcessing = ref(false);

function blankValues() {
    return Object.fromEntries(props.definition.fields.map((f) => [f.name, f.default ?? (f.type === 'switch' ? true : null)]));
}

function openCreate() {
    editing.value = null;
    form.value = useForm(blankValues());
    drawerOpen.value = true;
}

function openEdit(row) {
    editing.value = row;
    const values = blankValues();
    for (const field of props.definition.fields) {
        const value = row[field.name];
        values[field.name] = field.type === 'switch' ? !!value : (value ?? null);
    }
    form.value = useForm(values);
    drawerOpen.value = true;
}

function rowClicked(row) {
    if (props.definition.hasAttachments) {
        router.visit(route('masters.show', [props.definition.slug, row.id]));
    } else if (props.can.update) {
        openEdit(row);
    }
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (drawerOpen.value = false) };
    if (editing.value) {
        form.value.put(route('masters.update', [props.definition.slug, editing.value.id]), options);
    } else {
        form.value.post(route('masters.store', props.definition.slug), options);
    }
}

function destroy() {
    deleteProcessing.value = true;
    router.delete(route('masters.destroy', [props.definition.slug, deleting.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            deleting.value = null;
        },
    });
}

const recordLabel = (row) => row?.code ?? row?.[props.definition.nameColumn] ?? '';
const clickable = computed(() => props.definition.hasAttachments || props.can.update);
const titleSlot = computed(() => `cell-${props.definition.columns[0].key}`);
</script>

<template>
    <AppLayout :title="definition.title">
        <PageHeader :title="definition.title" :subtitle="`Company master · ${records.total} ${records.total === 1 ? 'record' : 'records'}`">
            <template #actions>
                <AppButton v-if="can.create" icon="plus" @click="openCreate">New {{ definition.singular.toLowerCase() }}</AppButton>
            </template>
        </PageHeader>

        <div class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
            <FilterBar
                :key="definition.slug"
                :filters="filters"
                :placeholder="`Search ${definition.title.toLowerCase()}`"
                :selects="[
                    {
                        key: 'status',
                        options: [
                            { value: 'all', label: 'All' },
                            { value: 'active', label: 'Active' },
                            { value: 'inactive', label: 'Inactive' },
                        ],
                    },
                ]"
            />
            <DataTable
                :columns="definition.columns"
                :rows="records.data"
                :row-href="null"
                :empty-icon="icon"
                :empty-title="`No ${definition.title.toLowerCase()} found`"
                :empty-description="filters.search ? 'Try a different search.' : null"
            >
                <template #[titleSlot]="{ row, value }">
                    <button
                        v-if="clickable"
                        type="button"
                        class="text-left font-medium text-brand-700 hover:underline"
                        :class="definition.columns[0].type === 'code' ? 'font-mono text-xs' : ''"
                        @click.stop="rowClicked(row)"
                    >
                        {{ value ?? '—' }}
                    </button>
                    <span v-else>{{ value ?? '—' }}</span>
                </template>
                <template v-if="can.update || can.delete" #actions="{ row }">
                    <AppButton v-if="can.update" variant="ghost" size="sm" icon="pencil" @click="openEdit(row)">Edit</AppButton>
                    <AppButton v-if="can.delete" variant="ghost" size="sm" icon="trash" @click="deleting = row">Delete</AppButton>
                </template>
            </DataTable>
            <Pagination :paginator="records" />
        </div>

        <AppDrawer
            :show="drawerOpen"
            :title="editing ? `Edit ${definition.singular.toLowerCase()} ${recordLabel(editing)}` : `New ${definition.singular.toLowerCase()}`"
            @close="drawerOpen = false"
        >
            <form id="master-form" @submit.prevent="submit">
                <MasterForm :definition="definition" :form="form" :options="options" :editing="!!editing" />
            </form>
            <template #footer>
                <AppButton variant="secondary" class="flex-1 sm:flex-none" @click="drawerOpen = false">Cancel</AppButton>
                <AppButton type="submit" form="master-form" class="flex-1 sm:flex-none" :loading="form.processing">
                    {{ editing ? 'Save changes' : `Create ${definition.singular.toLowerCase()}` }}
                </AppButton>
            </template>
        </AppDrawer>

        <ConfirmDialog
            :show="!!deleting"
            :title="`Delete ${definition.singular.toLowerCase()} ${recordLabel(deleting)}?`"
            message="Records already used elsewhere cannot be deleted; mark them inactive instead."
            :confirm-label="`Delete ${definition.singular.toLowerCase()}`"
            :processing="deleteProcessing"
            @close="deleting = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>
