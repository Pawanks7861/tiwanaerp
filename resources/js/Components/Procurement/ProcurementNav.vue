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
        { key: 'material-requests', label: 'Material requests', short: 'MRs', route: 'projects.material-requests.index', permission: 'material_requests.view' },
        { key: 'rfqs', label: 'RFQs', short: 'RFQs', route: 'projects.rfqs.index', permission: 'rfq.view' },
        { key: 'purchase-orders', label: 'Purchase orders', short: 'POs', route: 'projects.purchase-orders.index', permission: 'purchase.view' },
        { key: 'grns', label: 'Goods receipts', short: 'GRNs', route: 'projects.grns.index', permission: 'grn.view' },
    ].filter((item) => can(item.permission)),
);
</script>

<template>
    <nav class="mb-4 flex max-w-full overflow-x-auto rounded-lg border border-line bg-white p-0.5 shadow-sm sm:inline-flex" aria-label="Procurement">
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
