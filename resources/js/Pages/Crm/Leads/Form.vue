<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    lead: { type: Object, default: null },
    assignees: { type: Array, required: true },
    clients: { type: Array, required: true },
    states: { type: Array, required: true },
});

const editing = computed(() => !!props.lead);
const l = props.lead;
const form = useForm({
    name: l?.name ?? '',
    company_name: l?.company_name ?? '',
    mobile: l?.mobile ?? '',
    email: l?.email ?? '',
    source: l?.source ?? '',
    project_type: l?.project_type ?? '',
    location: l?.location ?? '',
    state_code: l?.state_code ?? null,
    estimated_value: l?.estimated_value ?? null,
    expected_close_date: l?.expected_close_date ?? null,
    assigned_to: l?.assigned_to ?? null,
    client_id: l?.client_id ?? null,
    notes: l?.notes ?? '',
});

function submit() {
    const transform = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
    editing.value
        ? form.transform(transform).put(route('crm.leads.update', props.lead.id))
        : form.transform(transform).post(route('crm.leads.store'));
}
</script>

<template>
    <AppLayout :title="editing ? lead.lead_number : 'New lead'">
        <PageHeader :title="editing ? `Edit ${lead.lead_number}` : 'New lead'" :back="editing ? route('crm.leads.show', lead.id) : route('crm.leads.index')" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard title="Contact">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormInput v-model="form.name" label="Contact name" required maxlength="150" :error="form.errors.name" />
                    <FormInput v-model="form.company_name" label="Company" maxlength="200" :error="form.errors.company_name" />
                    <FormInput v-model="form.mobile" label="Mobile" type="tel" maxlength="20" :error="form.errors.mobile" />
                    <FormInput v-model="form.email" label="Email" type="email" maxlength="255" :error="form.errors.email" />
                    <SearchSelect v-model="form.client_id" label="Existing client" :options="clients" help="Link if this enquiry is from a client already in masters." :error="form.errors.client_id" />
                    <SearchSelect v-model="form.assigned_to" label="Assigned to" :options="assignees" :error="form.errors.assigned_to" />
                </div>
            </AppCard>
            <AppCard title="Enquiry">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormInput v-model="form.source" label="Source" maxlength="50" placeholder="Referral, website, walk-in…" :error="form.errors.source" />
                    <FormInput v-model="form.project_type" label="Project type" maxlength="50" placeholder="Residential, commercial…" :error="form.errors.project_type" />
                    <FormInput v-model="form.location" label="Location" maxlength="200" :error="form.errors.location" />
                    <SearchSelect v-model="form.state_code" label="State" :options="states" :error="form.errors.state_code" />
                    <DecimalInput v-model="form.estimated_value" label="Estimated value" prefix="₹" :error="form.errors.estimated_value" />
                    <FormInput v-model="form.expected_close_date" type="date" label="Expected close" :error="form.errors.expected_close_date" />
                    <FormInput v-model="form.notes" label="Notes" multiline :rows="3" maxlength="5000" class="sm:col-span-2 lg:col-span-3" :error="form.errors.notes" />
                </div>
            </AppCard>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('crm.leads.show', lead.id) : route('crm.leads.index')"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Create lead' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
