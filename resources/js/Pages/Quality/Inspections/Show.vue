<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
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
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    inspection: { type: Object, required: true },
    items: { type: Array, required: true },
    counts: { type: Object, required: true },
    ncrs: { type: Array, required: true },
    checkpointResults: { type: Array, required: true },
    results: { type: Array, required: true },
    members: { type: Array, required: true },
    attachments: { type: Array, required: true },
    audit: { type: Array, required: true },
    can: { type: Object, required: true },
    today: { type: String, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['inspection', 'items', 'result', 'remarks'].map((k) => page.props.errors?.[k]).find(Boolean));
const itemError = (id, field) => page.props.errors?.[`items.${id}.${field}`];

const state = reactive(Object.fromEntries(props.items.map((i) => [i.id, { result: i.result, remark: i.remark ?? '' }])));
const live = computed(() => {
    const values = Object.values(state);

    return {
        pass: values.filter((v) => v.result === 'pass').length,
        fail: values.filter((v) => v.result === 'fail').length,
        na: values.filter((v) => v.result === 'na').length,
        pending: values.filter((v) => !v.result).length,
    };
});
const tally = computed(() => (props.can.perform ? live.value : props.counts));
const payload = () => Object.fromEntries(Object.entries(state).map(([id, v]) => [id, { result: v.result || null, remark: v.remark?.trim() || null }]));

const RESULT_STYLE = {
    pass: 'border-green-600 bg-green-600 text-white',
    fail: 'border-red-600 bg-red-600 text-white',
    na: 'border-slate-500 bg-slate-500 text-white',
};
function setResult(id, value) {
    state[id].result = state[id].result === value ? null : value;
}

const saving = ref(false);
function saveResults() {
    saving.value = true;
    router.post(route('projects.inspections.record', [props.project.id, props.inspection.id]), { items: payload() }, { preserveScroll: true, onFinish: () => (saving.value = false) });
}

const completing = ref(false);
const completeForm = useForm({ result: 'passed', remarks: '' });
const hasFail = computed(() => live.value.fail > 0);
function openComplete() {
    completeForm.clearErrors();
    completeForm.result = hasFail.value ? 'failed' : 'passed';
    completing.value = true;
}
function complete() {
    completeForm
        .transform((d) => ({ result: hasFail.value ? 'failed' : d.result, remarks: d.remarks || null, items: payload() }))
        .post(route('projects.inspections.complete', [props.project.id, props.inspection.id]), { preserveScroll: true, onSuccess: () => (completing.value = false) });
}

const scheduling = ref(false);
const scheduleForm = useForm({ inspection_date: props.inspection.inspection_date ?? props.today, engineer_id: props.inspection.engineer_id ?? null });
function schedule() {
    scheduleForm.post(route('projects.inspections.schedule', [props.project.id, props.inspection.id]), { preserveScroll: true, onSuccess: () => (scheduling.value = false) });
}

const deleting = ref(false);
const processing = ref(false);
function destroy() {
    processing.value = true;
    router.delete(route('projects.inspections.destroy', [props.project.id, props.inspection.id]), { onFinish: () => ((processing.value = false), (deleting.value = false)) });
}

const statusNote = computed(() => {
    const i = props.inspection;

    return {
        requested: 'Requested. An inspector schedules it, then records each checkpoint.',
        scheduled: 'Mark every checkpoint pass, fail or N/A. Any failed checkpoint makes the inspection failed; otherwise choose passed or conditional when completing.',
        completed: i.result === 'passed' ? 'Completed and passed. The inspection is locked.' : `Completed: ${i.result_label}. The inspection is locked; raise an NCR for the non-conformance if needed.`,
    }[i.status];
});
</script>

