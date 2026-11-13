<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatQty } from '@/lib/format';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    dpr: { type: Object, required: true },
    options: { type: Object, required: true },
});

let tempId = 0;
const keyed = (rows) => rows.map((r) => ({ _key: `r${++tempId}`, ...r }));
const showCompleted = ref(false);

const blankItem = () => ({
    id: null, task_id: null, boq_item_id: null, description: '', work_area: '', unit_id: null,
    planned_start_date: null, planned_end_date: null, planned_qty: null, previous_executed_qty: null,
    today_target_qty: null, executed_qty: null, hold_quantity: '0', hold_reason: '', on_hold: false,
    cumulative_qty: null, balance_qty: null, shortfall_reason: '', remarks: '',
});
const blankLabour = () => ({ labour_trade_id: null, subcontractor_id: null, headcount: null, skilled: null, unskilled: null, operator_count: null, helper_count: null, hours: '8', remarks: '' });
const blankEquipment = () => ({ equipment_type_id: null, description: '', operator_name: '', start_time: '', end_time: '', working_hours: null, idle_hours: null, duration_hours: null, status: '', remarks: '' });
const blankMaterial = () => ({ material_id: null, opening_qty: null, received_qty: null, issued_qty: null, closing_qty: null, quantity: null, unit_id: null, remarks: '' });
const blankConstraint = () => ({ category: '', description: '', work_area: '', task_id: null, responsible: '', priority: '', raised_date: null, expected_resolution: null, status: 'open', remarks: '' });

const form = useForm({
    engineer_id: props.dpr.engineer_id,
    weather: props.dpr.weather ?? '',
    site_issues: props.dpr.site_issues ?? '',
    remarks: props.dpr.remarks ?? '',
    work_performed: props.dpr.work_performed ?? '',
    constraints_summary: props.dpr.constraints_summary ?? '',
    department: props.dpr.department ?? '',
    agency_name: props.dpr.agency_name ?? '',
    items: keyed(props.dpr.items),
    labours: keyed(props.dpr.labours ?? []),
    equipment: keyed(props.dpr.equipment ?? []),
    materials: keyed(props.dpr.materials ?? []),
    constraints: keyed(props.dpr.constraints ?? []),
});

function lineComplete(line) {
    const planned = Number(line.planned_qty ?? 0);
    const cum = Number(line.cumulative_qty ?? line.previous_executed_qty ?? 0);
    return planned > 0 && cum >= planned;
}

function lineVisible(line) {
    return showCompleted.value || !lineComplete(line);
}

const add = (list, blank) => form[list].push({ _key: `r${++tempId}`, ...blank() });
const err = (list, i, f) => form.errors[`${list}.${i}.${f}`];
const taskOf = (id) => props.options.tasks.find((t) => t.value === id);
const unitSymbol = (id) => props.options.units.find((u) => u.value === id)?.label ?? '';
const materialOf = (id) => props.options.materials.find((m) => m.value === id);

function taskChanged(line) {
    const task = taskOf(line.task_id);
    if (task) {
        line.unit_id = task.unit_id ?? line.unit_id;
        line.boq_item_id = task.boq_item_id ?? line.boq_item_id;
        line.description = line.description || task.label || '';
    }
}

function stripPayload(rows, extra = []) {
    return rows.map(({ _key, ...l }) => {
        extra.forEach((k) => delete l[k]);
        return l;
    });
}

function save() {
    form.transform((d) => ({
        ...d,
        items: stripPayload(d.items),
        labours: stripPayload(d.labours).map((l) => ({ ...l, remarks: l.remarks || null })),
        equipment: stripPayload(d.equipment).map((l) => ({ ...l, description: l.description || null })),
        materials: stripPayload(d.materials).map((l) => ({
            ...l,
            unit_id: l.unit_id ?? materialOf(l.material_id)?.unit_id ?? null,
            quantity: l.quantity ?? l.issued_qty,
            remarks: l.remarks || null,
        })),
        constraints: stripPayload(d.constraints).filter((c) => c.category && c.description),
    })).put(route('projects.dprs.update', [props.project.id, props.dpr.id]), { preserveScroll: true });
}
</script>

