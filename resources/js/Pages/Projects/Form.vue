<script setup>
import DateInput from '@/Components/Form/DateInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, default: null },
    clients: { type: Array, required: true },
    managers: { type: Array, required: true },
    states: { type: Array, required: true },
    projectTypes: { type: Array, required: true },
});

const editing = !!props.project;
const p = props.project ?? {};

const form = useForm({
    code: p.code ?? '',
    name: p.name ?? '',
    client_id: p.client_id ?? null,
    project_type: p.project_type ?? null,
    project_manager_id: p.project_manager_id ?? null,
    description: p.description ?? '',
    address: p.address ?? '',
    city: p.city ?? '',
    state_code: p.state_code ?? null,
    latitude: p.latitude ?? '',
    longitude: p.longitude ?? '',
    start_date: p.start_date ?? null,
    expected_end_date: p.expected_end_date ?? null,
    contract_value: p.contract_value ?? null,
});

function submit() {
    if (editing) {
        form.put(route('projects.update', p.id));
    } else {
        form.post(route('projects.store'));
    }
}
</script>

<template>
    <AppLayout :title="editing ? `Edit ${p.code}` : 'New project'">
        <PageHeader
            :title="editing ? `Edit project ${p.code}` : 'New project'"
            :subtitle="editing ? p.name : 'The project number is assigned automatically.'"
            :back="editing ? route('projects.show', p.id) : route('projects.index')"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Project details">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.name" label="Project name" required :error="form.errors.name" maxlength="200" autofocus />
                    <FormInput
                        v-model="form.code"
                        label="Short code"
                        :required="editing"
                        uppercase
                        maxlength="12"
                        :error="form.errors.code"
                        :help="editing ? 'Used on document numbers.' : 'Leave blank to generate (e.g. PRJ004).'"
                    />
                    <SearchSelect v-model="form.client_id" label="Client" :options="clients" :error="form.errors.client_id" placeholder="Select client" />
                    <FormSelect v-model="form.project_type" label="Project type" :options="projectTypes" :error="form.errors.project_type" />
                    <SearchSelect
                        v-model="form.project_manager_id"
                        label="Project manager"
                        :options="managers"
                        :error="form.errors.project_manager_id"
                        placeholder="Select project manager"
                        help="The project manager is added to the project team automatically."
                    />
                    <MoneyInput v-model="form.contract_value" label="Contract value" :error="form.errors.contract_value" />
                    <FormInput v-model="form.description" label="Description" multiline class="sm:col-span-2" :error="form.errors.description" />
                </div>
            </AppCard>

            <AppCard title="Schedule">
                <div class="grid gap-4 sm:grid-cols-2">
                    <DateInput v-model="form.start_date" label="Start date" :error="form.errors.start_date" />
                    <DateInput v-model="form.expected_end_date" label="Expected completion" :error="form.errors.expected_end_date" />
                </div>
            </AppCard>

            <AppCard title="Location">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.address" label="Address" multiline :rows="2" class="sm:col-span-2" :error="form.errors.address" />
                    <FormInput v-model="form.city" label="City" maxlength="100" :error="form.errors.city" />
                    <FormSelect
                        v-model="form.state_code"
                        label="State (place of supply)"
                        :options="states"
                        :error="form.errors.state_code"
                        help="Decides CGST + SGST or IGST on bills."
                    />
                    <FormInput v-model="form.latitude" label="Latitude" inputmode="decimal" :error="form.errors.latitude" placeholder="30.900965" />
                    <FormInput v-model="form.longitude" label="Longitude" inputmode="decimal" :error="form.errors.longitude" placeholder="75.857277" />
                </div>
            </AppCard>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <AppButton variant="secondary" :href="editing ? route('projects.show', p.id) : route('projects.index')">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing">{{ editing ? 'Save changes' : 'Create project' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
