<script setup>
import { dismissToast, toast, toasts } from '@/lib/toast';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, watch } from 'vue';
import Icon from './Icon.vue';

const page = usePage();

watch(
    () => page.props.flash,
    (flash) => {
        toast.success(flash?.success);
        toast.error(flash?.error);
    },
    { immediate: true },
);

// Errors raised by services that have no input on the page (approval, "in use", status rules).
const GENERAL_KEYS = ['approval', 'record', 'site', 'member', 'status', 'company_id', 'boq', 'section', 'rate_analysis', 'budget', 'task', 'milestone',
    'material_request', 'rfq', 'quotation', 'comparison', 'purchase_order', 'grn', 'document', 'issue', 'transfer', 'return', 'adjustment'];
const removeListener = router.on('error', (event) => {
    const errors = event.detail.errors ?? {};
    GENERAL_KEYS.forEach((key) => errors[key] && toast.error(errors[key]));
});

onBeforeUnmount(removeListener);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-3 z-[60] flex flex-col items-center gap-2 px-3 sm:top-4 sm:right-4 sm:left-auto sm:items-end">
        <TransitionGroup
            enter-active-class="duration-200 ease-out"
            enter-from-class="-translate-y-2 opacity-0"
            leave-active-class="duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="item in toasts"
                :key="item.id"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-white px-4 py-3 text-sm shadow-lg"
                :class="item.type === 'error' ? 'border-red-200' : 'border-emerald-200'"
                role="status"
            >
                <Icon :name="item.type === 'error' ? 'warning' : 'check-circle'" :class="item.type === 'error' ? 'text-red-500' : 'text-emerald-500'" />
                <p class="flex-1 text-slate-700">{{ item.message }}</p>
                <button type="button" class="text-slate-400 hover:text-slate-600" aria-label="Dismiss" @click="dismissToast(item.id)">
                    <Icon name="close" :size="16" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
