<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen">
        <aside class="relative hidden w-[42%] flex-col justify-between overflow-hidden bg-navy-900 p-10 text-white lg:flex">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-accent-500 text-lg font-bold">B</span>
                <span class="text-xl font-semibold tracking-tight">BUILDIFY<span class="text-accent-400">360</span></span>
            </div>
            <div class="max-w-md">
                <h1 class="text-3xl font-semibold leading-tight">Every project, site and approval in one place.</h1>
                <p class="mt-4 text-sm leading-relaxed text-slate-300">
                    Construction ERP for planning, procurement, stores, subcontractors, billing and finance — built for Indian GST.
                </p>
            </div>
            <p class="text-xs text-slate-400">&copy; {{ new Date().getFullYear() }} BUILDIFY360</p>
            <div class="pointer-events-none absolute -bottom-24 -right-24 h-72 w-72 rounded-full bg-brand-600/30 blur-3xl" />
        </aside>

        <main class="flex flex-1 flex-col items-center justify-center bg-surface px-4 py-10 sm:px-6">
            <Link href="/" class="mb-8 flex items-center gap-2 lg:hidden">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-accent-500 font-bold text-white">B</span>
                <span class="text-lg font-semibold tracking-tight text-navy-900">BUILDIFY<span class="text-accent-500">360</span></span>
            </Link>
            <div class="w-full max-w-md rounded-xl border border-line bg-white p-6 shadow-sm sm:p-8">
                <slot />
            </div>
        </main>
    </div>
</template>
