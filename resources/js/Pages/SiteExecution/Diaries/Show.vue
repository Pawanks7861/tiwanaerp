<script setup>
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import PhotoCapture from '@/Components/SiteExecution/PhotoCapture.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    diary: { type: Object, required: true },
    work_items: { type: Array, required: true },
    labours: { type: Array, required: true },
    equipment: { type: Array, required: true },
    materials: { type: Array, required: true },
    photos: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const confirmDelete = ref(false);
const confirmAction = ref(null);
const rejecting = ref(false);
const processing = ref(false);

const base = computed(() => [props.project.id, props.diary.id]);
const ACTIONS = {
    submit: { title: 'Submit diary', message: 'Submit this diary for review? It can no longer be edited unless it is rejected.', label: 'Submit' },
    review: { title: 'Mark as reviewed', message: 'Confirm you have checked this diary. It then goes to the approver.', label: 'Mark reviewed' },
    approve: { title: 'Approve diary', message: 'Approve this diary? It becomes available for the DPR of that date and is locked.', label: 'Approve' },
};

function run(action) {
    processing.value = true;
    router.post(route(`projects.site-diaries.${action}`, base.value), {}, {
        preserveScroll: true,
        onFinish: () => ((processing.value = false), (confirmAction.value = null)),
    });
}

function destroy() {
    processing.value = true;
    router.delete(route('projects.site-diaries.destroy', base.value), { onFinish: () => (processing.value = false) });
}

const timeline = computed(() => [
    { label: 'Created', by: props.diary.created_by, at: null },
    props.diary.submitted_at && { label: 'Submitted', by: props.diary.submitted_by, at: props.diary.submitted_at },
    props.diary.reviewed_at && { label: 'Reviewed', by: props.diary.reviewed_by, at: props.diary.reviewed_at },
    props.diary.approved_at && { label: 'Approved', by: props.diary.approved_by, at: props.diary.approved_at },
    props.diary.rejected_at && { label: 'Rejected', by: props.diary.rejected_by, at: props.diary.rejected_at },
].filter(Boolean));
</script>

