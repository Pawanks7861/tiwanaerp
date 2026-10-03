<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import AppTabs from '@/Components/UI/AppTabs.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatMoney, timeAgo } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    toAct: { type: Array, required: true },
    submitted: { type: Array, required: true },
});

const tab = ref('inbox');
const tabs = computed(() => [
    { key: 'inbox', label: 'Waiting for me', count: props.toAct.length },
    { key: 'submitted', label: 'Submitted by me', count: props.submitted.length },
]);
const rows = computed(() => (tab.value === 'inbox' ? props.toAct : props.submitted));

const ACTIONS = {
    approve: { title: 'Approve', route: 'approvals.approve', button: 'Approve', variant: 'primary', commentRequired: false },
    reject: { title: 'Reject', route: 'approvals.reject', button: 'Reject', variant: 'danger', commentRequired: true },
    sendBack: { title: 'Send back for changes', route: 'approvals.send-back', button: 'Send back', variant: 'secondary', commentRequired: true },
    cancel: { title: 'Withdraw request', route: 'approvals.cancel', button: 'Withdraw', variant: 'danger', commentRequired: false },
};

const active = ref(null);
const form = useForm({ comments: '' });

function open(request, action) {
    form.reset();
    form.clearErrors();
    active.value = { request, ...ACTIONS[action] };
}

function confirm() {
    form.post(route(active.value.route, active.value.request.id), {
        preserveScroll: true,
        onSuccess: () => (active.value = null),
    });
}

const typeLabel = (type) => type.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
</script>

<template>
    <AppLayout title="Approvals">
        <PageHeader title="Approvals" subtitle="Documents waiting for your decision, and the ones you have submitted." />

        <AppCard :padded="false">
            <div class="border-b border-line px-2 sm:px-3">
                <AppTabs v-model="tab" :items="tabs" />
            </div>

            <ul v-if="rows.length" class="divide-y divide-line">
                <li v-for="request in rows" :key="request.id" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:px-5">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link v-if="request.url" :href="request.url" class="font-medium text-brand-700 hover:underline">{{ request.title }}</Link>
                            <span v-else class="font-medium text-slate-900">{{ request.title }}</span>
                            <AppBadge color="brand">{{ typeLabel(request.document_type) }}</AppBadge>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Level {{ request.level }} of {{ request.levels }}<template v-if="request.step_name"> · {{ request.step_name }}</template>
                            <template v-if="request.submitted_by"> · by {{ request.submitted_by }}</template>
                            · {{ timeAgo(request.submitted_at) }}
                        </p>
                    </div>
                    <div v-if="request.amount !== null" class="text-sm font-semibold text-slate-900 tabular sm:text-right">{{ formatMoney(request.amount) }}</div>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="tab === 'inbox'">
                            <AppButton size="sm" @click="open(request, 'approve')">Approve</AppButton>
                            <AppButton size="sm" variant="secondary" @click="open(request, 'sendBack')">Send back</AppButton>
                            <AppButton size="sm" variant="ghost" class="text-red-600" @click="open(request, 'reject')">Reject</AppButton>
                        </template>
                        <AppButton v-else size="sm" variant="secondary" @click="open(request, 'cancel')">Withdraw</AppButton>
                    </div>
                </li>
            </ul>
            <EmptyState
                v-else
                icon="check-circle"
                :title="tab === 'inbox' ? 'Nothing waiting for you' : 'No pending submissions'"
                :description="tab === 'inbox' ? 'Documents that need your approval will appear here.' : 'Documents you submit for approval appear here until they are decided.'"
            />
        </AppCard>

        <AppModal :show="!!active" :title="active ? `${active.title}: ${active.request.title}` : ''" @close="active = null">
            <form id="approval-form" @submit.prevent="confirm">
                <FormInput
                    v-model="form.comments"
                    multiline
                    :rows="3"
                    :label="active?.commentRequired ? 'Reason' : 'Comments (optional)'"
                    :required="active?.commentRequired"
                    maxlength="2000"
                    :error="form.errors.comments || form.errors.approval"
                />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="active = null">Cancel</AppButton>
                <AppButton type="submit" form="approval-form" :variant="active?.variant" :loading="form.processing">{{ active?.button }}</AppButton>
            </template>
        </AppModal>
    </AppLayout>
</template>
