<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    delegations: { type: Array, required: true },
    users: { type: Array, required: true },
    documentTypes: { type: Array, required: true },
    can: { type: Object, required: true },
});

const form = useForm({
    delegate_id: null,
    starts_at: '',
    ends_at: '',
    document_types: [],
    reason: '',
});

function submit() {
    form.post(route('approvals.delegations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function revoke(id) {
    router.delete(route('approvals.delegations.destroy', id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Approval delegations">
        <PageHeader title="Approval delegations" subtitle="Let a colleague approve on your behalf for a date range." :back="route('approvals.index')" />

        <div class="space-y-4">
            <AppCard title="New delegation">
                <div class="grid gap-4 sm:grid-cols-2">
                    <SearchSelect v-model="form.delegate_id" label="Delegate" required :options="users" :error="form.errors.delegate_id" />
                    <FormInput v-model="form.starts_at" type="datetime-local" label="Starts" required :error="form.errors.starts_at" />
                    <FormInput v-model="form.ends_at" type="datetime-local" label="Ends" required :error="form.errors.ends_at" />
                    <FormInput v-model="form.reason" label="Reason" class="sm:col-span-2" maxlength="500" :error="form.errors.reason" />
                </div>
                <div class="mt-4 flex justify-end">
                    <AppButton :loading="form.processing" @click="submit">Save delegation</AppButton>
                </div>
            </AppCard>

            <AppCard title="Your delegations" :padded="false">
                <ul v-if="delegations.length" class="divide-y divide-line text-sm">
                    <li v-for="row in delegations" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <div>
                            <p class="font-medium">{{ row.delegator }} → {{ row.delegate }}</p>
                            <p class="text-xs text-slate-500">{{ formatDateTime(row.starts_at) }} – {{ formatDateTime(row.ends_at) }}<span v-if="!row.is_active" class="ml-2 text-red-600">Revoked</span></p>
                        </div>
                        <AppButton v-if="row.is_active" size="sm" variant="ghost" @click="revoke(row.id)">Revoke</AppButton>
                    </li>
                </ul>
                <p v-else class="p-4 text-sm text-slate-500">No delegations yet.</p>
            </AppCard>

            <p class="text-xs text-slate-500">
                <Link :href="route('approvals.index')" class="text-brand-700 hover:underline">Back to approvals inbox</Link>
            </p>
        </div>
    </AppLayout>
</template>
