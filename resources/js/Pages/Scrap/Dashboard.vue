<script setup>
import ScrapNav from '@/Components/Scrap/ScrapNav.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    cards: { type: Array, required: true },
    recent: { type: Array, required: true },
    projects: { type: Array, default: () => [] },
});
</script>

<template>
    <AppLayout title="Scrap Management">
        <ScrapNav active="dashboard" />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <AppCard v-for="card in cards" :key="card.key" :title="card.label" class="text-center">
                <p class="text-3xl font-semibold tabular text-slate-900">{{ card.value }}</p>
            </AppCard>
        </div>
        <AppCard title="Recent generations" subtitle="Latest scrap generation documents across your projects." class="mt-4" :padded="false">
            <ul v-if="recent.length" class="divide-y divide-line">
                <li v-for="row in recent" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                    <div>
                        <Link :href="row.show_url" class="font-mono text-sm font-medium text-brand-700 hover:underline">{{ row.generation_number }}</Link>
                        <div class="text-xs text-slate-500">{{ row.project?.name }} · {{ formatDate(row.generation_date) }}</div>
                    </div>
                    <StatusBadge :status="row.status" :label="row.status_label" />
                </li>
            </ul>
            <p v-else class="p-6 text-sm text-slate-500">No generations yet. Create one from a project Scrap tab.</p>
        </AppCard>
    </AppLayout>
</template>
