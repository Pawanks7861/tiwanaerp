<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import PdfActions from '@/Components/Pdf/PdfActions.vue';
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
    constraints: { type: Array, default: () => [] },
    diaries: { type: Array, default: () => [] },
    entries: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const showCompleted = ref(false);
const error = computed(() => {
    const e = page.props.errors ?? {};
    return e.items || e.dpr || e.approval || e.workflow || null;
});
const base = computed(() => [props.project.id, props.dpr.id]);
const confirming = ref(null);
const reopening = ref(false);
const processing = ref(false);
const headcount = computed(() => props.labours.reduce((sum, l) => sum + Number(l.headcount || 0), 0));

const visibleItems = computed(() => {
    if (showCompleted.value) return props.items;
    return props.items.filter((item) => {
        const planned = Number(item.planned_qty ?? 0);
        const cum = Number(item.cumulative_qty ?? 0);
        return !(planned > 0 && cum >= planned);
    });
});

const ACTIONS = {
    submit: { title: 'Submit DPR for approval?', message: 'Quantities are checked against the plan now and again at approval. Approval posts them to the progress ledger.', label: 'Submit', danger: false },
    refresh: { title: 'Refresh activity lines?', message: 'Activity, labour, equipment and material summaries are rebuilt from planning and site data. Manual edits on those lines may be replaced.', label: 'Refresh', danger: false },
    delete: { title: 'Delete this DPR?', message: 'This permanently removes the DPR and all related work lines, labour, equipment, materials, constraints, attachments, and approval history for this report.', label: 'Delete', danger: true },
};

