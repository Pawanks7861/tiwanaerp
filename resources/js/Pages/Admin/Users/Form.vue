<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    user: { type: Object, default: null },
    roles: { type: Array, required: true },
    canEditProfile: { type: Boolean, default: true },
});

const editing = !!props.user;

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    mobile: props.user?.mobile ?? '',
    password: '',
    password_confirmation: '',
    roles: props.user?.roles ?? [],
});

function submit() {
    const options = { onFinish: () => form.reset('password', 'password_confirmation') };
    if (editing) {
        form.put(route('admin.users.update', props.user.id), options);
    } else {
        form.post(route('admin.users.store'), options);
    }
}
</script>

<template>
    <AppLayout :title="editing ? `Edit ${user.name}` : 'Add user'">
        <PageHeader
            :title="editing ? `Edit ${user.name}` : 'Add user'"
            :subtitle="editing ? user.email : 'If the email already has an account (e.g. in another company), that person is added to this company.'"
            :back="route('admin.users.index')"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Profile">
                <div v-if="editing && !canEditProfile" class="mb-4 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                    <Icon name="info" class="mt-0.5 text-amber-500" />
                    This person also works for another company, so only they can change their name, email or password. You can still change their roles here.
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.name" label="Full name" required maxlength="150" :disabled="editing && !canEditProfile" :error="form.errors.name" autofocus />
                    <FormInput
                        v-model="form.email"
                        label="Email"
                        type="email"
                        required
                        autocomplete="off"
                        :disabled="editing && !canEditProfile"
                        :error="form.errors.email"
                    />
                    <FormInput
                        v-model="form.mobile"
                        label="Mobile"
                        type="tel"
                        inputmode="numeric"
                        maxlength="10"
                        placeholder="98XXXXXXXX"
                        :disabled="editing && !canEditProfile"
                        :error="form.errors.mobile"
                    />
                </div>
            </AppCard>

            <AppCard v-if="!editing || canEditProfile" title="Password" :subtitle="editing ? 'Leave blank to keep the current password.' : 'Required for a new account. Not used if the email already has an account.'">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.password" label="Password" type="password" autocomplete="new-password" :error="form.errors.password" help="At least 8 characters with letters and numbers." />
                    <FormInput v-model="form.password_confirmation" label="Confirm password" type="password" autocomplete="new-password" />
                </div>
            </AppCard>

            <AppCard title="Roles in this company" subtitle="Roles decide what the user can see and do. You can only assign roles whose permissions you hold.">
                <p v-if="form.errors.roles" class="mb-3 text-sm text-red-600">{{ form.errors.roles }}</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="role in roles"
                        :key="role.value"
                        class="flex cursor-pointer gap-3 rounded-lg border p-3 transition"
                        :class="form.roles.includes(role.value) ? 'border-brand-300 bg-brand-50' : 'border-line hover:bg-slate-50'"
                    >
                        <input v-model="form.roles" type="checkbox" :value="role.value" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                        <span>
                            <span class="block text-sm font-medium text-slate-800">{{ role.label }}</span>
                            <span v-if="role.description" class="block text-xs text-slate-500">{{ role.description }}</span>
                        </span>
                    </label>
                </div>
            </AppCard>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <AppButton variant="secondary" :href="route('admin.users.index')">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing">{{ editing ? 'Save changes' : 'Add user' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
