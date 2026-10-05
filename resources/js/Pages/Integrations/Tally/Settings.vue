<script setup>
import TallyNav from '@/Components/Integrations/TallyNav.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    connection: { type: Object, required: true },
    can: { type: Object, required: true },
    projects: { type: Array, default: () => [] },
});

const form = useForm({ ...props.connection });
const pending = useForm({ from: '', to: '', project_id: '', type: '', status: '' });

function save() {
    form.put(route('integrations.tally.update'), { preserveScroll: true });
}

const modes = [
    { value: 'direct', label: 'Direct' },
    { value: 'connector', label: 'Connector / future' },
];
const formats = [
    { value: 'xml', label: 'XML' },
    { value: 'json', label: 'JSON (not available yet)' },
];
const protocols = [
    { value: 'http', label: 'HTTP' },
    { value: 'https', label: 'HTTPS' },
];
const types = [
    { value: '', label: 'All types' },
    { value: 'client_invoice', label: 'Client invoice' },
    { value: 'payment', label: 'Receipt or payment' },
    { value: 'vendor_bill', label: 'Vendor bill' },
    { value: 'expense', label: 'Expense' },
    { value: 'subcontractor_bill', label: 'Subcontractor bill' },
    { value: 'labour_payment', label: 'Labour payment batch' },
    { value: 'petty_cash_transaction', label: 'Petty cash' },
];
</script>

<template>
    <AppLayout title="TallyPrime">
        <PageHeader title="TallyPrime" subtitle="Send finalized accounting documents to Tally. The ERP stays the operational source of truth." />
        <TallyNav active="connection" />

        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-line bg-white px-4 py-3">
            <span class="inline-flex items-center gap-2 text-sm font-medium text-slate-800">
                <span class="h-2.5 w-2.5 rounded-full" :class="connection.last_status === 'connected' ? 'bg-emerald-500' : 'bg-slate-300'" />
                {{ connection.last_status === 'connected' ? 'Connected' : 'Offline' }}
            </span>
            <span class="text-sm text-slate-500">{{ connection.tally_company_name || 'No company name' }}</span>
            <span v-if="connection.last_checked_at" class="text-xs text-slate-400">Checked {{ connection.last_checked_at }}</span>
            <span v-if="connection.last_sync_at" class="text-xs text-slate-400">Last sync {{ connection.last_sync_at }}</span>
        </div>

        <form class="space-y-4" @submit.prevent="save">
            <AppCard title="Connection">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormSwitch v-model="form.enabled" label="Enable Tally integration" :disabled="!can.manage" />
                    <FormSwitch v-model="form.dry_run" label="Dry run / preview only" description="Builds the voucher and does not send it." :disabled="!can.manage" />
                    <FormSelect v-model="form.transport" label="Connection mode" :options="modes" :disabled="!can.manage" />
                    <FormSelect v-model="form.format" label="Integration format" :options="formats" :disabled="!can.manage" />
                    <FormSelect v-model="form.protocol" label="Protocol" :options="protocols" :disabled="!can.manage" />
                    <FormInput v-model="form.host" label="Tally host" :error="form.errors.host" :disabled="!can.manage" help="Localhost, a private LAN address, or a VPN host. Do not publish port 9000 on the internet." />
                    <FormInput v-model="form.port" type="number" label="Tally port" :error="form.errors.port" :disabled="!can.manage" />
                    <FormInput v-model="form.tally_company_name" label="Tally company name" :disabled="!can.manage" />
                    <FormInput v-model="form.timeout_seconds" type="number" label="Request timeout (seconds)" :disabled="!can.manage" />
                    <FormSwitch v-model="form.auto_sync" label="Auto sync" description="Queue a sync after a document is finalized." :disabled="!can.manage" />
                    <FormSwitch v-model="form.sync_approved_transactions" label="Sync approved transactions" :disabled="!can.manage" />
                    <FormSwitch v-model="form.cost_centres_enabled" label="Enable project cost centre mapping" :disabled="!can.manage" />
                </div>
                <p v-if="connection.suggested_defaults" class="mt-3 text-xs text-slate-500">Host 127.0.0.1 and port 9000 are filled in only for this local environment. They are not assumed in production.</p>
                <p v-if="form.transport === 'connector'" class="mt-3 text-xs text-slate-500">Connector mode uses the same XML endpoint. Point the host at a private connector on the accountant's network. A desktop connector is not included in this version.</p>
            </AppCard>
            <div class="flex flex-wrap gap-2">
                <AppButton v-if="can.manage" type="submit" :loading="form.processing">Save</AppButton>
                <AppButton v-if="can.manage" type="button" variant="secondary" @click="router.post(route('integrations.tally.test'))">Test connection</AppButton>
                <AppButton v-if="can.sync" type="button" variant="secondary" @click="router.post(route('integrations.tally.masters'))">Sync masters</AppButton>
            </div>
        </form>

        <AppCard title="Sync pending" subtitle="Queues at most 50 finalized documents. Drafts are never included." class="mt-4">
            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="pending.post(route('integrations.tally.pending'), { preserveScroll: true })">
                <FormInput v-model="pending.from" type="date" label="From" />
                <FormInput v-model="pending.to" type="date" label="To" />
                <FormSelect v-model="pending.project_id" label="Project" :options="[{ value: '', label: 'All projects' }, ...projects]" />
                <FormSelect v-model="pending.type" label="Transaction type" :options="types" />
                <FormSelect v-model="pending.status" label="Status" :options="[{ value: '', label: 'Any open status' }, { value: 'pending', label: 'Pending' }, { value: 'failed', label: 'Failed' }, { value: 'needs_mapping', label: 'Needs mapping' }, { value: 'not_synced', label: 'Not synced' }]" />
                <div class="flex items-end">
                    <AppButton v-if="can.sync" type="submit" :loading="pending.processing">Sync pending transactions</AppButton>
                </div>
            </form>
        </AppCard>
    </AppLayout>
</template>
