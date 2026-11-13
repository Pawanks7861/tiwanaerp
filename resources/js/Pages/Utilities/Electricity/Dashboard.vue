<script setup>
import FilterBar from '@/Components/Data/FilterBar.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatCost, formatUnitRate } from '@/lib/electricity';
import { formatNumber } from '@/lib/format';
import { Link } from '@inertiajs/vue3';

defineProps({
    cards: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    can_view_costs: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
    available_months: { type: Array, default: () => [] },
    sites: { type: Array, default: () => [] },
});
</script>

<template>
    <AppLayout title="Electricity dashboard">
        <PageHeader title="Electricity dashboard" subtitle="Rolling view of consumption, demand and billing health." :back="route('utilities.electricity.hub')" />
        <AppCard :padded="false" class="mb-4">
            <FilterBar
                :searchable="false"
                :filters="{
                    month: filters.month ?? available_months[0]?.value ?? '',
                    utility_site_id: filters.utility_site_id ?? 'all',
                }"
                :selects="[
                    { key: 'month', options: available_months },
                    { key: 'utility_site_id', options: [{ value: 'all', label: 'All sites' }, ...sites] },
                ]"
            />
        </AppCard>
        <div v-if="alerts.length" class="mb-4 space-y-2">
            <AppCard v-for="(alert, i) in alerts" :key="i" :padded="false" class="border-amber-200 bg-amber-50/80">
                <p class="px-4 py-3 text-sm text-amber-900"><span class="font-medium">{{ alert.title }}</span><span v-if="alert.message"> — {{ alert.message }}</span></p>
            </AppCard>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <AppCard v-for="card in cards" :key="card.key" :title="card.label" :subtitle="card.hint">
                <p class="text-2xl font-semibold tabular text-slate-900">
                    <template v-if="card.kind === 'money'">{{ formatCost(card.value, can_view_costs) }}</template>
                    <template v-else-if="card.kind === 'rate'">{{ formatUnitRate(card.value, card.unit, can_view_costs) }}</template>
                    <template v-else-if="card.kind === 'quantity'">{{ formatNumber(card.value, 0, 2) }}<span v-if="card.unit" class="ml-1 text-base font-normal text-slate-500">{{ card.unit }}</span></template>
                    <template v-else>{{ card.value ?? '—' }}</template>
                </p>
                <Link v-if="card.href" :href="card.href" class="mt-2 inline-block text-xs font-medium text-brand-700 hover:underline">{{ card.link_label ?? 'View' }}</Link>
            </AppCard>
        </div>
        <p v-if="!cards.length" class="mt-4 text-sm text-slate-500">No dashboard metrics yet. Add sites, connections and readings to populate this view.</p>
    </AppLayout>
</template>
