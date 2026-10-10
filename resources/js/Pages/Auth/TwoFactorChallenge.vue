<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
});

const submit = () => {
    form.post(route('two-factor.verify'), {
        onFinish: () => form.reset('code'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Two-factor authentication" />

        <h2 class="text-xl font-semibold text-slate-900">Check your authenticator</h2>
        <p class="mt-1 text-sm text-slate-500">Enter the 6-digit code, or one of your recovery codes.</p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <FormInput v-model="form.code" label="Authentication code" autocomplete="one-time-code" :error="form.errors.code" />
            <AppButton type="submit" class="w-full" :loading="form.processing">Continue</AppButton>
        </form>
    </GuestLayout>
</template>
