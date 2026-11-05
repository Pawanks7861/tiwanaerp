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
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    conversions: { type: Object, required: true },
    filters: { type: Object, required: true },
    operations: { type: Array, required: true },
    materials: { type: Array, required: true },
    projects: { type: Array, required: true },
});

const drawerOpen = ref(false);
const editing = ref(null);

const blank = () => ({
    from_unit: '',
    to_unit: '',
    operation: 'multiply',
    factor: '1',
    description: '',
    material_id: null,
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
        from_unit: row.from_unit,
        to_unit: row.to_unit,
        operation: row.operation,
        factor: row.factor,
        description: row.description ?? '',
        material_id: row.material_id,
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
        material_id: form.material_id || null,
        project_id: form.project_id || null,
        effective_from: form.effective_from || null,
    };
    const opts = { preserveScroll: true, onSuccess: () => (drawerOpen.value = false) };
    if (editing.value) {
        form.transform(() => payload).put(route('coefficients.conversions.update', editing.value.id), opts);
    } else {
        form.transform(() => payload).post(route('coefficients.conversions.store'), opts);
    }
}

const columns = [
    { key: 'from_unit', label: 'From' },
    { key: 'to_unit', label: 'To' },
    { key: 'factor', label: 'Factor' },
    { key: 'operation_label', label: 'Op' },
    { key: 'scope', label: 'Scope' },
    { key: 'is_active', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Unit conversions">
        <PageHeader title="Unit conversions" subtitle="Material → project → company resolution for cost build-up.">
            <template #actions>
                <AppButton icon="plus" @click="openCreate">New conversion</AppButton>
            </template>
        </PageHeader>
        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search ?? '', active: filters.active ?? 'all' }"
                :selects="[{ key: 'active', options: [{ value: 'all', label: 'All' }, { value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] }]"
                placeholder="Search units or description…"
            />
            <DataTable
                :columns="columns"
                :rows="conversions.data"
                clickable
                empty-icon="scale"
                empty-title="No conversions"
                @row-click="openEdit"
            >
                <template #cell-scope="{ row }">
                    <span v-if="row.material_label" class="text-xs">{{ row.material_label }}</span>
                    <span v-else-if="row.project_label" class="text-xs">{{ row.project_label }}</span>
                    <span v-else class="text-xs text-slate-500">Company</span>
                </template>
                <template #cell-is_active="{ value }"><StatusBadge :status="value" /></template>
            </DataTable>
            <Pagination :paginator="conversions" />
        </AppCard>

        <AppDrawer :show="drawerOpen" :title="editing ? 'Edit conversion' : 'New conversion'" @close="drawerOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormInput v-model="form.from_unit" label="From unit" required :error="form.errors.from_unit" />
                    <FormInput v-model="form.to_unit" label="To unit" required :error="form.errors.to_unit" />
                    <FormSelect v-model="form.operation" label="Operation" required :options="operations" :error="form.errors.operation" />
                    <FormInput v-model="form.factor" label="Factor" required :error="form.errors.factor" />
                    <FormSelect v-model="form.material_id" label="Material scope" placeholder="Any material" :options="materials" />
                    <FormSelect v-model="form.project_id" label="Project scope" placeholder="Company default" :options="projects" />
                    <FormInput v-model="form.effective_from" label="Effective from" type="date" class="sm:col-span-2" />
                    <FormInput v-model="form.description" label="Description" class="sm:col-span-2" />
                </div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="drawerOpen = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="form.processing">Save</AppButton>
                </div>
            </form>
        </AppDrawer>
    </AppLayout>
</template>
