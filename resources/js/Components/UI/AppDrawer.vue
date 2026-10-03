<script setup>
import { onBeforeUnmount, watch } from 'vue';
import Icon from './Icon.vue';

/**
 * Side panel for forms: slides in from the right on desktop, full screen on phones.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    width: { type: String, default: 'max-w-xl' },
});

const emit = defineEmits(['close']);

const onKey = (e) => {
    if (e.key === 'Escape' && props.show) {
        emit('close');
    }
};

watch(
    () => props.show,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) {
            document.addEventListener('keydown', onKey);
        } else {
            document.removeEventListener('keydown', onKey);
        }
    },
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-40 bg-slate-900/40" @click="emit('close')" />
        </Transition>
        <Transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="translate-x-full"
            leave-active-class="duration-150 ease-in"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="show"
                class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl"
                :class="width"
                role="dialog"
                aria-modal="true"
            >
                <header class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-slate-900">{{ title }}</h2>
                        <p v-if="subtitle" class="mt-0.5 text-xs text-slate-500">{{ subtitle }}</p>
                    </div>
                    <button
                        type="button"
                        class="-m-1 rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Close"
                        @click="emit('close')"
                    >
                        <Icon name="close" />
                    </button>
                </header>
                <div class="flex-1 overflow-y-auto px-5 py-5">
                    <slot />
                </div>
                <footer v-if="$slots.footer" class="flex gap-2 border-t border-line bg-slate-50 px-5 py-3 sm:justify-end">
                    <slot name="footer" />
                </footer>
            </aside>
        </Transition>
    </Teleport>
</template>
