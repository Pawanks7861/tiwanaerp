<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import PhotoCapture from '@/Components/SiteExecution/PhotoCapture.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    diary: { type: Object, default: null },
    options: { type: Object, required: true },
    weathers: { type: Array, required: true },
    today: { type: String, required: true },
    photoMaxKb: { type: Number, required: true },
    canSubmit: { type: Boolean, default: false },
});

const editing = computed(() => !!props.diary);
const STEPS = [
    { key: 'general', label: 'General', fields: ['diary_date', 'site_id', 'weather', 'temperature', 'work_location', 'work_performed', 'latitude', 'longitude', 'uuid', 'diary'] },
    { key: 'work', label: 'Work', fields: ['work_items'] },
    { key: 'labour', label: 'Labour', fields: ['labours'] },
    { key: 'equipment', label: 'Equipment', fields: ['equipment'] },
    { key: 'materials', label: 'Materials', fields: ['materials'] },
    { key: 'issues', label: 'Issues', fields: ['issues', 'safety_incidents', 'remarks'] },
    { key: 'photos', label: 'Photos', fields: ['photo'] },
];
const initialStep = new URLSearchParams(window.location.search).get('step');
const step = ref(STEPS.some((s) => s.key === initialStep) ? initialStep : 'general');
const stepIndex = computed(() => STEPS.findIndex((s) => s.key === step.value));

let tempId = 0;
const keyed = (rows, blank) => (rows?.length ? rows.map((r) => ({ _key: `r${++tempId}`, ...blank(), ...r })) : []);
const blankWork = () => ({ task_id: null, boq_item_id: null, subcontractor_id: null, description: '', quantity: null, unit_id: null });
const blankLabour = () => ({ labour_trade_id: null, subcontractor_id: null, headcount: null, hours: '8', remarks: '' });
const blankEquipment = () => ({ equipment_type_id: null, description: '', working_hours: null, idle_hours: null });
const blankMaterial = () => ({ material_id: null, quantity: null, unit_id: null, remarks: '' });
const newUuid = () => (crypto.randomUUID ? crypto.randomUUID() : null);

const form = useForm({
    uuid: props.diary?.uuid ?? newUuid(),
    site_id: props.diary?.site_id ?? (props.options.sites.length === 1 ? props.options.sites[0].value : null),
    diary_date: props.diary?.diary_date ?? props.today,
    weather: props.diary?.weather ?? '',
    temperature: props.diary?.temperature ?? null,
    work_location: props.diary?.work_location ?? '',
    work_performed: props.diary?.work_performed ?? '',
    issues: props.diary?.issues ?? '',
    safety_incidents: props.diary?.safety_incidents ?? '',
    remarks: props.diary?.remarks ?? '',
    latitude: props.diary?.latitude ?? null,
    longitude: props.diary?.longitude ?? null,
    captured_at: props.diary?.captured_at ?? null,
    work_items: keyed(props.diary?.work_items, blankWork),
    labours: keyed(props.diary?.labours, blankLabour),
    equipment: keyed(props.diary?.equipment, blankEquipment),
    materials: keyed(props.diary?.materials, blankMaterial),
});

const add = (list, blank) => form[list].push({ _key: `r${++tempId}`, ...blank() });
const lineError = (list, index, field) => form.errors[`${list}.${index}.${field}`];
const stepErrors = (s) => Object.keys(form.errors).filter((k) => s.fields.some((f) => k === f || k.startsWith(`${f}.`))).length;

const taskOf = (id) => props.options.tasks.find((t) => t.value === id);
const boqOf = (id) => props.options.boq_items.find((b) => b.value === id);
const unitSymbol = (id) => props.options.units.find((u) => u.value === id)?.label ?? '';
const materialOf = (id) => props.options.materials.find((m) => m.value === id);

