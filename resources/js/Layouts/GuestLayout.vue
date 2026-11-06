<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const appName = computed(() => usePage().props.app?.name || 'Tiwana Construction ERP');
</script>

<template>
    <Head>
        <link head-key="favicon" rel="icon" href="/favicon.svg" />
    </Head>
    <div class="flex min-h-screen">
        <aside class="relative hidden w-[42%] flex-col justify-between overflow-hidden bg-navy-900 p-10 text-white lg:flex">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-500 text-lg font-bold">T</span>
                <span class="text-xl font-semibold leading-tight tracking-tight">{{ appName }}</span>
            </div>
            <div class="max-w-md">
                <h1 class="text-3xl font-semibold leading-tight">Every project, site and approval in one place.</h1>
                <p class="mt-4 text-sm leading-relaxed text-slate-300">
                    Construction ERP for planning, procurement, stores, subcontractors, billing and finance — built for Indian GST.
                </p>
            </div>
            <p class="text-xs text-slate-400">&copy; {{ new Date().getFullYear() }} {{ appName }}</p>
            <div class="pointer-events-none absolute -bottom-24 -right-24 h-72 w-72 rounded-full bg-brand-600/30 blur-3xl" />
        </aside>

        <main class="flex flex-1 flex-col items-center justify-center bg-surface px-4 py-10 sm:px-6">
            <Link href="/" class="mb-8 flex max-w-md items-center gap-2 lg:hidden">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent-500 font-bold text-white">T</span>
                <span class="text-base font-semibold leading-tight tracking-tight text-navy-900">{{ appName }}</span>
            </Link>
            <div class="w-full max-w-md rounded-xl border border-line bg-white p-6 shadow-sm sm:p-8">
                <slot />
            </div>
        </main>
    </div>
</template>
