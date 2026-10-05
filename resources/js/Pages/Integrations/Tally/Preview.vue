<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

defineProps({
    reference: { type: String, required: true },
    error: { type: String, default: null },
    voucher: { type: Object, default: null },
});
</script>

<template>
    <AppLayout title="Tally preview">
        <PageHeader title="Preview Tally entry" :subtitle="reference" />
        <AppCard>
            <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
            <template v-else-if="voucher">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Voucher type</dt><dd>{{ voucher.voucher_type }}</dd></div>
                    <div><dt class="text-slate-500">Date</dt><dd>{{ voucher.date }}</dd></div>
                    <div><dt class="text-slate-500">Number</dt><dd>{{ voucher.number }}</dd></div>
                    <div><dt class="text-slate-500">Reference</dt><dd>{{ voucher.reference }}</dd></div>
                </dl>
                <p class="mt-3 text-sm text-slate-600">{{ voucher.narration }}</p>
                <table class="mt-4 min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-500">
                        <tr><th class="py-1">Ledger</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right">Credit</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in voucher.lines" :key="index" class="border-t border-line">
                            <td class="py-1">{{ line.ledger }}</td>
                            <td class="py-1 text-right tabular">{{ line.debit !== '0.00' ? formatMoney(line.debit) : '' }}</td>
                            <td class="py-1 text-right tabular">{{ line.credit !== '0.00' ? formatMoney(line.credit) : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </AppCard>
    </AppLayout>
</template>
