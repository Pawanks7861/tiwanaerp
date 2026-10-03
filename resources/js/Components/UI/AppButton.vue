<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    variant: { type: String, default: 'primary' }, // primary | secondary | danger | ghost | accent
    size: { type: String, default: 'md' }, // sm | md | lg
    type: { type: String, default: 'button' },
    href: { type: String, default: null },
    method: { type: String, default: 'get' },
    icon: { type: String, default: null },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
});

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 select-none',
    {
        sm: 'h-8 px-3 text-xs',
        md: 'h-10 px-4 text-sm',
        lg: 'h-12 px-5 text-base',
    }[props.size],
    {
        primary: 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 focus-visible:ring-brand-500',
        accent: 'bg-accent-500 text-white shadow-sm hover:bg-accent-600 focus-visible:ring-accent-400',
        secondary: 'border border-line bg-white text-slate-700 shadow-sm hover:bg-slate-50 focus-visible:ring-brand-500',
        danger: 'bg-red-600 text-white shadow-sm hover:bg-red-700 focus-visible:ring-red-500',
        ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus-visible:ring-brand-500',
    }[props.variant],
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <Link v-if="href && !disabled" :href="href" :method="method" :as="method === 'get' ? 'a' : 'button'" :class="classes">
        <Icon v-if="icon" :name="icon" :size="size === 'sm' ? 16 : 18" />
        <slot />
    </Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading">
        <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z" />
        </svg>
        <Icon v-else-if="icon" :name="icon" :size="size === 'sm' ? 16 : 18" />
        <slot />
    </button>
</template>
