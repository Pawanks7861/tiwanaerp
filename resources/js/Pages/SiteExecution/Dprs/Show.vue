<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    dpr: { type: Object, required: true },
    items: { type: Array, required: true },
    labours: { type: Array, required: true },
    equipment: { type: Array, required: true },
    materials: { type: Array, required: true },
    diaries: { type: Array, required: true },
    entries: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const error = computed(() => {
    const e = page.props.errors ?? {};
    return e.items || e.dpr || e.approval || e.workflow || null;
});
const base = computed(() => [props.project.id, props.dpr.id]);
const confirming = ref(null);
const reopening = ref(false);
const processing = ref(false);
const headcount = computed(() => props.labours.reduce((sum, l) => sum + Number(l.headcount || 0), 0));
const pendingDiaries = computed(() => props.diaries.filter((d) => d.status !== 'approved').length);

const ACTIONS = {
    submit: { title: 'Submit DPR for approval?', message: 'Quantities are checked against the plan now and again at approval. Approval posts them to the progress ledger.', label: 'Submit', danger: false },
    refresh: { title: 'Refresh from site diaries?', message: 'Lines, labour, equipment and material are rebuilt from the approved diaries of this date. Manual changes to those lines are replaced.', label: 'Refresh', danger: false },
    delete: { title: 'Delete this DPR?', message: 'The draft is removed. The site diaries are not affected.', label: 'Delete', danger: true },
};

function run(action) {
    processing.value = true;
    const done = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    if (action === 'delete') {
        router.delete(route('projects.dprs.destroy', base.value), done);
    } else {
        router.post(route(`projects.dprs.${action}`, base.value), {}, done);
    }
}

const statusNote = computed(() => ({
    draft: props.dpr.revision > 0 ? `Revision ${props.dpr.revision}: correct the lines, then submit again. The earlier posting was reversed.` : 'Draft: review the lines, then submit for approval. Nothing is posted until the DPR is approved.',
    rejected: 'Rejected: correct the lines and submit again.',
    approved: 'Approved: quantities are posted to the progress ledger. To correct it, reopen the DPR (the posting is reversed).',
}[props.dpr.status] ?? null));
</script>

