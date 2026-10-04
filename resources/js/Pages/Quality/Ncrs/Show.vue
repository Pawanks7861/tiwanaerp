<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import QualityNav from '@/Components/Quality/QualityNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    ncr: { type: Object, required: true },
    attachments: { type: Array, required: true },
    audit: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['ncr', 'responsible_user_id', 'root_cause', 'corrective_action'].map((k) => page.props.errors?.[k]).find(Boolean));

const confirming = ref(null);
const processing = ref(false);
const ACTIONS = {
    start: { title: 'Start work on this NCR?', message: 'The responsible party begins the corrective action.', label: 'Start', danger: false, method: 'post', route: 'projects.ncrs.start' },
    close: { title: 'Close this NCR?', message: 'A closed NCR can never be changed again.', label: 'Close NCR', danger: false, method: 'post', route: 'projects.ncrs.close' },
    delete: { title: 'Delete this NCR?', message: 'Only an open NCR can be deleted. Its number is not reused.', label: 'Delete', danger: true, method: 'delete', route: 'projects.ncrs.destroy' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(action.route, [props.project.id, props.ncr.id]);
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const resolving = ref(false);
const resolveForm = useForm({ root_cause: props.ncr.root_cause ?? '', corrective_action: props.ncr.corrective_action ?? '' });
function resolve() {
    resolveForm.post(route('projects.ncrs.resolve', [props.project.id, props.ncr.id]), { preserveScroll: true, onSuccess: () => (resolving.value = false) });
}

const verifying = ref(false);
const verifyForm = useForm({ remarks: '' });
function verify() {
    verifyForm
        .transform((d) => ({ remarks: d.remarks || null }))
        .post(route('projects.ncrs.verify', [props.project.id, props.ncr.id]), { preserveScroll: true, onSuccess: () => (verifying.value = false) });
}
const reopening = ref(false);

const steps = computed(() => {
    const order = ['open', 'in_progress', 'resolved', 'verified', 'closed'];
    const current = order.indexOf(props.ncr.status);

    return [
        { key: 'open', label: 'Raised', by: props.ncr.raised_by, at: props.ncr.raised_at },
        { key: 'in_progress', label: 'In progress' },
        { key: 'resolved', label: 'Resolved', by: props.ncr.resolved_by, at: props.ncr.resolved_at },
        { key: 'verified', label: 'Verified', by: props.ncr.verified_by, at: props.ncr.verified_at },
        { key: 'closed', label: 'Closed', by: props.ncr.closed_by, at: props.ncr.closed_at },
    ].map((s, i) => ({ ...s, done: i < current || (i === current && s.key === 'closed'), current: i === current }));
});

const statusNote = computed(() => ({
    open: 'Assign a responsible person or subcontractor, then start the corrective work.',
    in_progress: props.ncr.verification_remarks
        ? `Sent back: ${props.ncr.verification_remarks}`
        : 'Record the root cause and corrective action to resolve it.',
    resolved: 'Awaiting verification by someone other than the person who resolved it.',
    verified: 'Verified. Close the NCR to finish it.',
    closed: 'Closed. This NCR can no longer be changed.',
})[props.ncr.status]);
</script>

<template>
    <ProjectLayout :project="project" active="quality" :title="ncr.ncr_number">
        <QualityNav :project-id="project.id" active="ncrs" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.ncrs.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">NCRs</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ ncr.ncr_number }}</h2>
                            <StatusBadge :status="ncr.status" :label="ncr.status_label" />
                            <StatusBadge :status="ncr.severity" :label="ncr.severity_label" />
                        </div>
                        <p class="mt-1 text-sm break-words whitespace-pre-line text-slate-800">{{ ncr.issue }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Location</dt><dd class="break-words">{{ ncr.location ?? '—' }}</dd></div>
                            <div><dt class="text-slate-500">Responsible</dt><dd>{{ ncr.responsible ?? 'Not assigned' }}</dd></div>
                            <div><dt class="text-slate-500">Target date</dt><dd :class="ncr.overdue ? 'font-medium text-red-700' : ''">{{ ncr.target_date ? formatDate(ncr.target_date) : '—' }}</dd></div>
                            <div>
                                <dt class="text-slate-500">Source</dt>
                                <dd>
                                    <Link v-if="ncr.inspection_id" :href="route('projects.inspections.show', [project.id, ncr.inspection_id])" class="text-brand-700 hover:underline">{{ ncr.inspection }}</Link>
                                    <span v-else>Manual</span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.ncrs.edit', [project.id, ncr.id])">Edit</AppButton>
                        <AppButton v-if="can.start" size="sm" @click="confirming = 'start'">Start</AppButton>
                        <AppButton v-if="can.resolve" size="sm" @click="resolving = true">Resolve</AppButton>
                        <AppButton v-if="can.verify" size="sm" @click="verifying = true">Verify</AppButton>
                        <AppButton v-if="can.reopen" size="sm" variant="secondary" @click="reopening = true">Send back</AppButton>
                        <AppButton v-if="can.close" size="sm" @click="confirming = 'close'">Close NCR</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete NCR" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
            </AppCard>

            <AppCard title="Workflow">
                <ol class="grid gap-3 sm:grid-cols-5">
                    <li v-for="step in steps" :key="step.key" class="rounded-lg border px-3 py-2" :class="step.current ? 'border-brand-300 bg-brand-50' : step.done ? 'border-green-200 bg-green-50' : 'border-line'">
                        <div class="text-sm font-medium" :class="step.current ? 'text-brand-800' : step.done ? 'text-green-800' : 'text-slate-500'">{{ step.label }}</div>
                        <div v-if="step.at" class="text-xs text-slate-500">{{ formatDateTime(step.at) }}<template v-if="step.by"> · {{ step.by }}</template></div>
                    </li>
                </ol>
            </AppCard>

            <AppCard v-if="ncr.root_cause || ncr.corrective_action || ncr.verification_remarks" title="Resolution">
                <dl class="space-y-3 text-sm">
                    <div v-if="ncr.root_cause"><dt class="text-xs text-slate-500">Root cause</dt><dd class="break-words whitespace-pre-line">{{ ncr.root_cause }}</dd></div>
                    <div v-if="ncr.corrective_action"><dt class="text-xs text-slate-500">Corrective action</dt><dd class="break-words whitespace-pre-line">{{ ncr.corrective_action }}</dd></div>
                    <div v-if="ncr.verification_remarks"><dt class="text-xs text-slate-500">Verification remarks</dt><dd class="break-words whitespace-pre-line">{{ ncr.verification_remarks }}</dd></div>
                </dl>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="ncr" :attachable-id="ncr.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Photos of the defect and of the rectified work…" />
            <AuditTrail :entries="audit" />
        </div>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? ACTIONS[confirming].title : ''"
            :message="confirming ? ACTIONS[confirming].message : ''"
            :confirm-label="confirming ? ACTIONS[confirming].label : ''"
            :danger="confirming ? ACTIONS[confirming].danger : true"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
        <AppModal :show="resolving" title="Resolve NCR" @close="resolving = false">
            <div class="grid gap-4">
                <FormInput v-model="resolveForm.root_cause" label="Root cause" required multiline :rows="3" maxlength="2000" :error="resolveForm.errors.root_cause" />
                <FormInput v-model="resolveForm.corrective_action" label="Corrective action taken" required multiline :rows="3" maxlength="2000" :error="resolveForm.errors.corrective_action" />
            </div>
            <p v-if="resolveForm.errors.ncr" class="mt-2 text-sm text-red-700">{{ resolveForm.errors.ncr }}</p>
            <template #footer>
                <AppButton variant="secondary" @click="resolving = false">Back</AppButton>
                <AppButton :loading="resolveForm.processing" @click="resolve">Resolve</AppButton>
            </template>
        </AppModal>
        <AppModal :show="verifying" title="Verify corrective action" @close="verifying = false">
            <p class="mb-3 text-sm text-slate-600">Confirm the corrective action was inspected and is effective.</p>
            <FormInput v-model="verifyForm.remarks" label="Verification remarks" multiline :rows="3" maxlength="1000" :error="verifyForm.errors.remarks" />
            <p v-if="verifyForm.errors.ncr" class="mt-2 text-sm text-red-700">{{ verifyForm.errors.ncr }}</p>
            <template #footer>
                <AppButton variant="secondary" @click="verifying = false">Back</AppButton>
                <AppButton :loading="verifyForm.processing" @click="verify">Verify</AppButton>
            </template>
        </AppModal>
        <ReasonDialog
            :show="reopening"
            :url="route('projects.ncrs.reopen', [project.id, ncr.id])"
            title="Send back for rework"
            message="The resolution is not accepted. The NCR returns to in progress."
            confirm-label="Send back"
            @close="reopening = false"
        />
    </ProjectLayout>
</template>
