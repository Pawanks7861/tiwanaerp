<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import LabourNav from '@/Components/Labour/LabourNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    date: { type: String, required: true },
    siteId: { type: Number, default: null },
    rows: { type: Array, required: true },
    summary: { type: Object, required: true },
    pendingDates: { type: Array, required: true },
    options: { type: Object, required: true },
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const STATUS_SHORT = { present: 'P', half_day: 'H', absent: 'A', leave: 'L' };
const STATUS_TONE = {
    present: 'bg-emerald-600 text-white border-emerald-600',
    half_day: 'bg-amber-500 text-white border-amber-500',
    absent: 'bg-red-600 text-white border-red-600',
    leave: 'bg-slate-600 text-white border-slate-600',
};

const filterDate = ref(props.date);
const filterSite = ref(props.siteId);
function reload(params = {}) {
    router.get(
        route('projects.attendance.index', props.project.id),
        { date: filterDate.value, site_id: filterSite.value ?? undefined, ...params },
        { preserveScroll: true, replace: true },
    );
}
watch(filterSite, () => reload());

function blank(row) {
    const a = row.attendance;

    return {
        labour_id: row.labour_id,
        status: a?.status ?? null,
        punch_in: a?.punch_in ?? '',
        punch_out: a?.punch_out ?? '',
        working_hours: a && a.punch_in ? null : (a?.working_hours ?? null),
        ot_hours: a && a.ot_hours !== '0.00' ? a.ot_hours : null,
        task_id: a?.task_id ?? null,
        remarks: a?.remarks ?? '',
    };
}

const extra = ref([]);
const sheet = ref({});
const expanded = ref({});
const selected = ref([]);
const dirty = ref(false);
function init() {
    sheet.value = Object.fromEntries(props.rows.map((r) => [r.labour_id, blank(r)]));
    extra.value = [];
    selected.value = [];
    dirty.value = false;
}
init();
watch(() => props.rows, init);

const allRows = computed(() => [...props.rows, ...extra.value]);
const isLocked = (row) => !props.can.mark || !!row.elsewhere || row.attendance?.approval_status === 'approved';
const editableRows = computed(() => allRows.value.filter((r) => !isLocked(r)));
const markedRows = computed(() => props.rows.filter((r) => r.attendance?.approval_status === 'marked' && !r.elsewhere));

function setStatus(row, status) {
    if (isLocked(row)) {
        return;
    }
    sheet.value[row.labour_id].status = status;
    dirty.value = true;
}
function markAll(status) {
    editableRows.value.forEach((r) => (sheet.value[r.labour_id].status = status));
    dirty.value = true;
}
function touch() {
    dirty.value = true;
}

const addLabourId = ref(null);
const addable = computed(() => props.options.labours.filter((l) => !allRows.value.some((r) => r.labour_id === l.value)));
watch(addLabourId, (id) => {
    const l = props.options.labours.find((o) => o.value === id);
    if (!l) {
        return;
    }
    const row = { labour_id: l.value, code: l.code, name: l.label, trade: null, daily_wage: l.daily_wage, ot_rate: l.ot_rate, elsewhere: null, attendance: null };
    extra.value.push(row);
    sheet.value[l.value] = { ...blank(row), status: 'present' };
    dirty.value = true;
    addLabourId.value = null;
});

const saving = ref(false);
const sentIds = ref([]);
const toSave = computed(() => editableRows.value.map((r) => sheet.value[r.labour_id]).filter((s) => s.status));
function save() {
    const rows = toSave.value.map((s) => ({
        ...s,
        punch_in: s.punch_in || null,
        punch_out: s.punch_out || null,
        working_hours: s.punch_in || s.punch_out ? null : s.working_hours,
    }));
    sentIds.value = rows.map((r) => r.labour_id);
    saving.value = true;
    router.post(
        route('projects.attendance.store', props.project.id),
        { attendance_date: props.date, site_id: props.siteId, rows },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}

function rowErrors(labourId) {
    const index = sentIds.value.indexOf(labourId);
    if (index < 0) {
        return [];
    }
    const prefix = `rows.${index}.`;

    return Object.entries(page.props.errors ?? {})
        .filter(([key]) => key.startsWith(prefix))
        .map(([, message]) => message);
}
const sheetErrors = computed(() => {
    const e = page.props.errors ?? {};

    return ['attendance_date', 'site_id', 'rows', 'ids', 'attendance'].map((k) => e[k]).filter(Boolean);
});

const approving = ref(false);
function approve(ids) {
    approving.value = true;
    router.post(route('projects.attendance.approve', props.project.id), { ids }, { preserveScroll: true, onFinish: () => (approving.value = false) });
}
function toggleSelect(id) {
    selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id];
}

