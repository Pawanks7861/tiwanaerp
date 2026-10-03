<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    company: { type: Object, default: null },
    states: { type: Array, required: true },
});

const editing = !!props.company;
const c = props.company ?? {};

const form = useForm({
    name: c.name ?? '',
    legal_name: c.legal_name ?? '',
    code: c.code ?? '',
    gstin: c.gstin ?? '',
    pan: c.pan ?? '',
    state_code: c.state_code ?? '03',
    address: c.address ?? '',
    city: c.city ?? '',
    pincode: c.pincode ?? '',
    phone: c.phone ?? '',
    email: c.email ?? '',
    is_active: c.is_active ?? true,
    ...(editing ? {} : { admin_name: '', admin_email: '', admin_password: '' }),
});

function submit() {
    if (editing) {
        form.put(route('platform.companies.update', c.id));
    } else {
        form.post(route('platform.companies.store'), { onFinish: () => form.reset('admin_password') });
    }
}
</script>

<template>
    <AppLayout :title="editing ? c.name : 'New company'">
        <PageHeader
            :title="editing ? c.name : 'New company'"
            :subtitle="editing ? c.code : 'Default roles, masters, the current financial year and approval workflows are set up automatically.'"
            :back="route('platform.companies.index')"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Company">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.name" label="Display name" required maxlength="200" :error="form.errors.name" autofocus />
                    <FormInput v-model="form.code" label="Company code" required uppercase maxlength="20" :error="form.errors.code" help="Short unique code, e.g. TIWANA." />
                    <FormInput v-model="form.legal_name" label="Legal name" maxlength="200" :error="form.errors.legal_name" />
                    <FormSelect v-model="form.state_code" label="State" required :options="states" :error="form.errors.state_code" />
                    <FormInput v-model="form.gstin" label="GSTIN" uppercase maxlength="15" :error="form.errors.gstin" />
                    <FormInput v-model="form.pan" label="PAN" uppercase maxlength="10" :error="form.errors.pan" />
                    <FormInput v-model="form.address" label="Address" multiline :rows="2" class="sm:col-span-2" :error="form.errors.address" />
                    <FormInput v-model="form.city" label="City" maxlength="100" :error="form.errors.city" />
                    <FormInput v-model="form.pincode" label="PIN code" inputmode="numeric" maxlength="6" :error="form.errors.pincode" />
                    <FormInput v-model="form.phone" label="Phone" type="tel" maxlength="20" :error="form.errors.phone" />
                    <FormInput v-model="form.email" label="Email" type="email" :error="form.errors.email" />
                    <div class="sm:col-span-2">
                        <FormSwitch v-model="form.is_active" label="Active" description="Users of an inactive company cannot sign in to it." />
                    </div>
                </div>
            </AppCard>

            <AppCard v-if="!editing" title="Company administrator" subtitle="Gets the Company Admin role. An existing account with this email is reused.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.admin_name" label="Name" required maxlength="150" :error="form.errors.admin_name" />
                    <FormInput v-model="form.admin_email" label="Email" type="email" required :error="form.errors.admin_email" />
                    <FormInput
                        v-model="form.admin_password"
                        label="Password"
                        type="password"
                        autocomplete="new-password"
                        :error="form.errors.admin_password"
                        help="Required only when the email has no account yet."
                    />
                </div>
            </AppCard>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <AppButton variant="secondary" :href="route('platform.companies.index')">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing">{{ editing ? 'Save changes' : 'Create company' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
