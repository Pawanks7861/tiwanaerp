<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

const modules = [
    { key: 'dashboard', title: 'Dashboard', description: 'Spend, consumption, demand and alerts across sites.', route: 'utilities.electricity.dashboard' },
    { key: 'readings', title: 'Meter readings', description: 'Capture kWh, kVAh, MDI and TOD slots.', route: 'utilities.electricity.readings.index' },
    { key: 'bills', title: 'Bills', description: 'Compare calculated vs actual DISCOM bills.', route: 'utilities.electricity.bills.index' },
    { key: 'tariffs', title: 'Tariffs', description: 'Rate structures and bill components.', route: 'utilities.electricity.tariffs.index' },
    { key: 'connections', title: 'Connections', description: 'Consumer numbers, demand and metering setup.', route: 'utilities.electricity.connections.index' },
    { key: 'sites', title: 'Sites', description: 'Plants, yards and offices consuming power.', route: 'utilities.electricity.sites.index' },
    { key: 'comparison', title: 'Monthly comparison', description: 'Month-on-month consumption and cost trends.', route: 'utilities.electricity.comparison' },
    { key: 'reports', title: 'Reports', description: 'Exportable electricity analytics.', route: 'utilities.electricity.reports' },
];
</script>

<template>
    <AppLayout title="Electricity">
        <PageHeader title="Electricity management" subtitle="Company-wide utility billing, tariffs and reconciliation." />
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Link v-for="item in modules" :key="item.key" :href="route(item.route)">
                <AppCard class="h-full transition hover:border-brand-200">
                    <p class="text-sm font-semibold text-slate-900">{{ item.title }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ item.description }}</p>
                </AppCard>
            </Link>
        </div>
        <div class="mt-4">
            <Link :href="route('utilities.electricity.tariff-comparison')" class="text-sm font-medium text-brand-700 hover:underline">Tariff comparison →</Link>
        </div>
    </AppLayout>
</template>