const unapproving = ref(null);
const deleting = ref(null);
const deleteProcessing = ref(false);
function destroyRow() {
    deleteProcessing.value = true;
    router.delete(route('projects.attendance.destroy', [props.project.id, deleting.value.attendance.id]), {
        preserveScroll: true,
        onFinish: () => ((deleteProcessing.value = false), (deleting.value = null)),
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="labour" title="Attendance">
        <LabourNav :project-id="project.id" active="attendance" />
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="grid gap-3 p-4 sm:grid-cols-3 sm:p-5">
                    <FormInput v-model="filterDate" type="date" label="Date" :max="today" @change="reload()" />
                    <FormSelect v-model="filterSite" label="Site" :options="options.sites" placeholder="Whole project" />
                    <div class="flex items-end gap-2">
                        <AppButton variant="secondary" size="sm" :disabled="date === today" @click="(filterDate = today), reload()">Today</AppButton>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-px border-t border-line bg-line text-center sm:grid-cols-7">
                    <div class="bg-white px-2 py-2"><p class="text-[11px] text-slate-500 uppercase">Present</p><p class="font-semibold tabular text-emerald-700">{{ summary.present }}</p></div>
                    <div class="bg-white px-2 py-2"><p class="text-[11px] text-slate-500 uppercase">Half</p><p class="font-semibold tabular text-amber-700">{{ summary.half_day }}</p></div>
                    <div class="bg-white px-2 py-2"><p class="text-[11px] text-slate-500 uppercase">Absent</p><p class="font-semibold tabular text-red-700">{{ summary.absent }}</p></div>
                    <div class="bg-white px-2 py-2"><p class="text-[11px] text-slate-500 uppercase">Leave</p><p class="font-semibold tabular">{{ summary.leave }}</p></div>
                    <div class="bg-white px-2 py-2"><p class="text-[11px] text-slate-500 uppercase">To approve</p><p class="font-semibold tabular">{{ summary.marked }}</p></div>
                    <div class="bg-white px-2 py-2"><p class="text-[11px] text-slate-500 uppercase">Approved</p><p class="font-semibold tabular">{{ summary.approved }}</p></div>
                    <div class="col-span-2 bg-white px-2 py-2 sm:col-span-1"><p class="text-[11px] text-slate-500 uppercase">Wages</p><p class="font-semibold tabular">{{ formatMoney(summary.wages) }}</p></div>
                </div>
                <div v-if="pendingDates.length" class="flex flex-wrap items-center gap-1.5 border-t border-line px-4 py-2.5 text-xs sm:px-5">
                    <span class="text-slate-500">Awaiting approval:</span>
                    <button
                        v-for="d in pendingDates"
                        :key="d.date"
                        type="button"
                        class="rounded-full border px-2 py-0.5"
                        :class="d.date === date ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-line text-slate-600 hover:bg-slate-50'"
                        @click="(filterDate = d.date), reload()"
                    >
                        {{ formatDate(d.date) }} · {{ d.count }}
                    </button>
                </div>
            </AppCard>

            <div v-if="sheetErrors.length" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                <p v-for="(e, i) in sheetErrors" :key="i">{{ e }}</p>
            </div>

            <AppCard :title="`Attendance · ${formatDate(date)}`" subtitle="Tap P / H / A / L for each labourer, then save. Approval posts the day's labour cost." :padded="false">
                <template #actions>
                    <template v-if="can.mark && editableRows.length">
                        <AppButton size="sm" variant="secondary" @click="markAll('present')">All present</AppButton>
                    </template>
                    <AppButton v-if="can.approve && markedRows.length" size="sm" variant="secondary" :loading="approving" :disabled="dirty" @click="approve(markedRows.map((r) => r.attendance.id))">
                        Approve all ({{ markedRows.length }})
                    </AppButton>
                </template>

                <EmptyState v-if="!allRows.length" icon="users" title="No crew for this project" description="Assign labourers to this project in the labour register, or add one below." />

                <ul class="divide-y divide-line">
                    <li v-for="row in allRows" :key="row.labour_id" class="px-4 py-3 sm:px-5" :class="isLocked(row) ? 'bg-slate-50/60' : ''">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <input
                                v-if="can.approve && row.attendance?.approval_status === 'marked' && !row.elsewhere"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-brand-600"
                                :checked="selected.includes(row.attendance.id)"
                                :aria-label="`Select ${row.name}`"
                                @change="toggleSelect(row.attendance.id)"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-slate-900">{{ row.name }}</p>
                                <p class="truncate text-xs text-slate-500">
                                    <span class="font-mono">{{ row.code }}</span><template v-if="row.trade"> · {{ row.trade }}</template> · {{ formatMoney(row.daily_wage) }}/day
                                </p>
                            </div>
                            <div v-if="row.elsewhere" class="text-xs text-amber-700"><Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />Marked on {{ row.elsewhere }}</div>
                            <div v-else class="flex shrink-0 gap-1" role="group" :aria-label="`Status of ${row.name}`">
                                <button
                                    v-for="s in options.statuses"
                                    :key="s.value"
                                    type="button"
                                    class="h-9 w-9 rounded-md border text-sm font-semibold transition disabled:cursor-not-allowed"
                                    :class="sheet[row.labour_id]?.status === s.value ? STATUS_TONE[s.value] : 'border-line bg-white text-slate-600 hover:bg-slate-50'"
                                    :disabled="isLocked(row)"
                                    :title="s.label"
                                    :aria-pressed="sheet[row.labour_id]?.status === s.value"
                                    @click="setStatus(row, s.value)"
                                >
                                    {{ STATUS_SHORT[s.value] }}
                                </button>
                            </div>
                        </div>

                        <div v-if="row.attendance" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600">
                            <StatusBadge :status="row.attendance.approval_status" :label="row.attendance.approval_status === 'approved' ? 'Approved' : 'Marked'" />
                            <span class="tabular">{{ formatNumber(row.attendance.working_hours, 0, 2) }} h<template v-if="row.attendance.ot_hours !== '0.00'"> + {{ formatNumber(row.attendance.ot_hours, 0, 2) }} OT</template></span>
                            <span class="tabular">{{ formatMoney(row.attendance.wage_amount) }}<template v-if="row.attendance.ot_amount !== '0.00'"> + {{ formatMoney(row.attendance.ot_amount) }}</template></span>
                            <span v-if="row.attendance.payment_number" class="font-mono">{{ row.attendance.payment_number }}</span>
                            <span v-else-if="row.attendance.approved_by">by {{ row.attendance.approved_by }}</span>
                            <span class="ml-auto flex gap-1">
                                <AppButton v-if="can.approve && row.attendance.approval_status === 'approved' && !row.attendance.payment_number" size="sm" variant="ghost" @click="unapproving = row">Un-approve</AppButton>
                                <AppButton v-if="can.mark && row.attendance.approval_status === 'marked'" size="sm" variant="ghost" icon="trash" :aria-label="`Remove ${row.name}`" @click="deleting = row" />
                            </span>
                        </div>

                        <template v-if="!isLocked(row) && sheet[row.labour_id]?.status && ['present', 'half_day'].includes(sheet[row.labour_id].status)">
                            <button type="button" class="mt-1.5 text-xs font-medium text-brand-700" @click="expanded[row.labour_id] = !expanded[row.labour_id]">
                                {{ expanded[row.labour_id] ? 'Hide details' : 'Hours, OT, task…' }}
                            </button>
                            <div v-if="expanded[row.labour_id]" class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-6">
                                <FormInput v-model="sheet[row.labour_id].punch_in" type="time" label="In" @input="touch" />
                                <FormInput v-model="sheet[row.labour_id].punch_out" type="time" label="Out" @input="touch" />
                                <DecimalInput v-model="sheet[row.labour_id].working_hours" label="Hours" :decimals="2" :disabled="!!(sheet[row.labour_id].punch_in || sheet[row.labour_id].punch_out)" @input="touch" />
                                <DecimalInput v-model="sheet[row.labour_id].ot_hours" label="OT hours" :decimals="2" @input="touch" />
                                <FormSelect v-model="sheet[row.labour_id].task_id" class="col-span-2" label="Task" :options="options.tasks" placeholder="No task" @change="touch" />
                                <FormInput v-model="sheet[row.labour_id].remarks" class="col-span-2 sm:col-span-6" label="Remarks" maxlength="500" @input="touch" />
                            </div>
                        </template>

                        <p v-for="(e, i) in rowErrors(row.labour_id)" :key="i" class="mt-1 text-xs text-red-700">{{ e }}</p>
                    </li>
                </ul>

                <template v-if="can.mark || can.approve" #footer>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <FormSelect v-if="can.mark && addable.length" v-model="addLabourId" class="sm:w-72" label="Add labourer from register" :options="addable" placeholder="Choose labourer…" />
                        <div v-else />
                        <div class="flex flex-wrap gap-2">
                            <AppButton v-if="can.approve && selected.length" variant="secondary" :loading="approving" :disabled="dirty" @click="approve(selected)">Approve selected ({{ selected.length }})</AppButton>
                            <AppButton v-if="can.mark && editableRows.length" :loading="saving" :disabled="!toSave.length" @click="save">Save attendance ({{ toSave.length }})</AppButton>
                        </div>
                    </div>
                    <p v-if="dirty && can.approve" class="mt-2 text-xs text-amber-700">Save your changes before approving.</p>
                </template>
            </AppCard>
        </div>

        <ReasonDialog
            :show="!!unapproving"
            :url="unapproving ? route('projects.attendance.unapprove', [project.id, unapproving.attendance.id]) : null"
            title="Un-approve attendance"
            message="The day's labour cost is reversed and the entry goes back to marked so it can be corrected."
            confirm-label="Un-approve"
            @close="unapproving = null"
        />
        <ConfirmDialog
            :show="!!deleting"
            title="Remove this entry?"
            :message="deleting ? `${deleting.name}'s unapproved attendance for ${formatDate(date)} will be removed.` : ''"
            confirm-label="Remove"
            :processing="deleteProcessing"
            @close="deleting = null"
            @confirm="destroyRow"
        />
    </ProjectLayout>
</template>
