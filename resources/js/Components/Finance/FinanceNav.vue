<script setup>
import { usePermissions } from '@/lib/permissions';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    projectId: { type: Number, required: true },
    active: { type: String, required: true },
});

const { can } = usePermissions();

const items = computed(() =>
    [
        can('expenses.view') && { key: 'expenses', label: 'Expenses', short: 'Expenses', route: 'projects.expenses.index' },
        can('petty_cash.view') && { key: 'petty-cash', label: 'Petty cash', short: 'Petty cash', route: 'projects.petty-cash.index' },
        can('billing.view') && { key: 'ra-bills', label: 'Client billing', short: 'RA bills', route: 'projects.ra-bills.index' },
        can('vendor_bills.view') && { key: 'vendor-bills', label: 'Vendor bills', short: 'Vendor bills', route: 'projects.vendor-bills.index' },
        can('payments.view') && { key: 'payments', label: 'Payments', short: 'Payments', route: 'projects.payments.index' },
        (can('billing.view') || can('subcontract.view')) && { key: 'retention', label: 'Retention', short: 'Retention', route: 'projects.retention.index' },
        can('payments.view') && { key: 'cash-flow', label: 'Cash flow', short: 'Cash flow', route: 'projects.cash-flow' },
    ].filter(Boolean),
);
</script>

<template>
    <nav class="mb-4 flex max-w-full overflow-x-auto rounded-lg border border-line bg-white p-0.5 shadow-sm sm:inline-flex" aria-label="Finance">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="route(item.route, props.projectId)"
            class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap"
            :class="active === item.key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
            :aria-current="active === item.key ? 'page' : undefined"
        >
            <span class="sm:hidden">{{ item.short }}</span><span class="hidden sm:inline">{{ item.label }}</span>
        </Link>
    </nav>
</template>
