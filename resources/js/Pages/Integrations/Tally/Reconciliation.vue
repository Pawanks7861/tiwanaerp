<script setup>
import TallyNav from '@/Components/Integrations/TallyNav.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    counts: { type: Object, required: true },
    rows: { type: Array, required: true },
});

const cards = [
    ['matched', 'Matched'],
    ['erp_only', 'ERP only'],
    ['tally_conflict', 'Tally conflict'],
    ['failed', 'Failed'],
    ['needs_review', 'Needs review'],
];
</script>

<template>
    <AppLayout title="Tally reconciliation">
        <PageHeader title="Reconciliation" subtitle="Compares ERP sync records with the acknowledgement Tally returned. This is not a full general-ledger match." />
        <TallyNav active="reconciliation" />
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <AppCard v-for="[key, label] in cards" :key="key">
                <p class="text-xs uppercase text-slate-500">{{ label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular text-slate-900">{{ counts[key] }}</p>
            </AppCard>
        </div>
        <AppCard :padded="false">
            <ul class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id" class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ row.reference }}</p>
                        <p class="text-xs text-slate-500">{{ row.type }} · {{ row.status_label }}</p>
                    </div>
                    <p class="text-xs text-slate-500">{{ row.message || row.tally_reference || '—' }}</p>
                </li>
                <li v-if="!rows.length" class="px-4 py-8 text-center text-sm text-slate-500">No sync records yet.</li>
            </ul>
        </AppCard>
    </AppLayout>
</template>
