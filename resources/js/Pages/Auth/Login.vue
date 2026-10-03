<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Sign in" />

        <h2 class="text-xl font-semibold text-slate-900">Sign in</h2>
        <p class="mt-1 text-sm text-slate-500">Accounts are created by your company administrator.</p>

        <div v-if="status" class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">
            {{ status }}
        </div>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <FormInput v-model="form.email" label="Email" type="email" required autofocus autocomplete="username" :error="form.errors.email" />
            <FormInput v-model="form.password" label="Password" type="password" required autocomplete="current-password" :error="form.errors.password" />

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="form.remember" type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-brand-600" />
                    Remember me
                </label>
                <Link v-if="canResetPassword" :href="route('password.request')" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                    Forgot password?
                </Link>
            </div>

            <AppButton type="submit" block :loading="form.processing">Sign in</AppButton>
        </form>
    </GuestLayout>
</template>
