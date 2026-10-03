<script setup>
import AppButton from './AppButton.vue';
import AppModal from './AppModal.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Are you sure?' },
    message: { type: String, default: null },
    confirmLabel: { type: String, default: 'Confirm' },
    danger: { type: Boolean, default: true },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);
</script>

<template>
    <AppModal :show="show" :title="title" max-width="md" @close="emit('close')">
        <p v-if="message" class="text-sm text-slate-600">{{ message }}</p>
        <slot />
        <template #footer>
            <AppButton variant="secondary" @click="emit('close')">Cancel</AppButton>
            <AppButton :variant="danger ? 'danger' : 'primary'" :loading="processing" @click="emit('confirm')">
                {{ confirmLabel }}
            </AppButton>
        </template>
    </AppModal>
</template>
