<script setup>
import CellDecimal from '@/Components/Boq/CellDecimal.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { rateAnalysis } from '@/lib/boqCalc';
import { formatDateTime, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    analysis: { type: Object, default: null },
    units: { type: Array, required: true },
    materials: { type: Array, required: true },
    labourTrades: { type: Array, required: true },
    equipmentTypes: { type: Array, required: true },
    resourceTypes: { type: Array, required: true },
    can: { type: Object, required: true },
});

const editable = computed(() => props.can.update);
const blankItem = (type = 'material') => ({ resource_type: type, material_id: null, labour_trade_id: null, equipment_type_id: null, description: '', unit_id: null, quantity: null, wastage_percent: null, rate: null });

const form = useForm({
    name: props.analysis?.name ?? '',
    description: props.analysis?.description ?? '',
    unit_id: props.analysis?.unit_id ?? null,
    output_quantity: props.analysis?.output_quantity ?? '1',
    overhead_percent: props.analysis?.overhead_percent ?? '0',
    profit_percent: props.analysis?.profit_percent ?? '0',
    items: props.analysis?.items?.map((i) => ({ ...blankItem(), ...i })) ?? [blankItem()],
});

const preview = computed(() => rateAnalysis(form, form.items));
const unitLabel = (id) => props.units.find((u) => u.value === id)?.label ?? '';
const typeLabel = (t) => props.resourceTypes.find((r) => r.value === t)?.label ?? t;

function pickMaster(item, id) {
    const list = { material: props.materials, labour: props.labourTrades, equipment: props.equipmentTypes }[item.resource_type] ?? [];
    const master = list.find((m) => m.value === id);
    const column = { material: 'material_id', labour: 'labour_trade_id', equipment: 'equipment_type_id' }[item.resource_type];
    item[column] = id;
    if (master) {
        item.description ||= master.label;
        if (master.unit_id && !item.unit_id) {
            item.unit_id = master.unit_id;
        }
        if (master.rate && !item.rate) {
            item.rate = master.rate;
        }
    }
}
const masterOptions = (type) => ({ material: props.materials, labour: props.labourTrades, equipment: props.equipmentTypes })[type] ?? null;
const masterValue = (item) => ({ material: item.material_id, labour: item.labour_trade_id, equipment: item.equipment_type_id })[item.resource_type] ?? null;

const err = (index, field) => form.errors[`items.${index}.${field}`];

function submit() {
    const url = props.analysis ? route('projects.rate-analyses.update', [props.project.id, props.analysis.id]) : route('projects.rate-analyses.store', props.project.id);
    form[props.analysis ? 'put' : 'post'](url, { preserveScroll: true });
}

const confirming = ref(null);
const processing = ref(false);
function confirmAction() {
    processing.value = true;
    const done = { onFinish: () => ((processing.value = false), (confirming.value = null)) };
    if (confirming.value === 'approve') {
        router.post(route('projects.rate-analyses.approve', [props.project.id, props.analysis.id]), {}, { preserveScroll: true, ...done });
    } else {
        router.delete(route('projects.rate-analyses.destroy', [props.project.id, props.analysis.id]), done);
    }
}
</script>