<template>
    <ProjectLayout :project="project" active="site-diaries" :title="`Site diary ${formatDate(diary.diary_date)}`">
        <div class="space-y-4">
            <AppCard>
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-900">Site diary · {{ formatDate(diary.diary_date) }}</h2>
                            <StatusBadge :status="diary.status" :label="diary.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ diary.site ?? 'No site' }}<template v-if="diary.work_location"> · {{ diary.work_location }}</template> · by {{ diary.created_by }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="can.update" variant="secondary" size="sm" icon="pencil" class="min-h-11" :href="route('projects.site-diaries.edit', base)">Edit</AppButton>
                        <AppButton v-if="can.submit" size="sm" class="min-h-11" @click="confirmAction = 'submit'">Submit</AppButton>
                        <AppButton v-if="can.review" size="sm" class="min-h-11" @click="confirmAction = 'review'">Mark reviewed</AppButton>
                        <AppButton v-if="can.approve" size="sm" icon="check-circle" class="min-h-11" @click="confirmAction = 'approve'">Approve</AppButton>
                        <AppButton v-if="can.reject" variant="danger" size="sm" class="min-h-11" @click="rejecting = true">Reject</AppButton>
                        <AppButton v-if="can.delete" variant="ghost" size="sm" icon="trash" class="min-h-11" @click="confirmDelete = true">Delete</AppButton>
                    </div>
                </div>
                <p v-if="errors.diary" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ errors.diary }}</p>
                <div v-if="diary.status === 'rejected' && diary.rejection_reason" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    <span class="font-medium">Rejected:</span> {{ diary.rejection_reason }}
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-slate-500">Weather</dt><dd class="font-medium text-slate-900">{{ diary.weather || '—' }}</dd></div>
                    <div><dt class="text-slate-500">Temperature</dt><dd class="font-medium text-slate-900">{{ diary.temperature !== null ? `${Number(diary.temperature)} °C` : '—' }}</dd></div>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Location</dt>
                        <dd class="font-medium text-slate-900 tabular">{{ diary.latitude ? `${Number(diary.latitude).toFixed(5)}, ${Number(diary.longitude).toFixed(5)}` : 'Not captured' }}</dd>
                    </div>
                </dl>
                <div v-if="diary.work_performed" class="mt-4">
                    <h3 class="text-sm font-medium text-slate-500">Work performed</h3>
                    <p class="mt-1 text-sm whitespace-pre-line text-slate-800">{{ diary.work_performed }}</p>
                </div>
                <ol class="mt-4 flex flex-wrap gap-x-6 gap-y-1 border-t border-line pt-3 text-xs text-slate-500">
                    <li v-for="step in timeline" :key="step.label">
                        <span class="font-medium text-slate-700">{{ step.label }}</span> · {{ step.by ?? '—' }}<template v-if="step.at"> · {{ formatDateTime(step.at) }}</template>
                    </li>
                </ol>
            </AppCard>

            <AppCard title="Work done" :padded="false">
                <div v-if="work_items.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2">Task / description</th>
                                <th class="hidden px-4 py-2 md:table-cell">BOQ item</th>
                                <th class="hidden px-4 py-2 md:table-cell">Subcontractor</th>
                                <th class="px-4 py-2 text-right">Quantity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="w in work_items" :key="w.id">
                                <td class="px-4 py-2">
                                    <div class="font-medium text-slate-900">{{ w.task ?? w.description }}</div>
                                    <div v-if="w.task && w.description" class="text-xs text-slate-500">{{ w.description }}</div>
                                    <div v-if="w.boq_item" class="text-xs text-slate-500 md:hidden">BOQ {{ w.boq_item }}</div>
                                </td>
                                <td class="hidden px-4 py-2 text-slate-600 md:table-cell">{{ w.boq_item ?? '—' }}</td>
                                <td class="hidden px-4 py-2 text-slate-600 md:table-cell">{{ w.subcontractor ?? '—' }}</td>
                                <td class="px-4 py-2 text-right whitespace-nowrap tabular">{{ formatQty(w.quantity) }} {{ w.unit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-3 text-sm text-slate-500">No work lines.</p>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Labour" :padded="false">
                    <ul v-if="labours.length" class="divide-y divide-line text-sm">
                        <li v-for="l in labours" :key="l.id" class="flex items-center justify-between gap-2 px-4 py-2">
                            <div>
                                <div class="font-medium text-slate-900">{{ l.trade }}</div>
                                <div class="text-xs text-slate-500">{{ l.subcontractor ?? 'Departmental' }}<template v-if="l.remarks"> · {{ l.remarks }}</template></div>
                            </div>
                            <div class="text-right tabular">{{ l.headcount }} nos<span v-if="l.hours" class="block text-xs text-slate-500">{{ Number(l.hours) }} h</span></div>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">No labour recorded.</p>
                </AppCard>
                <AppCard title="Equipment" :padded="false">
                    <ul v-if="equipment.length" class="divide-y divide-line text-sm">
                        <li v-for="e in equipment" :key="e.id" class="flex items-center justify-between gap-2 px-4 py-2">
                            <div>
                                <div class="font-medium text-slate-900">{{ e.type ?? e.description }}</div>
                                <div v-if="e.type && e.description" class="text-xs text-slate-500">{{ e.description }}</div>
                            </div>
                            <div class="text-right text-xs text-slate-600 tabular">
                                <div>Working {{ e.working_hours !== null ? Number(e.working_hours) : '—' }} h</div>
                                <div>Idle {{ e.idle_hours !== null ? Number(e.idle_hours) : '—' }} h</div>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">No equipment recorded.</p>
                </AppCard>
            </div>

            <AppCard title="Material used" subtitle="Reported consumption only; it does not move stock or cost." :padded="false">
                <ul v-if="materials.length" class="divide-y divide-line text-sm">
                    <li v-for="m in materials" :key="m.id" class="flex items-center justify-between gap-2 px-4 py-2">
                        <div>
                            <div class="font-medium text-slate-900">{{ m.material }}</div>
                            <div class="text-xs text-slate-500">{{ m.code }}<template v-if="m.remarks"> · {{ m.remarks }}</template></div>
                        </div>
                        <div class="whitespace-nowrap tabular">{{ formatQty(m.quantity) }} {{ m.unit }}</div>
                    </li>
                </ul>
                <p v-else class="px-4 py-3 text-sm text-slate-500">No material recorded.</p>
            </AppCard>

            <AppCard v-if="diary.issues || diary.safety_incidents || diary.remarks" title="Issues and remarks">
                <dl class="grid gap-4 text-sm lg:grid-cols-3">
                    <div v-if="diary.issues"><dt class="text-slate-500">Site issues</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ diary.issues }}</dd></div>
                    <div v-if="diary.safety_incidents"><dt class="text-slate-500">Safety incidents</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ diary.safety_incidents }}</dd></div>
                    <div v-if="diary.remarks"><dt class="text-slate-500">Remarks</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ diary.remarks }}</dd></div>
                </dl>
            </AppCard>

            <AppCard :title="`Photos (${photos.length})`">
                <PhotoCapture
                    :photos="photos"
                    :upload-url="can.update ? route('projects.site-diaries.photos.store', base) : null"
                    :delete-route="can.update ? (photo) => route('projects.site-diaries.photos.destroy', [...base, photo.id]) : null"
                    :can-edit="can.update"
                />
            </AppCard>
        </div>

        <ConfirmDialog
            :show="!!confirmAction"
            :title="confirmAction ? ACTIONS[confirmAction].title : ''"
            :message="confirmAction ? ACTIONS[confirmAction].message : ''"
            :confirm-label="confirmAction ? ACTIONS[confirmAction].label : ''"
            :processing="processing"
            @close="confirmAction = null"
            @confirm="run(confirmAction)"
        />
        <ConfirmDialog :show="confirmDelete" title="Delete diary" message="Delete this draft diary and its photos?" confirm-label="Delete" danger :processing="processing" @close="confirmDelete = false" @confirm="destroy" />
        <ReasonDialog :show="rejecting" :url="route('projects.site-diaries.reject', base)" title="Reject diary" message="The author can correct and resubmit it." confirm-label="Reject" danger @close="rejecting = false" />
    </ProjectLayout>
</template>