function taskChanged(line) {
    const task = taskOf(line.task_id);
    if (task) {
        line.unit_id = task.unit_id ?? line.unit_id;
        line.boq_item_id = task.boq_item_id ?? line.boq_item_id;
    }
}
function boqChanged(line) {
    const boq = boqOf(line.boq_item_id);
    if (boq && !line.unit_id) {
        line.unit_id = boq.unit_id;
    }
}

const locating = ref(false);
const locationError = ref(null);
function captureLocation() {
    if (!navigator.geolocation) {
        locationError.value = 'Location is not available on this device.';
        return;
    }
    locating.value = true;
    locationError.value = null;
    navigator.geolocation.getCurrentPosition(
        (p) => {
            form.latitude = p.coords.latitude;
            form.longitude = p.coords.longitude;
            form.captured_at = new Date().toISOString();
            locating.value = false;
        },
        () => ((locating.value = false), (locationError.value = 'Location was not shared. You can save without it.')),
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    );
}

function save(intent = 'draft') {
    const transform = (d) => ({
        ...d,
        intent,
        temperature: d.temperature === '' ? null : d.temperature,
        work_items: d.work_items.map(({ _key, ...l }) => ({ ...l, description: l.description || null })),
        labours: d.labours.map(({ _key, ...l }) => ({ ...l, remarks: l.remarks || null })),
        equipment: d.equipment.map(({ _key, ...l }) => ({ ...l, description: l.description || null })),
        materials: d.materials.map(({ _key, ...l }) => ({ ...l, unit_id: l.unit_id ?? materialOf(l.material_id)?.unit_id ?? null, remarks: l.remarks || null })),
    });
    const options = {
        preserveScroll: true,
        onError: () => {
            const first = STEPS.find((s) => stepErrors(s) > 0);
            if (first) {
                step.value = first.key;
            }
        },
    };
    editing.value
        ? form.transform(transform).put(route('projects.site-diaries.update', [props.project.id, props.diary.id]), options)
        : form.transform(transform).post(route('projects.site-diaries.store', props.project.id), options);
}
</script>

