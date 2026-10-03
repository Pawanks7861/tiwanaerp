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

const props = defineProps({
    project: { type: Object, required: true },
    dpr: { type: Object, required: true },
    options: { type: Object, required: true },
});

let tempId = 0;
const keyed = (rows) => rows.map((r) => ({ _key: `r${++tempId}`, ...r }));
const blankItem = () => ({ id: null, task_id: null, boq_item_id: null, description: '', unit_id: null, executed_qty: null, planned_qty: null, cumulative_qty: null, balance_qty: null });
const blankLabour = () => ({ labour_trade_id: null, subcontractor_id: null, headcount: null, hours: '8', remarks: '' });
const blankEquipment = () => ({ equipment_type_id: null, description: '', working_hours: null, idle_hours: null });
const blankMaterial = () => ({ material_id: null, quantity: null, unit_id: null, remarks: '' });

const form = useForm({
    engineer_id: props.dpr.engineer_id,
    weather: props.dpr.weather ?? '',
    site_issues: props.dpr.site_issues ?? '',
    remarks: props.dpr.remarks ?? '',
    items: keyed(props.dpr.items),
    labours: keyed(props.dpr.labours),
    equipment: keyed(props.dpr.equipment),
    materials: keyed(props.dpr.materials),
});

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
    }
}

function save() {
    form.transform((d) => ({
        ...d,
        items: d.items.map(({ _key, planned_qty, cumulative_qty, balance_qty, ...l }) => ({ ...l, description: l.description || null })),
        labours: d.labours.map(({ _key, ...l }) => ({ ...l, remarks: l.remarks || null })),
        equipment: d.equipment.map(({ _key, ...l }) => ({ ...l, description: l.description || null })),
        materials: d.materials.map(({ _key, ...l }) => ({ ...l, unit_id: l.unit_id ?? materialOf(l.material_id)?.unit_id ?? null, remarks: l.remarks || null })),
    })).put(route('projects.dprs.update', [props.project.id, props.dpr.id]), { preserveScroll: true });
}
</script>

