<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * Asks for a reason, then posts { reason } to `url` (cancel / reject / close short).
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    url: { type: String, default: null },
    title: { type: String, required: true },
    message: { type: String, default: null },
    confirmLabel: { type: String, default: 'Confirm' },
    danger: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);

const form = useForm({ reason: '' });
watch(
    () => props.show,
    (open) => open && (form.reset(), form.clearErrors()),
);

function submit() {
    form.post(props.url, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <AppModal :show="show" :title="title" @close="emit('close')">
        <p v-if="message" class="mb-3 text-sm text-slate-600">{{ message }}</p>
        <FormInput v-model="form.reason" label="Reason" required multiline :rows="3" maxlength="500" :error="form.errors.reason" />
        <p v-if="form.errors.items" class="mt-2 text-sm text-red-700">{{ form.errors.items }}</p>
        <template #footer>
            <AppButton variant="secondary" @click="emit('close')">Back</AppButton>
            <AppButton :variant="danger ? 'danger' : 'primary'" :loading="form.processing" :disabled="form.reason.trim().length < 5" @click="submit">{{ confirmLabel }}</AppButton>
        </template>
    </AppModal>
</template>
