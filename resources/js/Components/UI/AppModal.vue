<script setup>
import Modal from '@/Components/Modal.vue';
import Icon from './Icon.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: null },
    maxWidth: { type: String, default: 'lg' },
    closeable: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Modal :show="show" :max-width="maxWidth" :closeable="closeable" @close="emit('close')">
        <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
            <button
                v-if="closeable"
                type="button"
                class="-m-1 rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                aria-label="Close"
                @click="emit('close')"
            >
                <Icon name="close" />
            </button>
        </div>
        <div class="px-5 py-4">
            <slot />
        </div>
        <div v-if="$slots.footer" class="flex flex-col-reverse gap-2 border-t border-line bg-slate-50 px-5 py-3 sm:flex-row sm:justify-end">
            <slot name="footer" />
        </div>
    </Modal>
</template>
