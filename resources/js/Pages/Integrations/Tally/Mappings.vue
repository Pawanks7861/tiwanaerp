<script setup>
import TallyNav from '@/Components/Integrations/TallyNav.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    systems: { type: Array, required: true },
    parties: { type: Array, required: true },
    cost_centres: { type: Array, required: true },
    sources: { type: Object, required: true },
    projects: { type: Array, required: true },
    can: { type: Object, required: true },
});

const systemsForm = useForm({
    rows: props.systems.map((row) => ({ ...row })),
});
const party = useForm({
    source_type: 'client',
    source_id: '',
    tally_ledger_name: '',
    tally_parent_group: 'Sundry Debtors',
    auto_create_allowed: false,
});
const centre = useForm({ project_id: '', tally_cost_centre_name: '' });

const partyTypes = [
    { value: 'client', label: 'Client' },
    { value: 'vendor', label: 'Vendor' },
    { value: 'subcontractor', label: 'Subcontractor' },
    { value: 'expense_category', label: 'Expense category' },
];
</script>

<template>
    <AppLayout title="Tally ledger mapping">
        <PageHeader title="Ledger mapping" subtitle="ERP names are not sent until a Tally ledger is chosen. A missing mapping blocks the voucher." />
        <TallyNav active="mappings" />

        <form @submit.prevent="systemsForm.put(route('integrations.tally.mappings.update'), { preserveScroll: true })">
            <AppCard title="System ledgers" :padded="false">
                <div class="divide-y divide-line">
                    <div v-for="(row, index) in systemsForm.rows" :key="row.map_key" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                        <div class="text-sm font-medium text-slate-800 lg:col-span-1">{{ row.label }}</div>
                        <FormInput v-model="systemsForm.rows[index].tally_ledger_name" label="Tally ledger" :disabled="!can.mapping" />
                        <FormInput v-model="systemsForm.rows[index].tally_parent_group" label="Parent group" :disabled="!can.mapping" />
                        <FormSwitch v-model="systemsForm.rows[index].auto_create_allowed" label="Allow create" :disabled="!can.mapping" />
                    </div>
                </div>
            </AppCard>
            <AppButton v-if="can.mapping" class="mt-3" type="submit" :loading="systemsForm.processing">Save system ledgers</AppButton>
        </form>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <AppCard title="Party and category ledgers">
                <ul v-if="parties.length" class="mb-4 space-y-2 text-sm">
                    <li v-for="row in parties" :key="row.id" class="flex justify-between gap-3">
                        <span class="text-slate-600">{{ row.source_type }} #{{ row.source_id }}</span>
                        <span class="font-medium text-slate-900">{{ row.tally_ledger_name }}</span>
                    </li>
                </ul>
                <form v-if="can.mapping" class="space-y-3" @submit.prevent="party.post(route('integrations.tally.mappings.parties'), { preserveScroll: true })">
                    <FormSelect v-model="party.source_type" label="Source" :options="partyTypes" />
                    <FormSelect v-model="party.source_id" label="Record" :options="sources[party.source_type] || []" />
                    <FormInput v-model="party.tally_ledger_name" label="Tally ledger" />
                    <FormInput v-model="party.tally_parent_group" label="Parent group" />
                    <FormSwitch v-model="party.auto_create_allowed" label="Create in Tally if confirmed" description="Does not rename an existing ledger or add a suffix." />
                    <AppButton type="submit" :loading="party.processing">Save mapping</AppButton>
                </form>
            </AppCard>

            <AppCard title="Project cost centres">
                <ul v-if="cost_centres.length" class="mb-4 space-y-2 text-sm">
                    <li v-for="row in cost_centres" :key="row.id" class="flex justify-between gap-3">
                        <span class="text-slate-600">{{ row.project }}</span>
                        <span class="font-medium text-slate-900">{{ row.tally_cost_centre_name }}</span>
                    </li>
                </ul>
                <p v-else class="mb-4 text-sm text-slate-500">Leave this empty if the company does not use Tally cost centres.</p>
                <form v-if="can.mapping" class="space-y-3" @submit.prevent="centre.post(route('integrations.tally.mappings.cost-centres'), { preserveScroll: true })">
                    <FormSelect v-model="centre.project_id" label="Project" :options="projects" />
                    <FormInput v-model="centre.tally_cost_centre_name" label="Tally cost centre" />
                    <AppButton type="submit" :loading="centre.processing">Save cost centre</AppButton>
                </form>
            </AppCard>
        </div>
    </AppLayout>
</template>