<template>
    <ProjectLayout :project="project" active="dprs" :title="`Edit ${dpr.dpr_number}`">
        <form class="space-y-4 pb-20" @submit.prevent="save">
            <AppCard :title="`${dpr.dpr_number} · ${formatDate(dpr.dpr_date)}`" subtitle="Draft DPR. Planned, cumulative and balance are recalculated by the system; only today's quantities are entered.">
                <p v-if="form.errors.items || form.errors.dpr" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ form.errors.items || form.errors.dpr }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <SearchSelect v-model="form.engineer_id" label="Site engineer" :options="options.members" :error="form.errors.engineer_id" />
                    <FormInput v-model="form.weather" label="Weather" maxlength="150" :error="form.errors.weather" />
                    <FormInput v-model="form.site_issues" label="Site issues" multiline :rows="3" maxlength="10000" :error="form.errors.site_issues" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="3" maxlength="5000" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard title="Work progress" subtitle="A line that was posted in an earlier revision cannot be removed; set its quantity to 0 instead." :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in form.items" :key="line._key" class="p-4">
                        <div class="grid gap-3 sm:grid-cols-6 lg:grid-cols-12">
                            <SearchSelect v-model="line.task_id" label="Task" :options="options.tasks" class="sm:col-span-3 lg:col-span-4" :error="err('items', i, 'task_id')" @update:model-value="taskChanged(line)" />
                            <SearchSelect v-model="line.boq_item_id" label="BOQ item" :options="options.boq_items" class="sm:col-span-3 lg:col-span-4" :error="err('items', i, 'boq_item_id')" />
                            <FormInput v-model="line.description" label="Description" maxlength="500" class="sm:col-span-6 lg:col-span-4" :error="err('items', i, 'description')" />
                            <DecimalInput v-model="line.executed_qty" label="Today" required :decimals="4" :suffix="unitSymbol(line.unit_id)" class="sm:col-span-2 lg:col-span-3" :error="err('items', i, 'executed_qty')" />
                            <SearchSelect v-model="line.unit_id" label="Unit" :options="options.units" :clearable="false" class="sm:col-span-2 lg:col-span-2" :error="err('items', i, 'unit_id')" />
                            <div class="flex items-end justify-between gap-2 text-xs text-slate-500 sm:col-span-2 lg:col-span-7">
                                <span v-if="line.planned_qty !== null" class="tabular">Planned {{ formatQty(line.planned_qty) }} · cumulative {{ formatQty(line.cumulative_qty ?? 0) }} · balance {{ formatQty(line.balance_qty ?? 0) }}</span>
                                <span v-else />
                                <button type="button" class="flex size-11 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove line ${i + 1}`" @click="form.items.splice(i, 1)">
                                    <Icon name="trash" :size="16" />
                                </button>
                            </div>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('items', blankItem)">Add line</AppButton></div>
            </AppCard>

            <AppCard title="Labour" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in form.labours" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-6 lg:grid-cols-12">
                        <SearchSelect v-model="line.labour_trade_id" label="Trade" required :options="options.trades" class="sm:col-span-3 lg:col-span-3" :error="err('labours', i, 'labour_trade_id')" />
                        <SearchSelect v-model="line.subcontractor_id" label="Subcontractor" :options="options.subcontractors" class="sm:col-span-3 lg:col-span-3" :error="err('labours', i, 'subcontractor_id')" />
                        <FormInput v-model="line.headcount" type="number" min="1" label="Headcount" required class="sm:col-span-2 lg:col-span-2" :error="err('labours', i, 'headcount')" />
                        <DecimalInput v-model="line.hours" label="Hours" :decimals="2" class="sm:col-span-2 lg:col-span-2" :error="err('labours', i, 'hours')" />
                        <div class="flex items-end sm:col-span-2 lg:col-span-2">
                            <button type="button" class="flex size-11 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove labour ${i + 1}`" @click="form.labours.splice(i, 1)"><Icon name="trash" :size="16" /></button>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('labours', blankLabour)">Add labour</AppButton></div>
            </AppCard>

            <AppCard title="Equipment" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in form.equipment" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-6 lg:grid-cols-12">
                        <SearchSelect v-model="line.equipment_type_id" label="Equipment type" :options="options.equipment_types" class="sm:col-span-3 lg:col-span-3" :error="err('equipment', i, 'equipment_type_id')" />
                        <FormInput v-model="line.description" label="Description" maxlength="150" class="sm:col-span-3 lg:col-span-3" :error="err('equipment', i, 'description')" />
                        <DecimalInput v-model="line.working_hours" label="Working hours" :decimals="2" class="sm:col-span-2 lg:col-span-2" :error="err('equipment', i, 'working_hours')" />
                        <DecimalInput v-model="line.idle_hours" label="Idle hours" :decimals="2" class="sm:col-span-2 lg:col-span-2" :error="err('equipment', i, 'idle_hours')" />
                        <div class="flex items-end sm:col-span-2 lg:col-span-2">
                            <button type="button" class="flex size-11 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove equipment ${i + 1}`" @click="form.equipment.splice(i, 1)"><Icon name="trash" :size="16" /></button>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('equipment', blankEquipment)">Add equipment</AppButton></div>
            </AppCard>

            <AppCard title="Material used" subtitle="Reported only; no stock or cost is posted." :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, i) in form.materials" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-6 lg:grid-cols-12">
                        <SearchSelect v-model="line.material_id" label="Item" required :options="options.materials" class="sm:col-span-3 lg:col-span-5" :error="err('materials', i, 'material_id') || err('materials', i, 'unit_id')" />
                        <DecimalInput v-model="line.quantity" label="Quantity" required :decimals="4" :suffix="materialOf(line.material_id)?.unit ?? ''" class="sm:col-span-3 lg:col-span-2" :error="err('materials', i, 'quantity')" />
                        <FormInput v-model="line.remarks" label="Remarks" maxlength="255" class="sm:col-span-4 lg:col-span-3" :error="err('materials', i, 'remarks')" />
                        <div class="flex items-end sm:col-span-2 lg:col-span-2">
                            <button type="button" class="flex size-11 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove material ${i + 1}`" @click="form.materials.splice(i, 1)"><Icon name="trash" :size="16" /></button>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3"><AppButton size="sm" variant="secondary" icon="plus" @click="add('materials', blankMaterial)">Add material</AppButton></div>
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