function run(action) {
    processing.value = true;
    const data = action === 'refresh' ? { include_completed: showCompleted.value ? 1 : 0 } : {};
    const done = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    if (action === 'delete') {
        router.delete(route('projects.dprs.destroy', base.value), done);
    } else {
        router.post(route(`projects.dprs.${action}`, base.value), data, done);
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
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ dpr.source_label }}</span>
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
                        <AppButton v-if="can.update" size="sm" variant="secondary" @click="confirming = 'refresh'">Refresh</AppButton>
                        <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit for approval</AppButton>
                        <ApprovalActions :approval="approval" noun="DPR" />
                        <AppButton v-if="can.reopen" size="sm" variant="danger" @click="reopening = true">Reopen for correction</AppButton>
                        <PdfActions
                            v-if="can.export"
                            :title="`${dpr.dpr_number}.pdf`"
                            :view-url="route('projects.dprs.pdf.view', base)"
                            :download-url="route('projects.dprs.pdf', base)"
                        />
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

            <AppCard title="Activity progress" :padded="false">
                <template #actions>
                    <label class="flex items-center gap-2 px-4 text-xs text-slate-600">
                        <input v-model="showCompleted" type="checkbox" class="rounded border-slate-300 text-brand-600" />
                        Show completed
                    </label>
                </template>
                <div v-if="visibleItems.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-xs">
                        <thead class="bg-slate-50 font-semibold text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Work area</th>
                                <th class="px-3 py-2 text-left">Activity</th>
                                <th class="hidden px-3 py-2 lg:table-cell">Plan dates</th>
                                <th class="px-3 py-2 text-right">Previous</th>
                                <th class="px-3 py-2 text-right">Target</th>
                                <th class="px-3 py-2 text-right">Today</th>
                                <th class="px-3 py-2 text-right">Hold</th>
                                <th class="px-3 py-2 text-right">Cumulative</th>
                                <th class="px-3 py-2 text-right">Balance</th>
                                <th class="px-3 py-2 text-right">% done</th>
                                <th class="px-3 py-2 text-right">% ach.</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in visibleItems" :key="item.id" :class="item.on_hold ? 'bg-amber-50/50' : ''">
                                <td class="px-3 py-2 text-slate-600">{{ item.work_area ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="font-medium text-slate-900">{{ item.task ?? item.activity_name ?? item.description }}</div>
                                    <div v-if="item.boq_item" class="text-[11px] text-slate-500">BOQ {{ item.boq_item }}</div>
                                    <div v-if="item.shortfall_reason" class="text-[11px] text-amber-800">{{ item.shortfall_reason }}</div>
                                </td>
                                <td class="hidden px-3 py-2 text-slate-500 lg:table-cell">
                                    <span v-if="item.planned_start_date">{{ formatDate(item.planned_start_date) }}</span>
                                    <span v-if="item.planned_end_date"> – {{ formatDate(item.planned_end_date) }}</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(item.previous_executed_qty ?? 0) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ item.today_target_qty != null ? formatQty(item.today_target_qty) : '—' }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular">{{ formatQty(item.executed_qty) }} {{ item.unit }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(item.hold_quantity ?? 0) }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ item.cumulative_qty != null ? formatQty(item.cumulative_qty) : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ item.balance_qty != null ? formatQty(item.balance_qty) : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ item.completion_percent != null ? `${formatQty(item.completion_percent)}%` : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ item.achievement_percent != null ? `${formatQty(item.achievement_percent)}%` : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-3 text-sm text-slate-500">No activity lines. Turn on “Show completed” if everything is marked done.</p>
            </AppCard>

            <AppCard title="Materials" subtitle="Opening / received / issued / closing" :padded="false">
                <div v-if="materials.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-xs">
                        <thead class="bg-slate-50 font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Item</th>
                                <th class="px-3 py-2 text-right">Opening</th>
                                <th class="px-3 py-2 text-right">Received</th>
                                <th class="px-3 py-2 text-right">Issued</th>
                                <th class="px-3 py-2 text-right">Closing</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="m in materials" :key="m.id">
                                <td class="px-3 py-2">{{ m.material }}<span class="block text-slate-500">{{ m.code }}</span></td>
                                <td class="px-3 py-2 text-right tabular">{{ m.opening_qty != null ? formatQty(m.opening_qty) : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ m.received_qty != null ? formatQty(m.received_qty) : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(m.issued_qty ?? m.quantity) }} {{ m.unit }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ m.closing_qty != null ? formatQty(m.closing_qty) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-3 text-sm text-slate-500">None.</p>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Manpower" :padded="false">
                    <ul v-if="labours.length" class="divide-y divide-line text-sm">
                        <li v-for="l in labours" :key="l.id" class="flex justify-between gap-2 px-4 py-2">
                            <span>{{ l.trade }}<span class="block text-xs text-slate-500">{{ l.subcontractor ?? 'Departmental' }}</span></span>
                            <span class="text-right tabular text-xs">
                                {{ l.headcount }} nos
                                <span v-if="l.skilled != null" class="block text-slate-500">S {{ l.skilled }} / U {{ l.unskilled ?? 0 }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">None.</p>
                </AppCard>
                <AppCard title="Equipment" :padded="false">
                    <ul v-if="equipment.length" class="divide-y divide-line text-sm">
                        <li v-for="e in equipment" :key="e.id" class="flex justify-between gap-2 px-4 py-2">
                            <span>{{ e.type ?? e.description }}<span v-if="e.operator_name" class="block text-xs text-slate-500">{{ e.operator_name }}</span></span>
                            <span class="text-right text-xs tabular">
                                {{ e.working_hours !== null ? Number(e.working_hours) : 0 }} h work
                                <span class="block text-slate-500">{{ e.idle_hours !== null ? Number(e.idle_hours) : 0 }} h idle</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-slate-500">None.</p>
                </AppCard>
            </div>

            <AppCard v-if="constraints.length || dpr.constraints_summary" title="Constraints / hindrances" :padded="false">
                <p v-if="dpr.constraints_summary" class="border-b border-line px-4 py-3 text-sm whitespace-pre-line text-slate-800">{{ dpr.constraints_summary }}</p>
                <ul v-if="constraints.length" class="divide-y divide-line text-sm">
                    <li v-for="c in constraints" :key="c.id" class="px-4 py-2">
                        <span class="font-medium text-slate-900">{{ c.category }}</span>
                        <span class="text-xs text-slate-500"> · {{ c.status }}</span>
                        <p class="mt-0.5 text-slate-700">{{ c.description }}</p>
                        <p class="text-xs text-slate-500">{{ c.work_area ?? c.task ?? '' }} {{ c.responsible ? `· ${c.responsible}` : '' }}</p>
                    </li>
                </ul>
            </AppCard>

            <AppCard v-if="dpr.work_performed || dpr.site_issues || dpr.remarks" title="Narrative">
                <p v-if="dpr.work_performed" class="text-sm whitespace-pre-line text-slate-800"><span class="font-medium text-slate-600">Work performed:</span> {{ dpr.work_performed }}</p>
                <p v-if="dpr.site_issues" class="mt-2 text-sm whitespace-pre-line text-slate-800">{{ dpr.site_issues }}</p>
                <p v-if="dpr.remarks" class="mt-2 text-sm whitespace-pre-line text-slate-600">{{ dpr.remarks }}</p>
            </AppCard>

            <AppCard v-if="diaries.length" title="Legacy site diary source" subtitle="This DPR was built from approved site diaries." :padded="false">
                <ul class="divide-y divide-line text-sm">
                    <li v-for="d in diaries" :key="d.id" class="flex items-center justify-between gap-2 px-4 py-2">
                        <span class="font-medium text-slate-900">{{ d.site ?? 'Record #' + d.id }} · {{ d.created_by }}</span>
                        <StatusBadge :status="d.status" :label="d.status_label" />
                    </li>
                </ul>
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
