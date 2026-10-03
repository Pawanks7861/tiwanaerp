<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import StatCard from '@/Components/Data/StatCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppTabs from '@/Components/UI/AppTabs.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * Cash flow (cash documents only) plus outstanding receivables / payables, shared by the project
 * and company finance pages. Filters reload the current URL.
 */
const props = defineProps({
    entries: { type: Array, required: true },
    totals: { type: Object, required: true },
    outstanding: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    modes: { type: Array, required: true },
    projects: { type: Array, default: null },
});

const state = reactive({
    project_id: props.filters.project_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    type: props.filters.type ?? '',
    mode: props.filters.mode ?? '',
    party: props.filters.party ?? '',
});
function apply() {
    const query = Object.fromEntries(Object.entries(state).filter(([, v]) => v !== '' && v !== null));
    router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true });
}
function clear() {
    Object.keys(state).forEach((k) => (state[k] = ''));
    apply();
}

const tab = ref('cash');
const tabs = computed(() => [
    { key: 'cash', label: 'Cash flow', count: props.entries.length },
    { key: 'receivables', label: 'Receivables', count: props.outstanding.receivables.length },
    { key: 'payables', label: 'Payables', count: props.outstanding.payables.length },
]);
const keyed = (rows) => rows.map((r, i) => ({ ...r, _key: i }));
const cashRows = computed(() => keyed(props.entries));
const outRows = computed(() => keyed(tab.value === 'cash' ? [] : props.outstanding[tab.value]));
const TYPE_LABELS = computed(() => Object.fromEntries(props.types.map((t) => [t.value, t.label])));

const cashColumns = computed(() => [
    { key: 'date', label: 'Date' },
    ...(props.projects ? [{ key: 'project', label: 'Project', mobile: false }] : []),
    { key: 'document', label: 'Document' },
    { key: 'party', label: 'Party', mobile: false },
    { key: 'inflow', label: 'In', align: 'right' },
    { key: 'outflow', label: 'Out', align: 'right' },
]);
const outColumns = computed(() => [
    { key: 'document', label: 'Document' },
    ...(props.projects ? [{ key: 'project', label: 'Project', mobile: false }] : []),
    { key: 'party', label: 'Party', mobile: false },
    { key: 'bucket', label: 'Age', mobile: false },
    { key: 'due', label: 'Due', align: 'right', mobile: false },
    { key: 'outstanding', label: 'Outstanding', align: 'right' },
]);
const BUCKETS = ['0-30', '31-60', '61-90', '90+'];
function aging(rows) {
    return BUCKETS.map((b) => ({ bucket: b, count: rows.filter((r) => r.bucket === b).length }));
}
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
            <StatCard label="Cash in" :value="formatMoney(totals.inflow)" icon="banknotes" tone="green" />
            <StatCard label="Cash out" :value="formatMoney(totals.outflow)" icon="banknotes" tone="orange" />
            <StatCard label="Net cash" :value="formatMoney(totals.net)" icon="scale" :tone="totals.net.startsWith('-') ? 'orange' : 'brand'" />
            <StatCard label="Receivable" :value="formatMoney(outstanding.totals.receivable)" icon="receipt" tone="slate" />
            <StatCard label="Payable" :value="formatMoney(outstanding.totals.payable)" icon="receipt" tone="amber" />
        </div>

        <AppCard :padded="false">
            <div class="grid gap-2 border-b border-line p-3 sm:grid-cols-3 lg:grid-cols-7">
                <select v-if="projects" v-model="state.project_id" aria-label="Project" class="rounded-lg border-slate-300 py-2 text-sm shadow-sm lg:col-span-2" @change="apply">
                    <option value="">All projects</option>
                    <option v-for="p in projects" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <input v-model="state.from" type="date" aria-label="From" class="rounded-lg border-slate-300 py-2 text-sm shadow-sm" @change="apply" />
                <input v-model="state.to" type="date" aria-label="To" class="rounded-lg border-slate-300 py-2 text-sm shadow-sm" @change="apply" />
                <select v-model="state.type" aria-label="Type" class="rounded-lg border-slate-300 py-2 text-sm shadow-sm" @change="apply">
                    <option value="">All types</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <select v-model="state.mode" aria-label="Mode" class="rounded-lg border-slate-300 py-2 text-sm shadow-sm" @change="apply">
                    <option value="">All modes</option>
                    <option v-for="m in modes" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <input v-model="state.party" type="search" placeholder="Party…" aria-label="Party" class="rounded-lg border-slate-300 py-2 text-sm shadow-sm" @keyup.enter="apply" @change="apply" />
                <AppButton size="sm" variant="ghost" class="self-center" @click="clear">Clear</AppButton>
            </div>
            <div class="border-b border-line px-3 pt-2">
                <AppTabs v-model="tab" :items="tabs" />
            </div>

            <DataTable v-if="tab === 'cash'" :columns="cashColumns" :rows="cashRows" row-key="_key" empty-icon="banknotes" empty-title="No cash movements" empty-description="Approved receipts and payments, directly paid expenses and petty cash funding appear here.">
                <template #cell-date="{ row }">
                    <div class="text-sm">{{ formatDate(row.date) }}</div>
                    <div class="text-xs text-slate-500">{{ TYPE_LABELS[row.type] ?? row.type }}</div>
                </template>
                <template #cell-document="{ row }">
                    <Link :href="row.url" class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ row.document }}</Link>
                    <div class="text-xs text-slate-500">{{ row.mode }}<template v-if="row.reference"> · {{ row.reference }}</template></div>
                </template>
                <template #cell-party="{ row }">
                    <div class="text-sm">{{ row.party ?? '—' }}</div>
                    <div class="text-xs text-slate-500">{{ row.party_type }}</div>
                </template>
                <template #cell-inflow="{ value }"><span class="text-emerald-700 tabular">{{ value ? formatMoney(value) : '' }}</span></template>
                <template #cell-outflow="{ value }"><span class="tabular">{{ value ? formatMoney(value) : '' }}</span></template>
            </DataTable>

            <template v-else>
                <div class="flex flex-wrap gap-2 border-b border-line px-4 py-2 text-xs text-slate-600">
                    <span v-for="a in aging(outstanding[tab])" :key="a.bucket" class="rounded-full bg-slate-100 px-2 py-0.5">{{ a.bucket }} days: {{ a.count }}</span>
                </div>
                <DataTable :columns="outColumns" :rows="outRows" row-key="_key" empty-icon="receipt" :empty-title="tab === 'receivables' ? 'Nothing receivable' : 'Nothing payable'">
                    <template #cell-document="{ row }">
                        <Link :href="row.url" class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ row.document }}</Link>
                        <div class="text-xs text-slate-500">{{ formatDate(row.date) }} · {{ row.party_type }}</div>
                    </template>
                    <template #cell-bucket="{ row }"><span class="text-xs">{{ row.age_days }} d</span></template>
                    <template #cell-due="{ value }"><span class="tabular">{{ formatMoney(value) }}</span></template>
                    <template #cell-outstanding="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
                </DataTable>
            </template>
        </AppCard>
        <p class="text-xs text-slate-500">Cash flow is built from cash documents only and never from the cost ledger. Spending from a petty cash float is not a company cash movement (the cash left when the float was funded).</p>
    </div>
</template>
