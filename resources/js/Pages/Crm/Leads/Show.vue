<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    lead: { type: Object, required: true },
    activities: { type: Array, required: true },
    quotations: { type: Array, required: true },
    statuses: { type: Array, required: true },
    activityTypes: { type: Array, required: true },
    can: { type: Object, required: true },
    now: { type: String, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['lead', 'status'].map((k) => page.props.errors?.[k]).find(Boolean));
const closed = computed(() => ['won', 'lost'].includes(props.lead.status));
const manualStatuses = computed(() => props.statuses.filter((s) => s.value !== 'won'));

const activity = useForm({ type: 'call', activity_at: props.now, summary: '', next_follow_up: null });
function logActivity() {
    activity.post(route('crm.leads.activities.store', props.lead.id), { preserveScroll: true, onSuccess: () => activity.reset('summary', 'next_follow_up') });
}

const changing = ref(false);
const statusForm = useForm({ status: props.lead.status, lost_reason: '' });
function saveStatus() {
    statusForm
        .transform((d) => ({ ...d, lost_reason: d.status === 'lost' ? d.lost_reason : null }))
        .patch(route('crm.leads.status', props.lead.id), { preserveScroll: true, onSuccess: () => (changing.value = false) });
}

const deleting = ref(false);
const processing = ref(false);
function destroy() {
    processing.value = true;
    router.delete(route('crm.leads.destroy', props.lead.id), { onFinish: () => ((processing.value = false), (deleting.value = false)) });
}
</script>

<template>
    <AppLayout :title="lead.lead_number">
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('crm.leads.index')" class="text-xs font-medium text-slate-500 hover:text-slate-700">Leads</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h1 class="text-lg font-semibold text-slate-900">{{ lead.name }}</h1>
                            <span class="font-mono text-xs text-slate-500">{{ lead.lead_number }}</span>
                            <StatusBadge :status="lead.status" :label="lead.status_label" />
                        </div>
                        <p v-if="lead.company_name" class="mt-1 text-sm text-slate-800">{{ lead.company_name }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div v-if="lead.mobile"><dt class="text-slate-500">Mobile</dt><dd><a :href="`tel:${lead.mobile}`" class="text-brand-700">{{ lead.mobile }}</a></dd></div>
                            <div v-if="lead.email"><dt class="text-slate-500">Email</dt><dd class="truncate">{{ lead.email }}</dd></div>
                            <div v-if="lead.source"><dt class="text-slate-500">Source</dt><dd>{{ lead.source }}</dd></div>
                            <div v-if="lead.project_type"><dt class="text-slate-500">Project type</dt><dd>{{ lead.project_type }}</dd></div>
                            <div v-if="lead.location || lead.state"><dt class="text-slate-500">Location</dt><dd>{{ [lead.location, lead.state].filter(Boolean).join(', ') }}</dd></div>
                            <div v-if="lead.estimated_value"><dt class="text-slate-500">Est. value</dt><dd>{{ formatMoney(lead.estimated_value) }}</dd></div>
                            <div v-if="lead.expected_close_date"><dt class="text-slate-500">Expected close</dt><dd>{{ formatDate(lead.expected_close_date) }}</dd></div>
                            <div><dt class="text-slate-500">Assigned to</dt><dd>{{ lead.assignee ?? '—' }}</dd></div>
                            <div v-if="lead.client"><dt class="text-slate-500">Client</dt><dd>{{ lead.client.company_name }}</dd></div>
                        </dl>
                        <p v-if="lead.status === 'lost' && lead.lost_reason" class="mt-2 text-sm text-red-700">Lost: {{ lead.lost_reason }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.quote" size="sm" icon="plus" :href="route('crm.quotations.create', { lead_id: lead.id })">Quotation</AppButton>
                        <AppButton v-if="can.update && lead.status !== 'won'" size="sm" variant="secondary" @click="changing = true">Change status</AppButton>
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('crm.leads.edit', lead.id)">Edit</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete lead" @click="deleting = true" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <p v-if="lead.notes" class="border-t border-line px-4 py-3 text-sm whitespace-pre-line text-slate-700 sm:px-5">{{ lead.notes }}</p>
            </AppCard>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <AppCard title="Activity" class="lg:col-span-2" :padded="false">
                    <form v-if="can.update && !closed" class="grid gap-3 border-b border-line p-4 sm:grid-cols-4" @submit.prevent="logActivity">
                        <FormSelect v-model="activity.type" label="Type" required :options="activityTypes" :error="activity.errors.type" />
                        <FormInput v-model="activity.activity_at" type="datetime-local" label="When" required :error="activity.errors.activity_at" />
                        <FormInput v-model="activity.next_follow_up" type="date" label="Next follow-up" :error="activity.errors.next_follow_up" class="sm:col-span-2" />
                        <FormInput v-model="activity.summary" label="Summary" required multiline :rows="2" maxlength="1000" class="sm:col-span-3" :error="activity.errors.summary" />
                        <div class="flex items-end"><AppButton type="submit" size="sm" :loading="activity.processing" block>Log</AppButton></div>
                    </form>
                    <ul v-if="activities.length" class="divide-y divide-line">
                        <li v-for="a in activities" :key="a.id" class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700">{{ a.type_label }}</span>
                                <span>{{ formatDateTime(a.activity_at) }}</span>
                                <span v-if="a.by">· {{ a.by }}</span>
                                <span v-if="a.next_follow_up" class="text-amber-700">· follow up {{ formatDate(a.next_follow_up) }}</span>
                            </div>
                            <p class="mt-1 text-sm whitespace-pre-line text-slate-800">{{ a.summary }}</p>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">No activity logged yet.</p>
                </AppCard>
                <AppCard title="Quotations" :padded="false">
                    <ul v-if="quotations.length" class="divide-y divide-line">
                        <li v-for="q in quotations" :key="q.id" class="px-4 py-3">
                            <Link :href="route('crm.quotations.show', q.id)" class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ q.number }}</Link>
                            <div class="mt-0.5 flex items-center justify-between gap-2">
                                <span class="min-w-0 truncate text-sm text-slate-800">{{ q.title }}</span>
                                <StatusBadge :status="q.status" :label="q.status_label" />
                            </div>
                            <p class="text-xs text-slate-500 tabular">{{ formatMoney(q.total_amount) }}</p>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">No quotations yet.</p>
                </AppCard>
            </div>
        </div>

        <AppModal :show="changing" title="Change lead status" @close="changing = false">
            <div class="grid gap-4">
                <FormSelect v-model="statusForm.status" label="Status" required :options="manualStatuses" :error="statusForm.errors.status" />
                <FormInput v-if="statusForm.status === 'lost'" v-model="statusForm.lost_reason" label="Why was it lost?" required multiline :rows="2" maxlength="500" :error="statusForm.errors.lost_reason" />
                <p class="text-xs text-slate-500">A lead becomes won when its quotation is accepted.</p>
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="changing = false">Cancel</AppButton>
                <AppButton :loading="statusForm.processing" @click="saveStatus">Save</AppButton>
            </template>
        </AppModal>
        <ConfirmDialog :show="deleting" title="Delete this lead?" message="The lead and its activity log are removed." confirm-label="Delete" danger :processing="processing" @close="deleting = false" @confirm="destroy" />
    </AppLayout>
</template>
