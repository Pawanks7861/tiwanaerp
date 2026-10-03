<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';

defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
});
</script>

<template>
    <AppLayout title="My profile">
        <PageHeader title="My profile" subtitle="Your sign-in details. Roles are managed by your company administrator." />

        <div class="grid gap-4 lg:grid-cols-2">
            <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
            <UpdatePasswordForm />
        </div>
    </AppLayout>
</template>
