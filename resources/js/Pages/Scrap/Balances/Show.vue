<script setup>
import ScrapNav from '@/Components/Scrap/ScrapNav.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatQty } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    project: { type: Object, required: true },
    balance: { type: Object, required: true },
    ledger: { type: Array, required: true },
    generations: { type: Array, default: () => [] },
});
</script>

<template>
    <ProjectLayout :project="project" active="scrap" :title="balance.scrap_type?.name ?? 'Scrap balance'">
        <ScrapNav active="balances" scope="project" :project-id="project.id" />
        <AppCard :title="balance.scrap_type?.name" :subtitle="`${balance.category ?? ''} · available ${formatQty(balance.quantity)} ${balance.unit ?? ''}`">
            <dl class="grid gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-slate-500">Generated</dt><dd class="tabular">{{ formatQty(balance.qty_generated) }}</dd></div>
                <div><dt class="text-slate-500">Dispatched</dt><dd class="tabular">{{ formatQty(balance.qty_dispatched) }}</dd></div>
                <div><dt class="text-slate-500">Adjusted net</dt><dd class="tabular">{{ formatQty(balance.qty_adjusted_net) }}</dd></div>
            </dl>
        </AppCard>
        <AppCard title="Recent ledger" class="mt-4" :padded="false">
            <table class="w-full text-sm">
                <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-600">
                    <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Type</th><th class="px-4 py-2 text-right">In</th><th class="px-4 py-2 text-right">Out</th><th class="px-4 py-2">Ref</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(row, i) in ledger" :key="i">
                        <td class="px-4 py-2">{{ formatDate(row.transaction_date) }}</td>
                        <td class="px-4 py-2">{{ row.type_label }}</td>
                        <td class="px-4 py-2 text-right tabular">{{ formatQty(row.quantity_in) }}</td>
                        <td class="px-4 py-2 text-right tabular">{{ formatQty(row.quantity_out) }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ row.reference_number ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </AppCard>
        <AppCard v-if="generations.length" title="Related generations" class="mt-4" :padded="false">
            <ul class="divide-y divide-line">
                <li v-for="g in generations" :key="g.id" class="px-4 py-2">
                    <Link :href="g.show_url" class="font-mono text-sm text-brand-700 hover:underline">{{ g.generation_number }}</Link>
                    <span class="ml-2 text-xs text-slate-500">{{ formatDate(g.generation_date) }}</span>
                </li>
            </ul>
        </AppCard>
    </ProjectLayout>
</template>