<template>
    <ProjectLayout :project="project" active="dprs" :title="`Edit ${dpr.dpr_number}`">
        <form class="space-y-4 pb-20" @submit.prevent="save">
            <AppCard :title="`${dpr.dpr_number} · ${formatDate(dpr.dpr_date)}`" subtitle="Direct DPR · planned, cumulative and balance are recalculated when you save.">
                <p v-if="form.errors.items || form.errors.dpr" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ form.errors.items || form.errors.dpr }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <SearchSelect v-model="form.engineer_id" label="Site engineer" :options="options.members" :error="form.errors.engineer_id" />
                    <FormInput v-model="form.department" label="Department" maxlength="120" :error="form.errors.department" />
                    <FormInput v-model="form.agency_name" label="Agency" maxlength="160" :error="form.errors.agency_name" />
                    <FormInput v-model="form.weather" label="Weather" maxlength="150" :error="form.errors.weather" />
                    <FormInput v-model="form.work_performed" label="Work performed (summary)" multiline :rows="2" maxlength="10000" :error="form.errors.work_performed" />
                    <FormInput v-model="form.constraints_summary" label="Constraints summary" multiline :rows="2" maxlength="10000" :error="form.errors.constraints_summary" />
                    <FormInput v-model="form.site_issues" label="Site issues" multiline :rows="2" maxlength="10000" :error="form.errors.site_issues" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="2" maxlength="5000" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard title="Activity progress" :padded="false">
                <template #actions>
                    <label class="flex items-center gap-2 px-4 text-xs text-slate-600">
                        <input v-model="showCompleted" type="checkbox" class="rounded border-slate-300 text-brand-600" />
                        Show completed
                    </label>
                </template>
                <div class="overflow-x-auto">
                    <table class="min-w-[960px] divide-y divide-line text-xs">
                        <thead class="bg-slate-50 font-semibold text-slate-500 uppercase">
                            <tr>
                                <th class="px-2 py-2 text-left">Area / activity</th>
                                <th class="px-2 py-2 text-right">Prev</th>
                                <th class="px-2 py-2 text-right">Target</th>
                                <th class="px-2 py-2 text-right">Today</th>
                                <th class="px-2 py-2 text-right">Hold</th>
                                <th class="px-2 py-2 text-right">Cum / bal</th>
                                <th class="px-2 py-2 text-right">%</th>
                                <th class="w-8" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(line, i) in form.items" v-show="lineVisible(line)" :key="line._key">
                                <td class="px-2 py-2 align-top">
                                    <div class="mb-1 font-medium text-slate-800">{{ line.work_area || '—' }}</div>
                                    <SearchSelect v-model="line.task_id" :options="options.tasks" :error="err('items', i, 'task_id')" @update:model-value="taskChanged(line)" />
                                    <FormInput v-model="line.description" class="mt-1" maxlength="500" placeholder="Description" :error="err('items', i, 'description')" />
                                    <FormInput v-model="line.shortfall_reason" class="mt-1" maxlength="500" placeholder="Shortfall reason" :error="err('items', i, 'shortfall_reason')" />
                                </td>
                                <td class="px-2 py-2 text-right align-top tabular text-slate-600">{{ formatQty(line.previous_executed_qty ?? 0) }}</td>
                                <td class="px-2 py-2 align-top">
                                    <DecimalInput v-model="line.today_target_qty" :decimals="4" :suffix="unitSymbol(line.unit_id)" :error="err('items', i, 'today_target_qty')" />
                                </td>
                                <td class="px-2 py-2 align-top">
                                    <DecimalInput v-model="line.executed_qty" required :decimals="4" :suffix="unitSymbol(line.unit_id)" :error="err('items', i, 'executed_qty')" />
                                </td>
                                <td class="px-2 py-2 align-top">
                                    <DecimalInput v-model="line.hold_quantity" :decimals="4" :error="err('items', i, 'hold_quantity')" />
                                    <FormInput v-model="line.hold_reason" class="mt-1" maxlength="80" placeholder="Hold reason" />
                                </td>
                                <td class="px-2 py-2 text-right align-top tabular text-[11px] text-slate-500">
                                    {{ formatQty(line.cumulative_qty ?? 0) }}<br />{{ formatQty(line.balance_qty ?? 0) }}
                                </td>
                                <td class="px-2 py-2 text-right align-top tabular text-[11px] text-slate-500">
                                    <span v-if="line.completion_percent != null">{{ formatQty(line.completion_percent) }}%</span>
                                    <span v-if="line.achievement_percent != null" class="block">{{ formatQty(line.achievement_percent) }}% ach.</span>
                                </td>
                                <td class="px-2 py-2 align-top">
                                    <button type="button" class="flex size-9 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" @click="form.items.splice(form.items.indexOf(line), 1)">
                                        <Icon name="trash" :size="14" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('items', blankItem)">Add activity</AppButton></div>
            </AppCard>

            <AppCard title="Materials" subtitle="Opening / received / issued / closing (informational)" :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-xs">
                        <thead class="bg-slate-50 font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Item</th>
                                <th class="px-3 py-2 text-right">Opening</th>
                                <th class="px-3 py-2 text-right">Received</th>
                                <th class="px-3 py-2 text-right">Issued</th>
                                <th class="px-3 py-2 text-right">Closing</th>
                                <th class="w-8" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(line, i) in form.materials" :key="line._key">
                                <td class="px-3 py-2">
                                    <SearchSelect v-model="line.material_id" required :options="options.materials" :error="err('materials', i, 'material_id')" />
                                </td>
                                <td class="px-3 py-2"><DecimalInput v-model="line.opening_qty" :decimals="4" :error="err('materials', i, 'opening_qty')" /></td>
                                <td class="px-3 py-2"><DecimalInput v-model="line.received_qty" :decimals="4" :error="err('materials', i, 'received_qty')" /></td>
                                <td class="px-3 py-2"><DecimalInput v-model="line.issued_qty" :decimals="4" :error="err('materials', i, 'issued_qty')" /></td>
                                <td class="px-3 py-2"><DecimalInput v-model="line.closing_qty" :decimals="4" :error="err('materials', i, 'closing_qty')" /></td>
                                <td class="px-3 py-2">
                                    <button type="button" class="text-slate-400 hover:text-red-600" @click="form.materials.splice(i, 1)"><Icon name="trash" :size="14" /></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('materials', blankMaterial)">Add material</AppButton></div>
            </AppCard>

            <AppCard title="Manpower" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in form.labours" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-6 lg:grid-cols-12">
                        <SearchSelect v-model="line.labour_trade_id" label="Trade" required :options="options.trades" class="sm:col-span-3" :error="err('labours', i, 'labour_trade_id')" />
                        <SearchSelect v-model="line.subcontractor_id" label="Subcontractor" :options="options.subcontractors" class="sm:col-span-3" />
                        <FormInput v-model="line.headcount" type="number" min="1" label="Headcount" required class="sm:col-span-2" :error="err('labours', i, 'headcount')" />
                        <FormInput v-model="line.skilled" type="number" min="0" label="Skilled" class="sm:col-span-2" />
                        <FormInput v-model="line.unskilled" type="number" min="0" label="Unskilled" class="sm:col-span-2" />
                        <DecimalInput v-model="line.hours" label="Hours" :decimals="2" class="sm:col-span-2" />
                        <button type="button" class="flex size-11 items-center justify-center self-end text-slate-400 hover:text-red-600" @click="form.labours.splice(i, 1)"><Icon name="trash" :size="16" /></button>
                    </li>
                </ol>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('labours', blankLabour)">Add labour</AppButton></div>
            </AppCard>

            <AppCard title="Equipment" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in form.equipment" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-6 lg:grid-cols-12">
                        <SearchSelect v-model="line.equipment_type_id" label="Type" :options="options.equipment_types" class="sm:col-span-3" />
                        <FormInput v-model="line.description" label="Description" maxlength="150" class="sm:col-span-3" />
                        <FormInput v-model="line.operator_name" label="Operator" maxlength="120" class="sm:col-span-2" />
                        <FormInput v-model="line.start_time" type="time" label="Start" class="sm:col-span-2" />
                        <FormInput v-model="line.end_time" type="time" label="End" class="sm:col-span-2" />
                        <DecimalInput v-model="line.working_hours" label="Work h" :decimals="2" class="sm:col-span-2" />
                        <DecimalInput v-model="line.idle_hours" label="Idle h" :decimals="2" class="sm:col-span-2" />
                        <button type="button" class="flex size-11 items-center justify-center self-end text-slate-400 hover:text-red-600" @click="form.equipment.splice(i, 1)"><Icon name="trash" :size="16" /></button>
                    </li>
                </ol>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('equipment', blankEquipment)">Add equipment</AppButton></div>
            </AppCard>

            <AppCard title="Constraints / hindrances" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in form.constraints" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-2">
                        <FormInput v-model="line.category" label="Category" required maxlength="80" :error="err('constraints', i, 'category')" />
                        <FormInput v-model="line.work_area" label="Work area" maxlength="160" />
                        <FormInput v-model="line.description" label="Description" required multiline :rows="2" maxlength="5000" class="sm:col-span-2" :error="err('constraints', i, 'description')" />
                        <FormInput v-model="line.responsible" label="Responsible" maxlength="160" />
                        <FormInput v-model="line.status" label="Status" maxlength="40" />
                        <button type="button" class="text-sm text-slate-400 hover:text-red-600 sm:col-span-2" @click="form.constraints.splice(i, 1)">Remove</button>
                    </li>
                </ol>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('constraints', blankConstraint)">Add constraint</AppButton></div>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-white/95 p-3 backdrop-blur">
                <div class="mx-auto flex max-w-7xl justify-end gap-2">
                    <AppButton variant="ghost" class="min-h-11" :href="route('projects.dprs.show', [project.id, dpr.id])">Cancel</AppButton>
                    <AppButton type="submit" class="min-h-11" :loading="form.processing">Save DPR</AppButton>
                </div>
            </div>
        </form>
    </ProjectLayout>
</template>
