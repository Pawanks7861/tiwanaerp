<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    project: { type: Object, required: true },
    boqs: { type: Array, required: true },
});

const pct = (line) => (line.percent === null ? null : Math.min(100, Number(line.percent)));
</script>

<template>
    <ProjectLayout :project="project" active="progress" title="BOQ progress">
        <div class="space-y-4">
            <Link :href="route('projects.progress', project.id)" class="text-sm font-medium text-slate-500 hover:text-slate-700">← Task progress</Link>
            <AppCard
                v-for="boq in boqs"
                :key="boq.id"
                :title="`${boq.boq_number} · ${boq.title}`"
                :subtitle="`Version ${boq.version}. Executed quantity from approved DPRs, tracked per BOQ line across revisions.`"
                :padded="false"
            >
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">Item</th>
                                <th class="px-4 py-2 text-right">BOQ qty</th>
                                <th class="px-4 py-2 text-right">Executed</th>
                                <th class="px-4 py-2 text-right">Balance</th>
                                <th class="w-44 px-4 py-2 text-left">Progress</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="line in boq.lines" :key="line.id">
                                <td class="px-4 py-2"><span class="font-mono text-xs text-slate-500">{{ line.item_code }}</span> {{ line.name }}</td>
                                <td class="px-4 py-2 text-right whitespace-nowrap tabular">{{ formatQty(line.quantity) }} {{ line.unit }}</td>
                                <td class="px-4 py-2 text-right whitespace-nowrap tabular">{{ formatQty(line.executed) }}</td>
                                <td class="px-4 py-2 text-right whitespace-nowrap tabular">{{ formatQty(line.balance) }}</td>
                                <td class="px-4 py-2">
                                    <div v-if="pct(line) !== null" class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full" :class="pct(line) >= 100 ? 'bg-emerald-500' : 'bg-brand-500'" :style="{ width: `${pct(line)}%` }" /></div>
                                        <span class="w-14 text-right text-xs tabular">{{ Number(line.percent).toFixed(1) }}%</span>
                                    </div>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="line in boq.lines" :key="line.id" class="px-4 py-3 text-sm">
                        <p class="font-medium text-slate-800"><span class="font-mono text-xs text-slate-500">{{ line.item_code }}</span> {{ line.name }}</p>
                        <p class="text-xs text-slate-500 tabular">{{ formatQty(line.executed) }} / {{ formatQty(line.quantity) }} {{ line.unit }} · balance {{ formatQty(line.balance) }}</p>
                        <div v-if="pct(line) !== null" class="mt-1 flex items-center gap-2">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full" :class="pct(line) >= 100 ? 'bg-emerald-500' : 'bg-brand-500'" :style="{ width: `${pct(line)}%` }" /></div>
                            <span class="w-14 text-right text-xs tabular">{{ Number(line.percent).toFixed(1) }}%</span>
                        </div>
                    </li>
                </ul>
                <p v-if="!boq.lines.length" class="px-4 py-3 text-sm text-slate-500">No lines.</p>
            </AppCard>
            <AppCard v-if="!boqs.length">
                <EmptyState icon="document" title="No approved BOQ" description="BOQ progress appears once a BOQ is approved and DPRs post quantities against its lines." />
            </AppCard>
        </div>
    </ProjectLayout>
</template>
