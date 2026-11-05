<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatRate } from '@/lib/format';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    entry_types: { type: Array, required: true },
    projects: { type: Array, required: true },
});

const drawerOpen = ref(false);
const editing = ref(null);

const blank = () => ({
    entry_type: 'material',
    description: '',
    unit: '',
    rate: '0',
    source: '',
    notes: '',
    project_id: null,
    effective_from: '',
    is_active: true,
});

const form = useForm(blank());

function openCreate() {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    drawerOpen.value = true;
}

function openEdit(row) {
    editing.value = row;
    form.defaults({
        entry_type: row.entry_type,
        description: row.description,
        unit: row.unit ?? '',
        rate: row.rate,
        source: row.source ?? '',
        notes: row.notes ?? '',
        project_id: row.project_id,
        effective_from: row.effective_from ?? '',
        is_active: row.is_active,
    });
    form.reset();
    drawerOpen.value = true;
}

function submit() {
    const payload = {
        ...form.data(),
        project_id: form.project_id || null,
        effective_from: form.effective_from || null,
        unit: form.unit || null,
        source: form.source || null,
        notes: form.notes || null,
    };
    const opts = { preserveScroll: true, onSuccess: () => (drawerOpen.value = false) };
    if (editing.value) {
        form.transform(() => payload).put(route('coefficients.rate-library.update', editing.value.id), opts);
    } else {
        form.transform(() => payload).post(route('coefficients.rate-library.store'), opts);
    }
}

const columns = [
    { key: 'entry_type', label: 'Type' },
    { key: 'description', label: 'Description' },
    { key: 'unit', label: 'Unit' },
    { key: 'rate', label: 'Rate', align: 'right' },
    { key: 'is_active', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Costing rate library">
        <PageHeader title="Costing rate library" subtitle="Reference rates for cost build-up (Rate Library source).">
            <template #actions>
                <AppButton icon="plus" @click="openCreate">New entry</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', entry_type: filters.entry_type ?? 'all', active: filters.active ?? 'all' }"
                :selects="[
                    { key: 'entry_type', options: [{ value: 'all', label: 'All types' }, ...entry_types] },
                    { key: 'active', options: [{ value: 'all', label: 'All' }, { value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] },
                ]"
                placeholder="Search description…"
            />
            <DataTable :columns="columns" :rows="entries.data" clickable empty-icon="banknotes" empty-title="No entries" @row-click="openEdit">
                <template #cell-rate="{ row }"><span class="tabular">{{ formatRate(row.rate) }}</span></template>
                <template #cell-is_active="{ value }"><StatusBadge :status="value" /></template>
            </DataTable>
            <Pagination :paginator="entries" />
        </AppCard>

        <AppDrawer :show="drawerOpen" :title="editing ? 'Edit rate entry' : 'New rate entry'" @close="drawerOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <FormSelect v-model="form.entry_type" label="Type" required :options="entry_types" :error="form.errors.entry_type" />
                <FormInput v-model="form.description" label="Description" required maxlength="255" :error="form.errors.description" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormInput v-model="form.unit" label="Unit" maxlength="40" />
                    <FormInput v-model="form.rate" label="Rate" required :error="form.errors.rate" />
                    <FormInput v-model="form.effective_from" label="Effective from" type="date" />
                    <FormSelect v-model="form.project_id" label="Project" placeholder="Company-wide" :options="projects" />
                    <FormInput v-model="form.source" label="Source" class="sm:col-span-2" />
                    <FormInput v-model="form.notes" label="Notes" class="sm:col-span-2" />
                </div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="drawerOpen = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="form.processing">Save</AppButton>
                </div>
            </form>
        </AppDrawer>
    </AppLayout>
</template>