<template>
    <ProjectLayout :project="project" active="quality" :title="inspection.inspection_number">
        <QualityNav :project-id="project.id" active="inspections" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.inspections.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Inspections</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ inspection.inspection_number }}</h2>
                            <StatusBadge :status="inspection.status" :label="inspection.status_label" />
                            <StatusBadge v-if="inspection.result" :status="inspection.result" :label="inspection.result_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ inspection.checklist }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Site</dt><dd>{{ inspection.site ?? '—' }}</dd></div>
                            <div><dt class="text-slate-500">Location</dt><dd class="break-words">{{ inspection.location ?? '—' }}</dd></div>
                            <div><dt class="text-slate-500">Date</dt><dd>{{ inspection.inspection_date ? formatDate(inspection.inspection_date) : 'Not scheduled' }}</dd></div>
                            <div><dt class="text-slate-500">Inspector</dt><dd>{{ inspection.engineer ?? '—' }}</dd></div>
                            <div v-if="inspection.task"><dt class="text-slate-500">Task</dt><dd>{{ inspection.task }}</dd></div>
                            <div v-if="inspection.boq_item"><dt class="text-slate-500">BOQ item</dt><dd>{{ inspection.boq_item }}</dd></div>
                        </dl>
                        <p v-if="inspection.request_notes" class="mt-2 text-xs text-slate-600">{{ inspection.request_notes }}</p>
                        <p class="mt-2 text-xs text-slate-500">
                            Requested by {{ inspection.requested_by ?? '—' }}
                            <template v-if="inspection.completed_at"> · Completed {{ formatDateTime(inspection.completed_at) }} by {{ inspection.completed_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.edit" size="sm" variant="secondary" icon="pencil" :href="route('projects.inspections.edit', [project.id, inspection.id])">Edit</AppButton>
                        <AppButton v-if="can.schedule" size="sm" icon="calendar" @click="scheduling = true">Schedule</AppButton>
                        <AppButton v-if="can.raiseNcr" size="sm" variant="danger" icon="warning" :href="route('projects.ncrs.create', project.id) + `?inspection=${inspection.id}`">Raise NCR</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete inspection" @click="deleting = true" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
                <div v-if="inspection.remarks" class="border-t border-line px-4 py-2.5 text-sm text-slate-700 sm:px-5"><span class="text-xs text-slate-500">Remarks:</span> {{ inspection.remarks }}</div>
            </AppCard>

            <AppCard title="Checkpoints" :padded="false">
                <template #actions>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        <span class="rounded bg-green-50 px-2 py-0.5 text-green-700">Pass {{ tally.pass }}</span>
                        <span class="rounded bg-red-50 px-2 py-0.5 text-red-700">Fail {{ tally.fail }}</span>
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-slate-600">N/A {{ tally.na }}</span>
                        <span v-if="tally.pending" class="rounded bg-amber-50 px-2 py-0.5 text-amber-700">Pending {{ tally.pending }}</span>
                    </div>
                </template>

                <!-- Desktop: checkpoint table -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="w-10 px-4 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase">#</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase">Checkpoint</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase">Result</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase">Remark</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line bg-white">
                            <tr v-for="(item, index) in items" :key="item.id" class="align-top">
                                <td class="px-4 py-3 text-slate-400 tabular">{{ index + 1 }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ item.checkpoint }}</div>
                                    <div v-if="item.acceptance_criteria" class="text-xs text-slate-500">{{ item.acceptance_criteria }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div v-if="can.perform" class="inline-flex overflow-hidden rounded-md border border-line" role="group" :aria-label="`Result for checkpoint ${index + 1}`">
                                        <button
                                            v-for="option in checkpointResults"
                                            :key="option.value"
                                            type="button"
                                            class="border-r border-line px-2.5 py-1 text-xs font-medium last:border-r-0"
                                            :class="state[item.id].result === option.value ? RESULT_STYLE[option.value] : 'bg-white text-slate-600 hover:bg-slate-50'"
                                            :aria-pressed="state[item.id].result === option.value"
                                            @click="setResult(item.id, option.value)"
                                        >{{ option.label }}</button>
                                    </div>
                                    <StatusBadge v-else-if="item.result" :status="{ pass: 'passed', fail: 'failed', na: 'inactive' }[item.result]" :label="checkpointResults.find((o) => o.value === item.result)?.label" />
                                    <span v-else class="text-xs text-slate-400">Not assessed</span>
                                    <p v-if="itemError(item.id, 'result')" class="mt-1 text-xs text-red-600">{{ itemError(item.id, 'result') }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <template v-if="can.perform">
                                        <input
                                            v-model="state[item.id].remark"
                                            type="text"
                                            maxlength="500"
                                            :placeholder="state[item.id].result === 'fail' ? 'Required: why it failed' : 'Optional'"
                                            class="block w-full min-w-48 rounded-md border-slate-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200"
                                            :class="state[item.id].result === 'fail' && !state[item.id].remark?.trim() ? 'border-red-300' : ''"
                                            :aria-label="`Remark for checkpoint ${index + 1}`"
                                        />
                                        <p v-if="itemError(item.id, 'remark')" class="mt-1 text-xs text-red-600">{{ itemError(item.id, 'remark') }}</p>
                                    </template>
                                    <span v-else class="text-slate-700">{{ item.remark ?? '' }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile: stacked cards -->
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="(item, index) in items" :key="item.id" class="space-y-2 px-4 py-3">
                        <div>
                            <div class="text-sm font-medium text-slate-900"><span class="mr-1 text-slate-400 tabular">{{ index + 1 }}.</span>{{ item.checkpoint }}</div>
                            <div v-if="item.acceptance_criteria" class="text-xs text-slate-500">{{ item.acceptance_criteria }}</div>
                        </div>
                        <template v-if="can.perform">
                            <div class="grid grid-cols-3 gap-1.5" role="group" :aria-label="`Result for checkpoint ${index + 1}`">
                                <button
                                    v-for="option in checkpointResults"
                                    :key="option.value"
                                    type="button"
                                    class="rounded-md border py-2 text-sm font-medium"
                                    :class="state[item.id].result === option.value ? RESULT_STYLE[option.value] : 'border-line bg-white text-slate-600'"
                                    :aria-pressed="state[item.id].result === option.value"
                                    @click="setResult(item.id, option.value)"
                                >{{ option.label }}</button>
                            </div>
                            <input
                                v-model="state[item.id].remark"
                                type="text"
                                maxlength="500"
                                :placeholder="state[item.id].result === 'fail' ? 'Required: why it failed' : 'Remark (optional)'"
                                class="block w-full rounded-md border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200"
                                :class="state[item.id].result === 'fail' && !state[item.id].remark?.trim() ? 'border-red-300' : ''"
                            />
                            <p v-if="itemError(item.id, 'remark')" class="text-xs text-red-600">{{ itemError(item.id, 'remark') }}</p>
                        </template>
                        <div v-else class="flex items-center justify-between gap-2 text-xs">
                            <StatusBadge v-if="item.result" :status="{ pass: 'passed', fail: 'failed', na: 'inactive' }[item.result]" :label="checkpointResults.find((o) => o.value === item.result)?.label" />
                            <span v-else class="text-slate-400">Not assessed</span>
                            <span v-if="item.remark" class="min-w-0 text-right break-words text-slate-600">{{ item.remark }}</span>
                        </div>
                    </li>
                </ul>

                <div v-if="can.perform" class="flex flex-col gap-2 border-t border-line p-3 sm:flex-row sm:justify-end">
                    <AppButton variant="secondary" :loading="saving" @click="saveResults">Save results</AppButton>
                    <AppButton :disabled="live.pending > 0" @click="openComplete">Complete inspection</AppButton>
                </div>
            </AppCard>

            <AppCard v-if="ncrs.length" title="NCRs raised from this inspection" :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="ncr in ncrs" :key="ncr.id">
                        <Link :href="route('projects.ncrs.show', [project.id, ncr.id])" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-slate-50">
                            <span class="font-mono text-xs font-medium text-slate-900">{{ ncr.ncr_number }}</span>
                            <span class="flex gap-2"><StatusBadge :status="ncr.severity" :label="ncr.severity_label" /><StatusBadge :status="ncr.status" :label="ncr.status_label" /></span>
                        </Link>
                    </li>
                </ul>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="quality_inspection" :attachable-id="inspection.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Site photos, test reports…" />
            <AuditTrail :entries="audit" />
        </div>

        <AppModal :show="scheduling" title="Schedule inspection" @close="scheduling = false">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput v-model="scheduleForm.inspection_date" type="date" label="Inspection date" required :error="scheduleForm.errors.inspection_date" />
                <SearchSelect v-model="scheduleForm.engineer_id" label="Inspector" :options="members" :error="scheduleForm.errors.engineer_id" />
            </div>
            <p v-if="scheduleForm.errors.inspection" class="mt-2 text-sm text-red-700">{{ scheduleForm.errors.inspection }}</p>
            <template #footer>
                <AppButton variant="secondary" @click="scheduling = false">Back</AppButton>
                <AppButton :loading="scheduleForm.processing" @click="schedule">Schedule</AppButton>
            </template>
        </AppModal>

        <AppModal :show="completing" title="Complete inspection" @close="completing = false">
            <p class="mb-3 text-sm text-slate-600">
                {{ live.pass }} passed, {{ live.fail }} failed, {{ live.na }} N/A. Completing locks the inspection.
            </p>
            <div v-if="hasFail" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                A checkpoint failed, so the inspection result is <strong>Failed</strong>.
            </div>
            <fieldset v-else class="mb-3 space-y-2">
                <legend class="mb-1 text-sm font-medium text-slate-700">Result</legend>
                <label v-for="option in results.filter((r) => r.value !== 'failed')" :key="option.value" class="flex items-center gap-2 text-sm">
                    <input v-model="completeForm.result" type="radio" :value="option.value" class="text-brand-600 focus:ring-brand-200" />
                    {{ option.label }}<span v-if="option.value === 'conditional'" class="text-xs text-slate-500">(accepted with conditions; remarks required)</span>
                </label>
            </fieldset>
            <FormInput
                v-model="completeForm.remarks"
                label="Remarks"
                multiline
                :rows="3"
                maxlength="2000"
                :required="!hasFail && completeForm.result === 'conditional'"
                :error="completeForm.errors.remarks"
            />
            <template v-for="key in ['result', 'items', 'inspection']" :key="key">
                <p v-if="completeForm.errors[key]" class="mt-2 text-sm text-red-700">{{ completeForm.errors[key] }}</p>
            </template>
            <template #footer>
                <AppButton variant="secondary" @click="completing = false">Back</AppButton>
                <AppButton :variant="hasFail ? 'danger' : 'primary'" :loading="completeForm.processing" @click="complete">Complete as {{ hasFail ? 'failed' : completeForm.result }}</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :show="deleting"
            title="Delete this inspection request?"
            message="Only requested inspections can be deleted. Its number is not reused."
            confirm-label="Delete"
            :processing="processing"
            @close="deleting = false"
            @confirm="destroy"
        />
    </ProjectLayout>
</template>