<template>
    <ProjectLayout :project="project" active="dprs" :title="dpr.dpr_number">
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-3 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('projects.dprs.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Daily progress reports</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-lg font-semibold text-slate-900">{{ dpr.dpr_number }}</h2>
                            <StatusBadge :status="dpr.status" :label="dpr.status_label" />
                            <span v-if="dpr.revision > 0" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Rev {{ dpr.revision }}</span>
                        </div>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">Date</dt><dd>{{ formatDate(dpr.dpr_date) }}</dd></div>
                            <div><dt class="text-slate-500">Engineer</dt><dd>{{ dpr.engineer ?? '—' }}</dd></div>
                            <div><dt class="text-slate-500">Labour</dt><dd class="tabular">{{ headcount }} nos</dd></div>
                            <div><dt class="text-slate-500">Weather</dt><dd>{{ dpr.weather || '—' }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ dpr.created_by ?? '—' }}
                            <template v-if="dpr.approved_at"> · Approved {{ formatDateTime(dpr.approved_at) }} by {{ dpr.approved_by }}</template>
                            <template v-if="dpr.reopened_at"> · Reopened {{ formatDateTime(dpr.reopened_at) }} by {{ dpr.reopened_by }} ({{ dpr.reopen_reason }})</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.dprs.edit', base)">Edit</AppButton>
                        <AppButton v-if="can.update" size="sm" variant="secondary" @click="confirming = 'refresh'">Refresh from diaries</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="DPR" />
                        <AppButton v-if="can.reopen" size="sm" variant="danger" @click="reopening = true">Reopen for correction</AppButton>
                        <a
                            v-if="can.export"
                            :href="route('projects.dprs.pdf', base)"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                        ><Icon name="download" :size="14" /> PDF</a>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete DPR" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="error" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ error }}</p>
                <div v-if="approval" class="border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 sm:px-5">
                    Awaiting approval · level {{ approval.level }} of {{ approval.levels }}<template v-if="approval.step_name"> ({{ approval.step_name }})</template>
                </div>
                <div v-else-if="statusNote" class="border-t border-line bg-slate-50 px-4 py-2.5 text-xs text-slate-600 sm:px-5">
                    <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />{{ statusNote }}
                </div>
            </AppCard>

            <AppCard title="Work progress" :subtitle="`${items.length} line(s). Cumulative and balance are frozen when the DPR is approved.`" :padded="false">
                <div v-if="items.length" class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">Task / BOQ item</th>
                                <th class="px-4 py-2 text-right">Planned</th>
                                <th class="px-4 py-2 text-right">Today</th>
                                <th class="px-4 py-2 text-right">Cumulative</th>
                                <th class="px-4 py-2 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in items" :key="item.id">
                                <td class="px-4 py-2">
                                    <div class="font-medium text-slate-900">{{ item.task ?? item.boq_item ?? item.description }}</div>
                                    <div class="text-xs text-slate-500">
                                        <template v-if="item.task && item.boq_item">BOQ {{ item.boq_item }}</template>
                                        <template v-if="item.description && (item.task || item.boq_item)"> · {{ item.description }}</template>
                                        <span v-if="!item.task && !item.boq_item" class="text-amber-700">Not linked; no progress is posted</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-right whitespace-nowrap tabular">{{ item.planned_qty !== null ? formatQty(item.planned_qty) : '—' }}</td>
                                <td class="px-4 py-2 text-right font-medium whitespace-nowrap tabular">{{ formatQty(item.executed_qty) }} {{ item.unit }}</td>
                                <td class="px-4 py-2 text-right whitespace-nowrap tabular">{{ item.cumulative_qty !== null ? formatQty(item.cumulative_qty) : '—' }}</td>
                                <td class="px-4 py-2 text-right whitespace-nowrap tabular">{{ item.balance_qty !== null ? formatQty(item.balance_qty) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul v-if="items.length" class="divide-y divide-line md:hidden">
                    <li v-for="item in items" :key="item.id" class="px-4 py-3 text-sm">
                        <div class="font-medium text-slate-900">{{ item.task ?? item.boq_item ?? item.description }}</div>
                        <div class="mt-1 grid grid-cols-3 gap-2 text-xs">
                            <div><span class="block text-slate-500">Today</span><span class="font-medium tabular">{{ formatQty(item.executed_qty) }} {{ item.unit }}</span></div>
                            <div><span class="block text-slate-500">Cumulative</span><span class="tabular">{{ item.cumulative_qty !== null ? formatQty(item.cumulative_qty) : '—' }}</span></div>
                            <div><span class="block text-slate-500">Balance</span><span class="tabular">{{ item.balance_qty !== null ? formatQty(item.balance_qty) : '—' }}</span></div>
                        </div>
                    </li>
                </ul>
                <p v-if="!items.length" class="px-4 py-3 text-sm text-slate-500">No work lines.</p>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-3">
                <AppCard title="Labour" :padded="false">
                    <ul v-if="labours.length" class="divide-y divide-line text-sm">
                        <li v-for="l in labours" :key="l.id" class="flex justify-between gap-2 px-4 py-2">
                            <span>{{ l.trade }}<span class="block text-xs text-slate-500">{{ l.subcontractor ?? 'Departmental' }}</span></span>
                            <span class="tabular">{{ l.headcount }} nos</span>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">None.</p>
                </AppCard>
                <AppCard title="Equipment" :padded="false">
                    <ul v-if="equipment.length" class="divide-y divide-line text-sm">
                        <li v-for="e in equipment" :key="e.id" class="flex justify-between gap-2 px-4 py-2">
                            <span>{{ e.type ?? e.description }}<span v-if="e.type && e.description" class="block text-xs text-slate-500">{{ e.description }}</span></span>
                            <span class="text-right text-xs tabular">{{ e.working_hours !== null ? Number(e.working_hours) : 0 }} h work<span class="block text-slate-500">{{ e.idle_hours !== null ? Number(e.idle_hours) : 0 }} h idle</span></span>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">None.</p>
                </AppCard>
                <AppCard title="Material used" subtitle="Reported only; no stock posting" :padded="false">
                    <ul v-if="materials.length" class="divide-y divide-line text-sm">
                        <li v-for="m in materials" :key="m.id" class="flex justify-between gap-2 px-4 py-2">
                            <span>{{ m.material }}<span class="block text-xs text-slate-500">{{ m.code }}</span></span>
                            <span class="whitespace-nowrap tabular">{{ formatQty(m.quantity) }} {{ m.unit }}</span>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">None.</p>
                </AppCard>
            </div>

            <AppCard v-if="dpr.site_issues || dpr.remarks" title="Issues and remarks">
                <p v-if="dpr.site_issues" class="text-sm whitespace-pre-line text-slate-800">{{ dpr.site_issues }}</p>
                <p v-if="dpr.remarks" class="mt-3 text-sm whitespace-pre-line text-slate-600">{{ dpr.remarks }}</p>
            </AppCard>

            <AppCard title="Source site diaries" :padded="false">
                <ul class="divide-y divide-line text-sm">
                    <li v-for="d in diaries" :key="d.id" class="flex items-center justify-between gap-2 px-4 py-2">
                        <Link :href="route('projects.site-diaries.show', [project.id, d.id])" class="font-medium text-brand-700 hover:underline">{{ d.site ?? 'Diary #' + d.id }} · {{ d.created_by }}</Link>
                        <StatusBadge :status="d.status" :label="d.status_label" />
                    </li>
                </ul>
                <p v-if="pendingDiaries" class="border-t border-line bg-slate-50 px-4 py-2 text-xs text-slate-600">Only approved diaries are included. {{ pendingDiaries }} other diary(ies) of this date are not.</p>
            </AppCard>

            <AppCard v-if="entries.length" title="Progress ledger postings" subtitle="Append-only. Corrections appear as reversal rows." :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">Task</th>
                                <th class="hidden px-4 py-2 text-left md:table-cell">Reference</th>
                                <th class="px-4 py-2 text-right">Quantity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="e in entries" :key="e.id" :class="e.is_reversal ? 'bg-red-50/50' : ''">
                                <td class="px-4 py-2">{{ e.task ?? 'BOQ line only' }}<span v-if="e.is_reversal" class="ml-1 text-xs text-red-700">reversal</span></td>
                                <td class="hidden px-4 py-2 font-mono text-xs text-slate-500 md:table-cell">{{ e.posting_ref }}</td>
                                <td class="px-4 py-2 text-right tabular" :class="Number(e.quantity) < 0 ? 'text-red-700' : ''">{{ formatQty(e.quantity) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AttachmentPanel :attachments="attachments" attachable-type="dpr" :attachable-id="dpr.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Site photos, signed DPR…" />
        </div>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? ACTIONS[confirming].title : ''"
            :message="confirming ? ACTIONS[confirming].message : ''"
            :confirm-label="confirming ? ACTIONS[confirming].label : ''"
            :danger="confirming ? ACTIONS[confirming].danger : false"
            :processing="processing"
            @close="confirming = null"
            @confirm="run(confirming)"
        />
        <ReasonDialog :show="reopening" :url="route('projects.dprs.reopen', base)" title="Reopen DPR" message="The posted progress is reversed and the DPR becomes a draft (next revision) for correction." confirm-label="Reopen" danger @close="reopening = false" />
    </ProjectLayout>
</template>
