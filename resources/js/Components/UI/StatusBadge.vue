<script setup>
import { computed } from 'vue';
import AppBadge from './AppBadge.vue';

/**
 * Consistent colours for document and record statuses across modules.
 */
const props = defineProps({
    status: { type: [String, Boolean], required: true },
    label: { type: String, default: null },
});

const colors = {
    active: 'green',
    approved: 'green',
    completed: 'blue',
    closed: 'slate',
    planning: 'brand',
    draft: 'slate',
    pending: 'amber',
    submitted: 'amber',
    reviewed: 'blue',
    on_hold: 'orange',
    sent_back: 'orange',
    rejected: 'red',
    cancelled: 'red',
    inactive: 'slate',
    revised: 'slate',
    superseded: 'slate',
    not_started: 'slate',
    in_progress: 'brand',
    delayed: 'red',
    partially_ordered: 'brand',
    ordered: 'blue',
    received: 'green',
    partially_received: 'brand',
    dispatched: 'blue',
    closed_short: 'orange',
    sent: 'blue',
    quotes_received: 'brand',
    evaluated: 'amber',
    invited: 'slate',
    responded: 'green',
    marked: 'amber',
    certified: 'green',
    partially_paid: 'brand',
    paid: 'blue',
    achieved: 'green',
    available: 'green',
    assigned: 'brand',
    under_repair: 'orange',
    disposed: 'slate',
    returned: 'slate',
    open: 'amber',
    posted: 'green',
    requested: 'amber',
    scheduled: 'blue',
    under_review: 'blue',
    resolved: 'brand',
    verified: 'green',
    archived: 'slate',
    withdrawn: 'slate',
    passed: 'green',
    failed: 'red',
    synced: 'green',
    not_synced: 'slate',
    needs_mapping: 'orange',
    conflict: 'orange',
    cancel_pending: 'amber',
    reversal_synced: 'blue',
    conditional: 'orange',
    minor: 'slate',
    major: 'orange',
    critical: 'red',
};

const key = computed(() => (props.status === true ? 'active' : props.status === false ? 'inactive' : props.status));
const text = computed(() => props.label ?? String(key.value).replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()));
</script>

<template>
    <AppBadge :color="colors[key] ?? 'slate'" dot>{{ text }}</AppBadge>
</template>