<template>
    <ProjectLayout :project="project" active="rate-analysis" :title="analysis ? analysis.code : 'New rate analysis'">
        <form class="space-y-4" @submit.prevent="submit">
            <AppCard>
                <template #header>
                    <div class="min-w-0">
                        <Link :href="route('projects.rate-analyses.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Rate analyses</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-slate-900">{{ analysis ? `${analysis.code} · ${analysis.name}` : 'New rate analysis' }}</h2>
                            <StatusBadge v-if="analysis" :status="analysis.status" :label="analysis.status_label" />
                        </div>
                        <p v-if="analysis?.approved_at" class="text-xs text-slate-500">Approved {{ formatDateTime(analysis.approved_at) }}<template v-if="analysis.approved_by"> by {{ analysis.approved_by }}</template> · locked</p>
                    </div>
                </template>
                <template #actions>
                    <AppButton v-if="can.approve" size="sm" variant="secondary" @click="confirming = 'approve'">Approve</AppButton>
                    <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete" @click="confirming = 'delete'" />
                </template>

                <fieldset :disabled="!editable" class="grid gap-4 sm:grid-cols-6">
                    <FormInput v-model="form.name" label="Name" required maxlength="255" class="sm:col-span-4" :error="form.errors.name" placeholder="e.g. PCC M15 (1:2:4)" />
                    <FormSelect v-model="form.unit_id" label="Output unit" required :options="units" class="sm:col-span-2" :error="form.errors.unit_id" />
                    <FormInput v-model="form.description" label="Description" multiline :rows="2" class="sm:col-span-6" :error="form.errors.description" />
                    <DecimalInput v-model="form.output_quantity" label="Output quantity" required :decimals="4" class="sm:col-span-2" :suffix="unitLabel(form.unit_id)" help="Quantity of work produced by the resources below" :error="form.errors.output_quantity" />
                    <DecimalInput v-model="form.overhead_percent" label="Overhead %" :decimals="4" suffix="%" class="sm:col-span-2" :error="form.errors.overhead_percent" />
                    <DecimalInput v-model="form.profit_percent" label="Profit %" :decimals="4" suffix="%" class="sm:col-span-2" :error="form.errors.profit_percent" />
                </fieldset>
            </AppCard>

            <AppCard title="Resources" subtitle="Amount = quantity × (1 + wastage%) × rate" :padded="false">
                <template v-if="editable" #actions>
                    <AppButton size="sm" variant="secondary" icon="plus" @click="form.items.push(blankItem())">Add resource</AppButton>
                </template>
                <p v-if="form.errors.items" class="px-4 pt-3 text-xs text-red-600">{{ form.errors.items }}</p>

                <!-- Desktop -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-2 py-2 text-left">Type</th>
                                <th class="min-w-[14rem] px-2 py-2 text-left">Resource</th>
                                <th class="px-2 py-2 text-left">Unit</th>
                                <th class="px-2 py-2 text-right">Qty</th>
                                <th class="px-2 py-2 text-right">Wastage %</th>
                                <th class="px-2 py-2 text-right">Rate</th>
                                <th class="px-2 py-2 text-right">Amount</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(item, index) in form.items" :key="index" class="align-top">
                                <template v-if="editable">
                                    <td class="px-1 py-1.5">
                                        <select v-model="item.resource_type" class="rounded border-slate-200 py-1 pr-7 pl-1.5 text-xs">
                                            <option v-for="t in resourceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                                        </select>
                                    </td>
                                    <td class="space-y-1 px-1 py-1.5">
                                        <SearchSelect
                                            v-if="masterOptions(item.resource_type)"
                                            :model-value="masterValue(item)"
                                            :options="masterOptions(item.resource_type)"
                                            :placeholder="`Choose ${typeLabel(item.resource_type).toLowerCase()}…`"
                                            :error="err(index, 'material_id') || err(index, 'labour_trade_id') || err(index, 'equipment_type_id')"
                                            @update:model-value="(id) => pickMaster(item, id)"
                                        />
                                        <input v-model="item.description" maxlength="255" placeholder="Description" class="w-full rounded px-1.5 py-1 text-xs" :class="err(index, 'description') ? 'border-red-400 bg-red-50' : 'border-slate-200'" />
                                    </td>
                                    <td class="px-1 py-1.5">
                                        <select v-model="item.unit_id" class="w-20 rounded border-slate-200 py-1 pr-6 pl-1.5 text-xs">
                                            <option :value="null">—</option>
                                            <option v-for="u in units" :key="u.value" :value="u.value">{{ u.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1.5"><CellDecimal v-model="item.quantity" :error="!!err(index, 'quantity')" /></td>
                                    <td class="px-1 py-1.5"><CellDecimal v-model="item.wastage_percent" :error="!!err(index, 'wastage_percent')" /></td>
                                    <td class="px-1 py-1.5"><CellDecimal v-model="item.rate" :error="!!err(index, 'rate')" /></td>
                                </template>
                                <template v-else>
                                    <td class="px-2 py-2">{{ typeLabel(item.resource_type) }}</td>
                                    <td class="px-2 py-2 text-slate-800">{{ item.description }}</td>
                                    <td class="px-2 py-2">{{ unitLabel(item.unit_id) || '—' }}</td>
                                    <td class="px-2 py-2 text-right tabular">{{ formatQty(item.quantity) }}</td>
                                    <td class="px-2 py-2 text-right tabular">{{ formatPercent(item.wastage_percent) }}</td>
                                    <td class="px-2 py-2 text-right tabular">{{ formatRate(item.rate) }}</td>
                                </template>
                                <td class="px-2 py-2 text-right font-medium tabular">{{ formatMoney(preview.items[index]?.amount) }}</td>
                                <td class="px-1 py-1.5 text-right">
                                    <button v-if="editable" type="button" class="rounded p-1 text-slate-400 hover:text-red-600" aria-label="Remove resource" @click="form.items.splice(index, 1)">
                                        <Icon name="trash" :size="14" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile -->
                <div class="divide-y divide-line md:hidden">
                    <div v-for="(item, index) in form.items" :key="index" class="space-y-2 px-4 py-3">
                        <template v-if="editable">
                            <div class="flex items-center gap-2">
                                <select v-model="item.resource_type" class="flex-1 rounded-lg border-slate-300 py-1.5 text-sm">
                                    <option v-for="t in resourceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                                </select>
                                <button type="button" class="rounded p-1.5 text-slate-400" aria-label="Remove resource" @click="form.items.splice(index, 1)"><Icon name="trash" :size="16" /></button>
                            </div>
                            <SearchSelect
                                v-if="masterOptions(item.resource_type)"
                                :model-value="masterValue(item)"
                                :options="masterOptions(item.resource_type)"
                                :error="err(index, 'material_id') || err(index, 'labour_trade_id') || err(index, 'equipment_type_id')"
                                @update:model-value="(id) => pickMaster(item, id)"
                            />
                            <FormInput v-model="item.description" placeholder="Description" :error="err(index, 'description')" />
                            <div class="grid grid-cols-3 gap-2">
                                <DecimalInput v-model="item.quantity" label="Qty" :decimals="4" :error="err(index, 'quantity')" />
                                <DecimalInput v-model="item.wastage_percent" label="Wastage %" :decimals="4" :error="err(index, 'wastage_percent')" />
                                <DecimalInput v-model="item.rate" label="Rate" :decimals="4" :error="err(index, 'rate')" />
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-sm font-medium text-slate-900">{{ item.description }}</p>
                            <p class="text-xs text-slate-500">{{ typeLabel(item.resource_type) }} · {{ formatQty(item.quantity) }} {{ unitLabel(item.unit_id) }} × {{ formatRate(item.rate) }}<template v-if="Number(item.wastage_percent)"> + {{ formatPercent(item.wastage_percent) }} wastage</template></p>
                        </template>
                        <p class="text-right text-sm font-semibold tabular">{{ formatMoney(preview.items[index]?.amount) }}</p>
                    </div>
                </div>
            </AppCard>

            <AppCard title="Summary">
                <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between"><dt class="text-slate-500">Material</dt><dd class="tabular">{{ formatMoney(preview.material_cost) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Labour</dt><dd class="tabular">{{ formatMoney(preview.labour_cost) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Equipment</dt><dd class="tabular">{{ formatMoney(preview.equipment_cost) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Subcontract</dt><dd class="tabular">{{ formatMoney(preview.subcontract_cost) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Other</dt><dd class="tabular">{{ formatMoney(preview.other_cost) }}</dd></div>
                    <div class="flex justify-between font-medium"><dt>Direct cost</dt><dd class="tabular">{{ formatMoney(preview.direct_cost) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Overhead ({{ formatPercent(form.overhead_percent || 0) }})</dt><dd class="tabular">{{ formatMoney(preview.overhead_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Profit ({{ formatPercent(form.profit_percent || 0) }})</dt><dd class="tabular">{{ formatMoney(preview.profit_amount) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 font-semibold"><dt>Total cost</dt><dd class="tabular">{{ formatMoney(preview.total_cost) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold text-brand-700">
                        <dt>Unit rate</dt>
                        <dd class="tabular">{{ preview.unit_rate ? formatRate(preview.unit_rate) : '—' }} <span class="text-xs font-normal text-slate-500">/ {{ unitLabel(form.unit_id) || 'unit' }}</span></dd>
                    </div>
                </dl>
                <p v-if="editable" class="mt-3 text-xs text-slate-500">Preview only. Totals are recalculated exactly by the server when you save.</p>
                <template v-if="editable" #footer>
                    <div class="flex justify-end gap-2">
                        <AppButton variant="secondary" :href="route('projects.rate-analyses.index', project.id)">Cancel</AppButton>
                        <AppButton type="submit" :loading="form.processing">{{ analysis ? 'Save' : 'Create analysis' }}</AppButton>
                    </div>
                </template>
            </AppCard>
        </form>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming === 'approve' ? 'Approve this rate analysis?' : 'Delete this rate analysis?'"
            :message="confirming === 'approve' ? 'Approved analyses are locked and can be applied to BOQ lines. Save any changes first.' : 'This draft analysis will be removed.'"
            :confirm-label="confirming === 'approve' ? 'Approve' : 'Delete'"
            :danger="confirming !== 'approve'"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
    </ProjectLayout>
</template>
