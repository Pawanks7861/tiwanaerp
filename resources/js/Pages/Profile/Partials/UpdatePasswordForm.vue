<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import { toast } from '@/lib/toast';
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            toast.success('Password changed.');
        },
        onError: () => {
            if (form.errors.password) form.reset('password', 'password_confirmation');
            if (form.errors.current_password) form.reset('current_password');
        },
    });
};
</script>

<template>
    <AppCard title="Change password" subtitle="Use a long, random password.">
        <form id="password-form" class="space-y-4" @submit.prevent="updatePassword">
            <FormInput v-model="form.current_password" label="Current password" type="password" autocomplete="current-password" :error="form.errors.current_password" />
            <FormInput v-model="form.password" label="New password" type="password" autocomplete="new-password" :error="form.errors.password" />
            <FormInput v-model="form.password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" :error="form.errors.password_confirmation" />
        </form>
        <template #footer>
            <AppButton type="submit" form="password-form" :loading="form.processing">Change password</AppButton>
        </template>
    </AppCard>
</template>
