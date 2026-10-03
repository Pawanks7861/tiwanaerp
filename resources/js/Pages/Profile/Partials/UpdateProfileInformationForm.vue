<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <AppCard title="Profile information" subtitle="Your name and sign-in email.">
        <form id="profile-form" class="space-y-4" @submit.prevent="form.patch(route('profile.update'), { preserveScroll: true })">
            <FormInput v-model="form.name" label="Name" required maxlength="255" autocomplete="name" :error="form.errors.name" />
            <FormInput v-model="form.email" label="Email" type="email" required autocomplete="username" :error="form.errors.email" />

            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="text-sm text-slate-700">
                Your email address is unverified.
                <Link :href="route('verification.send')" method="post" as="button" class="font-medium text-brand-600 underline hover:text-brand-700">
                    Re-send the verification email.
                </Link>
                <p v-show="status === 'verification-link-sent'" class="mt-2 font-medium text-emerald-600">
                    A new verification link has been sent to your email address.
                </p>
            </div>
        </form>
        <template #footer>
            <AppButton type="submit" form="profile-form" :loading="form.processing">Save</AppButton>
        </template>
    </AppCard>
</template>
