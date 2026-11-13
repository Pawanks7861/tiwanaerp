<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    groups: { type: Array, default: () => [] },
    reports: { type: Array, default: () => [] },
});
</script>

<template>
    <AppLayout title="Electricity reports">
        <PageHeader title="Electricity reports" subtitle="Analytics and exports for utility billing." :back="route('utilities.electricity.hub')" />
        <div v-if="groups.length" class="space-y-6">
            <section v-for="group in groups" :key="group.key">
                <h2 class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ group.label }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <Link v-for="report in group.reports" :key="report.key" :href="report.url">
                        <AppCard class="h-full transition hover:border-brand-200">
                            <p class="text-sm font-semibold text-slate-900">{{ report.title }}</p>
                            <p v-if="report.description" class="mt-1 text-sm text-slate-500">{{ report.description }}</p>
                        </AppCard>
                    </Link>
                </div>
            </section>
        </div>
        <div v-else-if="reports.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Link v-for="report in reports" :key="report.key" :href="report.url ?? report.href">
                <AppCard class="h-full transition hover:border-brand-200">
                    <p class="text-sm font-semibold text-slate-900">{{ report.title }}</p>
                    <p v-if="report.description" class="mt-1 text-sm text-slate-500">{{ report.description }}</p>
                </AppCard>
            </Link>
        </div>
        <AppCard v-else>
            <p class="text-sm text-slate-600">No electricity reports are configured yet.</p>
        </AppCard>
    </AppLayout>
</template>
