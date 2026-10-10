<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import { toast } from '@/lib/toast';
import { router, useForm } from '@inertiajs/vue3';

defineProps({
    twoFactor: { type: Object, required: true },
});

const confirmForm = useForm({ code: '' });
const passwordForm = useForm({ current_password: '' });

const start = () => {
    router.post(route('two-factor.store'), {}, { preserveScroll: true });
};

const confirm = () => {
    confirmForm.post(route('two-factor.confirm'), {
        preserveScroll: true,
        onSuccess: () => {
            confirmForm.reset();
            toast.success('Two-factor authentication is on.');
        },
    });
};

const regenerate = () => {
    passwordForm.post(route('two-factor.recovery'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
};

const disable = () => {
    passwordForm.delete(route('two-factor.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
            toast.success('Two-factor authentication is off.');
        },
    });
};
</script>

<template>
    <AppCard title="Two-factor authentication" subtitle="Use an authenticator app. Privileged accounts should turn this on.">
        <div v-if="twoFactor.setup" class="space-y-3 text-sm">
            <p>Add this key to your authenticator app, then confirm with a code. Recovery codes are shown only now.</p>
            <p class="break-all rounded-md bg-slate-100 px-3 py-2 font-mono text-xs">{{ twoFactor.setup.secret }}</p>
            <p class="break-all text-xs text-slate-500">{{ twoFactor.setup.otpauth }}</p>
            <ul class="grid grid-cols-2 gap-1 font-mono text-xs">
                <li v-for="code in twoFactor.setup.recovery_codes" :key="code">{{ code }}</li>
            </ul>
        </div>

        <ul v-if="twoFactor.recoveryCodes?.length" class="mt-3 grid grid-cols-2 gap-1 font-mono text-xs">
            <li v-for="code in twoFactor.recoveryCodes" :key="code">{{ code }}</li>
        </ul>

        <form v-if="!twoFactor.enabled" class="mt-4 space-y-3" @submit.prevent="confirm">
            <FormInput v-model="confirmForm.code" label="Confirmation code" :error="confirmForm.errors.code" />
            <AppButton type="submit" :loading="confirmForm.processing" :disabled="!twoFactor.pending && !twoFactor.setup">Confirm</AppButton>
        </form>

        <form v-else class="mt-4 space-y-3" @submit.prevent>
            <FormInput v-model="passwordForm.current_password" label="Current password" type="password" :error="passwordForm.errors.current_password" />
            <div class="flex flex-wrap gap-2">
                <AppButton type="button" variant="secondary" :loading="passwordForm.processing" @click="regenerate">Regenerate recovery codes</AppButton>
                <AppButton type="button" variant="danger" :loading="passwordForm.processing" @click="disable">Turn off</AppButton>
            </div>
        </form>

        <template #footer>
            <AppButton v-if="!twoFactor.enabled" type="button" variant="secondary" @click="start">Set up authenticator</AppButton>
            <p v-else class="text-sm text-slate-600">Two-factor authentication is on for this account.</p>
        </template>
    </AppCard>
</template>