<template>
    <ProjectLayout :project="project" active="site-diaries" :title="editing ? 'Edit site diary' : 'New site diary'">
        <form class="space-y-4 pb-24" @submit.prevent="save('draft')">
            <nav class="-mx-3 flex gap-1 overflow-x-auto px-3 pb-1 sm:mx-0 sm:px-0" aria-label="Diary sections">
                <button
                    v-for="(s, i) in STEPS"
                    :key="s.key"
                    type="button"
                    class="relative flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-sm font-medium"
                    :class="step === s.key ? 'border-brand-600 bg-brand-600 text-white' : 'border-line bg-white text-slate-600 hover:bg-slate-50'"
                    :aria-current="step === s.key ? 'step' : undefined"
                    @click="step = s.key"
                >
                    <span class="text-xs opacity-70">{{ i + 1 }}</span>{{ s.label }}
                    <span v-if="stepErrors(s)" class="ml-0.5 rounded-full bg-red-500 px-1.5 text-[10px] text-white">{{ stepErrors(s) }}</span>
                </button>
            </nav>

            <p v-if="form.errors.diary" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ form.errors.diary }}</p>

            <AppCard v-show="step === 'general'" title="General" subtitle="Saved as a draft. Several diaries per day are fine (one per site, area or engineer).">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormInput v-model="form.diary_date" type="date" label="Diary date" required :max="today" :error="form.errors.diary_date" />
                    <SearchSelect v-model="form.site_id" label="Site" :options="options.sites" :disabled="!options.sites.length" :help="options.sites.length ? null : 'No sites set up for this project'" :error="form.errors.site_id" />
                    <FormInput v-model="form.work_location" label="Work location / area" maxlength="150" placeholder="e.g. Block A, 3rd floor" class="sm:col-span-2" :error="form.errors.work_location" />
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-slate-700">Weather</span>
                        <select v-model="form.weather" class="block min-h-11 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200">
                            <option value="">—</option>
                            <option v-for="w in weathers" :key="w" :value="w">{{ w }}</option>
                        </select>
                        <span v-if="form.errors.weather" class="mt-1 block text-xs text-red-600">{{ form.errors.weather }}</span>
                    </label>
                    <FormInput v-model="form.temperature" type="number" step="0.1" min="-50" max="60" inputmode="decimal" label="Temperature (°C)" :error="form.errors.temperature" />
                    <div class="sm:col-span-2">
                        <span class="mb-1 block text-sm font-medium text-slate-700">Location (optional)</span>
                        <div class="flex flex-wrap items-center gap-2">
                            <AppButton size="sm" variant="secondary" icon="map-pin" :loading="locating" class="min-h-11" @click="captureLocation">{{ form.latitude ? 'Update location' : 'Capture location' }}</AppButton>
                            <span v-if="form.latitude" class="text-xs text-slate-600 tabular">{{ Number(form.latitude).toFixed(5) }}, {{ Number(form.longitude).toFixed(5) }}</span>
                            <span v-if="locationError" class="text-xs text-amber-700">{{ locationError }}</span>
                        </div>
                    </div>
                    <FormInput v-model="form.work_performed" label="Work performed" multiline :rows="4" maxlength="5000" class="sm:col-span-2 lg:col-span-4" placeholder="Summary of today's work" :error="form.errors.work_performed" />
                </div>
            </AppCard>

            <AppCard v-show="step === 'work'" title="Work done" subtitle="Quantities executed today. Link each line to its task and/or BOQ item so the DPR can post progress." :padded="false">
                <p v-if="form.errors.work_items" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.work_items }}</p>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.work_items" :key="line._key" class="p-4">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-500 uppercase">Line {{ index + 1 }}</span>
                            <button type="button" class="flex size-11 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove work line ${index + 1}`" @click="form.work_items.splice(index, 1)">
                                <Icon name="trash" :size="16" />
                            </button>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-6 lg:grid-cols-12">
                            <SearchSelect v-model="line.task_id" label="Task" :options="options.tasks" class="sm:col-span-3 lg:col-span-4" :error="lineError('work_items', index, 'task_id')" @update:model-value="taskChanged(line)" />
                            <SearchSelect v-model="line.boq_item_id" label="BOQ item" :options="options.boq_items" :disabled="!options.boq_items.length" class="sm:col-span-3 lg:col-span-4" :error="lineError('work_items', index, 'boq_item_id')" @update:model-value="boqChanged(line)" />
                            <SearchSelect v-model="line.subcontractor_id" label="Subcontractor" :options="options.subcontractors" class="sm:col-span-6 lg:col-span-4" :error="lineError('work_items', index, 'subcontractor_id')" />
                            <FormInput v-model="line.description" label="Description" maxlength="500" class="sm:col-span-6 lg:col-span-6" :error="lineError('work_items', index, 'description')" />
                            <DecimalInput v-model="line.quantity" label="Quantity" required :decimals="4" :suffix="unitSymbol(line.unit_id)" class="sm:col-span-3 lg:col-span-3" :error="lineError('work_items', index, 'quantity')" />
                            <SearchSelect v-model="line.unit_id" label="Unit" :options="options.units" :clearable="false" class="sm:col-span-3 lg:col-span-3" :error="lineError('work_items', index, 'unit_id')" />
                        </div>
                        <div v-if="taskOf(line.task_id) || boqOf(line.boq_item_id)" class="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                            <div v-if="taskOf(line.task_id)" class="rounded-md bg-slate-50 px-3 py-2 text-slate-600">
                                <span class="font-mono font-medium text-slate-800">{{ taskOf(line.task_id).wbs_code }}</span> {{ taskOf(line.task_id).name }}
                                <template v-if="taskOf(line.task_id).planned_qty !== null">
                                    · planned {{ formatQty(taskOf(line.task_id).planned_qty) }} · done {{ formatQty(taskOf(line.task_id).completed_qty ?? 0) }} {{ taskOf(line.task_id).unit }}
                                </template>
                                <template v-if="taskOf(line.task_id).boq_item"> · BOQ {{ taskOf(line.task_id).boq_item }}</template>
                            </div>
                            <div v-if="boqOf(line.boq_item_id)" class="rounded-md bg-slate-50 px-3 py-2 text-slate-600">
                                BOQ <span class="font-medium text-slate-800">{{ boqOf(line.boq_item_id).label }}</span> · qty {{ formatQty(boqOf(line.boq_item_id).quantity) }} {{ boqOf(line.boq_item_id).unit }}
                            </div>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="secondary" icon="plus" class="min-h-11" @click="add('work_items', blankWork)">Add work line</AppButton>
                </div>
            </AppCard>

            <AppCard v-show="step === 'labour'" title="Labour on site" subtitle="Headcount for information only; no attendance or wages are recorded here." :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.labours" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-6 lg:grid-cols-12">
                        <SearchSelect v-model="line.labour_trade_id" label="Trade" required :options="options.trades" class="sm:col-span-3 lg:col-span-3" :error="lineError('labours', index, 'labour_trade_id')" />
                        <SearchSelect v-model="line.subcontractor_id" label="Subcontractor" :options="options.subcontractors" class="sm:col-span-3 lg:col-span-3" :error="lineError('labours', index, 'subcontractor_id')" />
                        <FormInput v-model="line.headcount" type="number" inputmode="numeric" min="1" label="Headcount" required class="sm:col-span-2 lg:col-span-2" :error="lineError('labours', index, 'headcount')" />
                        <DecimalInput v-model="line.hours" label="Hours" :decimals="2" class="sm:col-span-2 lg:col-span-2" :error="lineError('labours', index, 'hours')" />
                        <div class="flex items-end sm:col-span-2 lg:col-span-2">
                            <button type="button" class="flex size-11 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove labour line ${index + 1}`" @click="form.labours.splice(index, 1)">
                                <Icon name="trash" :size="16" />
                            </button>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="secondary" icon="plus" class="min-h-11" @click="add('labours', blankLabour)">Add labour</AppButton>
                </div>
            </AppCard>

            <AppCard v-show="step === 'equipment'" title="Equipment" subtitle="Working and idle hours by equipment type. The equipment register and costing come later." :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.equipment" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-6 lg:grid-cols-12">
                        <SearchSelect v-model="line.equipment_type_id" label="Equipment type" :options="options.equipment_types" class="sm:col-span-3 lg:col-span-3" :error="lineError('equipment', index, 'equipment_type_id')" />
                        <FormInput v-model="line.description" label="Description" maxlength="150" placeholder="e.g. JCB 3DX, hired" class="sm:col-span-3 lg:col-span-3" :error="lineError('equipment', index, 'description')" />
                        <DecimalInput v-model="line.working_hours" label="Working hours" :decimals="2" class="sm:col-span-2 lg:col-span-2" :error="lineError('equipment', index, 'working_hours')" />
                        <DecimalInput v-model="line.idle_hours" label="Idle hours" :decimals="2" class="sm:col-span-2 lg:col-span-2" :error="lineError('equipment', index, 'idle_hours')" />
                        <div class="flex items-end sm:col-span-2 lg:col-span-2">
                            <button type="button" class="flex size-11 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove equipment line ${index + 1}`" @click="form.equipment.splice(index, 1)">
                                <Icon name="trash" :size="16" />
                            </button>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="secondary" icon="plus" class="min-h-11" @click="add('equipment', blankEquipment)">Add equipment</AppButton>
                </div>
            </AppCard>

            <AppCard v-show="step === 'materials'" title="Material used" subtitle="What was consumed at site, for the record. Stock already left the store with the material issue; nothing is posted again." :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.materials" :key="line._key" class="grid gap-3 p-4 sm:grid-cols-6 lg:grid-cols-12">
                        <SearchSelect v-model="line.material_id" label="Item" required :options="options.materials" class="sm:col-span-3 lg:col-span-5" :error="lineError('materials', index, 'material_id') || lineError('materials', index, 'unit_id')" />
                        <DecimalInput v-model="line.quantity" label="Quantity" required :decimals="4" :suffix="materialOf(line.material_id)?.unit ?? ''" class="sm:col-span-3 lg:col-span-2" :error="lineError('materials', index, 'quantity')" />
                        <FormInput v-model="line.remarks" label="Remarks" maxlength="255" class="sm:col-span-4 lg:col-span-3" :error="lineError('materials', index, 'remarks')" />
                        <div class="flex items-end sm:col-span-2 lg:col-span-2">
                            <button type="button" class="flex size-11 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove material line ${index + 1}`" @click="form.materials.splice(index, 1)">
                                <Icon name="trash" :size="16" />
                            </button>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="secondary" icon="plus" class="min-h-11" @click="add('materials', blankMaterial)">Add material</AppButton>
                </div>
            </AppCard>

            <AppCard v-show="step === 'issues'" title="Issues and remarks">
                <div class="grid gap-4 lg:grid-cols-2">
                    <FormInput v-model="form.issues" label="Site issues / hindrances" multiline :rows="4" maxlength="5000" :error="form.errors.issues" />
                    <FormInput v-model="form.safety_incidents" label="Safety incidents" multiline :rows="4" maxlength="5000" :error="form.errors.safety_incidents" />
                    <FormInput v-model="form.remarks" label="Remarks" multiline :rows="3" maxlength="5000" class="lg:col-span-2" :error="form.errors.remarks" />
                </div>
            </AppCard>

            <AppCard v-show="step === 'photos'" title="Photos">
                <PhotoCapture
                    v-if="editing"
                    :photos="diary.photos"
                    :upload-url="route('projects.site-diaries.photos.store', [project.id, diary.id])"
                    :delete-route="(photo) => route('projects.site-diaries.photos.destroy', [project.id, diary.id, photo.id])"
                    :can-edit="true"
                    :max-kb="photoMaxKb"
                />
                <div v-else class="flex flex-col items-start gap-3 text-sm text-slate-600">
                    <p>Save the draft first, then add photos from the camera or gallery.</p>
                    <AppButton icon="camera" class="min-h-11" :loading="form.processing" @click="save('photos')">Save draft &amp; add photos</AppButton>
                </div>
            </AppCard>

            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-white/95 p-3 backdrop-blur md:left-auto md:right-0 md:w-auto md:rounded-tl-xl md:border-l">
                <div class="mx-auto flex max-w-7xl items-center gap-2">
                    <button type="button" class="hidden min-h-11 rounded-lg border border-line px-3 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40 sm:block" :disabled="stepIndex === 0" @click="step = STEPS[stepIndex - 1].key">Back</button>
                    <button type="button" class="hidden min-h-11 rounded-lg border border-line px-3 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40 sm:block" :disabled="stepIndex === STEPS.length - 1" @click="step = STEPS[stepIndex + 1].key">Next</button>
                    <Link
                        :href="editing ? route('projects.site-diaries.show', [project.id, diary.id]) : route('projects.site-diaries.index', project.id)"
                        class="inline-flex min-h-11 items-center justify-center rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100"
                    >Cancel</Link>
                    <AppButton type="submit" variant="secondary" :loading="form.processing" class="min-h-11 flex-1 md:flex-none">Save draft</AppButton>
                    <AppButton v-if="canSubmit" :loading="form.processing" class="min-h-11 flex-1 md:flex-none" @click="save('submit')">Submit</AppButton>
                </div>
            </div>
        </form>
    </ProjectLayout>
</template>
