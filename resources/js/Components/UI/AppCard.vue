<script setup>
defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <section class="rounded-xl border border-line bg-white shadow-sm">
        <header
            v-if="title || $slots.header || $slots.actions"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-5"
        >
            <slot name="header">
                <div class="min-w-0">
                    <h2 class="truncate text-sm font-semibold text-slate-900">{{ title }}</h2>
                    <p v-if="subtitle" class="mt-0.5 text-xs text-slate-500">{{ subtitle }}</p>
                </div>
            </slot>
            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div :class="padded ? 'p-4 sm:p-5' : ''">
            <slot />
        </div>
        <footer v-if="$slots.footer" class="border-t border-line px-4 py-3 sm:px-5">
            <slot name="footer" />
        </footer>
    </section>
</template>
