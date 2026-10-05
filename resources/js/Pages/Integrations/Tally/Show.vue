<script setup>
import TallyNav from '@/Components/Integrations/TallyNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    record: { type: Object, required: true },
    can: { type: Object, required: true },
});
</script>

<template>
    <AppLayout :title="`Tally ${record.reference}`">
        <PageHeader :title="record.reference" subtitle="Accounting snapshot sent, or prepared, for this ERP document." />
        <TallyNav active="history" />
        <AppCard>
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <StatusBadge :status="record.status" :label="record.status_label" />
                <span class="text-sm text-slate-500">{{ record.voucher_type || record.action }} · {{ record.attempts }} attempts</span>
                <AppButton v-if="can.retry && ['failed', 'pending', 'needs_mapping', 'cancel_pending'].includes(record.status)" size="sm" variant="secondary" @click="router.post(route('integrations.tally.history.retry', record.id))">Retry</AppButton>
            </div>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Party ledger</dt><dd>{{ record.party_ledger || '—' }}</dd></div>
                <div><dt class="text-slate-500">Amount</dt><dd class="tabular">{{ record.amount ? formatMoney(record.amount) : '—' }}</dd></div>
                <div><dt class="text-slate-500">Tally reference</dt><dd>{{ record.tally_reference || '—' }}</dd></div>
                <div><dt class="text-slate-500">Last attempt</dt><dd>{{ record.updated_at || '—' }}</dd></div>
            </dl>
            <p v-if="record.narration" class="mt-3 text-sm text-slate-600">{{ record.narration }}</p>
            <p v-if="record.error" class="mt-3 text-sm text-red-700">{{ record.error }}</p>
            <table v-if="record.lines.length" class="mt-4 min-w-full text-sm">
                <thead class="text-left text-xs uppercase text-slate-500">
                    <tr><th class="py-1">Ledger</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right">Credit</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in record.lines" :key="index" class="border-t border-line">
                        <td class="py-1">{{ line.ledger }}</td>
                        <td class="py-1 text-right tabular">{{ line.debit !== '0.00' ? formatMoney(line.debit) : '' }}</td>
                        <td class="py-1 text-right tabular">{{ line.credit !== '0.00' ? formatMoney(line.credit) : '' }}</td>
                    </tr>
                </tbody>
            </table>
            <pre v-if="can.manage && record.response" class="mt-4 overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ record.response }}</pre>
        </AppCard>
    </AppLayout>
</template>
