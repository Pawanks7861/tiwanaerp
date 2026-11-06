<script setup>
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import Icon from '@/Components/UI/Icon.vue';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    module: { type: String, required: true },
    projectId: { type: Number, default: null },
    context: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'imported']);

const step = ref('upload');
const busy = ref(false);
const progress = ref('');
const error = ref('');
const meta = ref(null);
const file = ref(null);
const headers = ref([]);
const rawHeaders = ref([]);
const mapping = ref({});
const mode = ref('create');
const importJob = ref(null);
const preview = ref([]);
const summary = ref({});
const sheets = ref([]);
const selectedSheetIndex = ref(0);
const suggestedSheetIndex = ref(null);
const headerRow = ref(1);
const suggestedHeaderRow = ref(1);
const priorImport = ref(null);
const reimportAction = ref('');

const title = computed(() => {
    if (props.module === 'boq.items') {
        return 'Import Excel / CSV';
    }
    return meta.value?.label ? `Import ${meta.value.label}` : 'Import';
});

const isBoq = computed(() => props.module === 'boq.items');

const primaryFields = computed(() => {
    const fields = meta.value?.fields ?? [];
    if (!isBoq.value) {
        return fields;
    }
    const preferred = [
        'section_name', 'subsection_name', 'item_code', 'name', 'description',
        'hsn_sac', 'unit', 'quantity', 'client_rate', 'gst_rate', 'amount', 'remarks',
    ];
    const byKey = Object.fromEntries(fields.map((f) => [f.key, f]));
    return preferred.map((key) => byKey[key]).filter(Boolean);
});

watch(
    () => props.show,
    async (open) => {
        if (!open) {
            return;
        }
        reset();
        await loadMeta();
    },
);

function reset() {
    step.value = 'upload';
    busy.value = false;
    progress.value = '';
    error.value = '';
    file.value = null;
    headers.value = [];
    rawHeaders.value = [];
    mapping.value = {};
    mode.value = 'create';
    importJob.value = null;
    preview.value = [];
    summary.value = {};
    sheets.value = [];
    selectedSheetIndex.value = 0;
    suggestedSheetIndex.value = null;
    headerRow.value = 1;
    suggestedHeaderRow.value = 1;
    priorImport.value = null;
    reimportAction.value = '';
}

async function loadMeta() {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(route('imports.meta', props.module), {
            params: props.projectId ? { project_id: props.projectId } : {},
        });
        meta.value = data;
        mode.value = data.modes?.[0]?.value ?? 'create';
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Could not load import settings.';
    } finally {
        busy.value = false;
    }
}

function onFileChange(event) {
    file.value = event.target.files?.[0] ?? null;
    error.value = '';
}

function onDrop(event) {
    event.preventDefault();
    file.value = event.dataTransfer?.files?.[0] ?? null;
}

async function upload() {
    if (!file.value) {
        error.value = 'Choose an Excel or CSV file.';
        return;
    }
    busy.value = true;
    progress.value = 'Reading file…';
    error.value = '';
    try {
        const body = new FormData();
        body.append('module', props.module);
        body.append('file', file.value);
        if (props.projectId) {
            body.append('project_id', String(props.projectId));
        }
        Object.entries(props.context || {}).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                body.append(`context[${key}]`, String(value));
            }
        });
        const { data } = await window.axios.post(route('imports.store'), body);
        applyUploadResult(data);
        step.value = sheets.value.length > 1 ? 'sheet' : 'map';
    } catch (e) {
        error.value = firstError(e) ?? 'Upload failed.';
    } finally {
        busy.value = false;
        progress.value = '';
    }
}

function applyUploadResult(data) {
    importJob.value = data.import;
    headers.value = data.headers;
    rawHeaders.value = data.raw_headers ?? data.headers ?? [];
    mapping.value = data.mapping;
    sheets.value = data.sheets ?? [];
    selectedSheetIndex.value = Number(data.import?.context?.sheet_index ?? data.suggested_sheet_index ?? 0);
    suggestedSheetIndex.value = data.suggested_sheet_index ?? null;
    headerRow.value = Number(data.header_row ?? data.suggested_header_row ?? 1);
    suggestedHeaderRow.value = Number(data.suggested_header_row ?? headerRow.value);
    priorImport.value = data.prior_import ?? null;
    mode.value = data.modes?.[0]?.value ?? 'create';
    meta.value = {
        ...(meta.value ?? {}),
        fields: data.fields,
        modes: data.modes,
        instructions: data.instructions,
    };
}

async function chooseSheet() {
    busy.value = true;
    progress.value = 'Reading file…';
    error.value = '';
    try {
        const { data } = await window.axios.post(route('imports.sheet', importJob.value.id), {
            sheet_index: selectedSheetIndex.value,
            header_row: headerRow.value,
        });
        importJob.value = data.import;
        headers.value = data.headers;
        rawHeaders.value = data.raw_headers ?? data.headers ?? [];
        mapping.value = data.mapping;
        sheets.value = data.sheets ?? sheets.value;
        headerRow.value = Number(data.header_row ?? headerRow.value);
        suggestedHeaderRow.value = Number(data.suggested_header_row ?? suggestedHeaderRow.value);
        step.value = 'map';
    } catch (e) {
        error.value = firstError(e) ?? 'Could not read that worksheet.';
    } finally {
        busy.value = false;
        progress.value = '';
    }
}

async function validate() {
    busy.value = true;
    progress.value = 'Validating rows…';
    error.value = '';
    try {
        if (isBoq.value && sheets.value.length >= 1) {
            await window.axios.post(route('imports.sheet', importJob.value.id), {
                sheet_index: selectedSheetIndex.value,
                header_row: headerRow.value,
            });
        }
        const { data } = await window.axios.post(route('imports.validate', importJob.value.id), {
            mode: mode.value,
            mapping: mapping.value,
        });
        importJob.value = data.import;
        preview.value = data.preview ?? [];
        summary.value = data.summary ?? {};
        step.value = 'preview';
    } catch (e) {
        error.value = firstError(e) ?? 'Validation failed.';
    } finally {
        busy.value = false;
        progress.value = '';
    }
}

async function confirm() {
    busy.value = true;
    progress.value = 'Importing…';
    error.value = '';
    try {
        const payload = {};
        if (priorImport.value && reimportAction.value) {
            payload.reimport_action = reimportAction.value;
        }
        const { data } = await window.axios.post(route('imports.confirm', importJob.value.id), payload);
        importJob.value = data.import;
        step.value = 'done';
        emit('imported', data.import);
    } catch (e) {
        error.value = firstError(e) ?? 'Import failed. Nothing was saved.';
        if (e.response?.data?.import) {
            importJob.value = e.response.data.import;
        }
        if (String(error.value).includes('already been imported')) {
            priorImport.value = priorImport.value ?? { message: error.value };
        }
    } finally {
        busy.value = false;
        progress.value = '';
    }
}

function firstError(e) {
    const errors = e.response?.data?.errors;
    if (errors) {
        return Object.values(errors).flat()[0];
    }
    return e.response?.data?.message;
}

function close() {
    emit('close');
}

function statusClass(status) {
    if (status === 'valid') return 'bg-emerald-50 text-emerald-800';
    if (status === 'warning' || status === 'duplicate' || status === 'skipped') return 'bg-amber-50 text-amber-900';
    if (status === 'invalid') return 'bg-red-50 text-red-800';
    return 'bg-slate-50 text-slate-700';
}

const headerOptions = computed(() => [
    { value: '', label: 'Ignore column' },
    ...headers.value.map((h, index) => ({
        value: h,
        label: (rawHeaders.value[index] || h).toString().replaceAll('_', ' '),
    })),
]);

const headerRowOptions = computed(() =>
    Array.from({ length: 30 }, (_, i) => ({
        value: i + 1,
        label: `Row ${i + 1}${suggestedHeaderRow.value === i + 1 ? ' (suggested)' : ''}`,
    })),
);

const sheetOptions = computed(() =>
    sheets.value.map((name, index) => ({
        value: index,
        label: `${name || `Sheet ${index + 1}`}${suggestedSheetIndex.value === index ? ' (suggested)' : ''}`,
    })),
);
</script>

<template>
    <AppModal :show="show" :title="title" max-width="4xl" @close="close">
        <div class="space-y-4">
            <p v-if="meta?.instructions && step === 'upload'" class="text-sm text-slate-600">{{ meta.instructions }}</p>
            <p v-if="progress" class="text-sm font-medium text-brand-700">{{ progress }}</p>
            <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</p>

            <div v-if="step === 'upload'" class="space-y-4">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <a
                        class="inline-flex items-center gap-2 font-medium text-brand-700 hover:underline"
                        :href="meta?.template_url || route('imports.template', { module, format: 'xlsx', ...(projectId ? { project_id: projectId } : {}) })"
                    >
                        <Icon name="document" :size="16" /> Download template
                    </a>
                    <a
                        class="inline-flex items-center gap-2 font-medium text-slate-600 hover:underline"
                        :href="meta?.template_csv_url || route('imports.template', { module, format: 'csv', ...(projectId ? { project_id: projectId } : {}) })"
                    >
                        CSV template
                    </a>
                </div>
                <label
                    class="flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center hover:border-brand-400 hover:bg-brand-50/40"
                    @dragover.prevent
                    @drop="onDrop"
                >
                    <Icon name="folder" :size="28" class="text-slate-400" />
                    <span class="mt-2 text-sm font-medium text-slate-800">Drop an .xlsx, .xls or .csv file here</span>
                    <span class="mt-1 text-xs text-slate-500">or click to browse</span>
                    <input type="file" class="sr-only" accept=".xlsx,.xls,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="onFileChange" />
                </label>
                <p v-if="file" class="text-sm text-slate-700">
                    Selected: <span class="font-medium">{{ file.name }}</span>
                    <span class="text-slate-500">({{ Math.round(file.size / 1024) }} KB)</span>
                </p>
            </div>

            <div v-else-if="step === 'sheet'" class="space-y-4">
                <p class="text-sm text-slate-600">Choose the worksheet that contains the BOQ rows. Suggested sheets are marked.</p>
                <FormSelect v-model="selectedSheetIndex" label="Worksheet" :options="sheetOptions" />
                <FormSelect v-model="headerRow" label="Header row" :options="headerRowOptions" />
                <p class="text-xs text-slate-500">Header detection looks at the first 30 rows. Change the header row if titles start later.</p>
            </div>

            <div v-else-if="step === 'map'" class="space-y-4">
                <div v-if="isBoq" class="grid gap-3 sm:grid-cols-2">
                    <FormSelect v-if="sheets.length" v-model="selectedSheetIndex" label="Worksheet" :options="sheetOptions" />
                    <FormSelect v-model="headerRow" label="Header row" :options="headerRowOptions" />
                </div>
                <FormSelect v-if="(meta?.modes?.length ?? 0) > 1" v-model="mode" label="Import mode" :options="meta?.modes ?? []" />
                <p class="text-sm text-slate-600">Map Excel columns to ERP fields. Unused columns can stay as Ignore column.</p>
                <div class="max-h-80 space-y-3 overflow-y-auto rounded-lg border border-line p-3">
                    <div v-for="field in primaryFields" :key="field.key" class="grid gap-2 sm:grid-cols-2 sm:items-center">
                        <div>
                            <p class="text-sm font-medium text-slate-800">
                                {{ field.label }}
                                <span v-if="field.required" class="text-red-600">*</span>
                            </p>
                            <p v-if="field.help" class="text-xs text-slate-500">{{ field.help }}</p>
                        </div>
                        <select v-model="mapping[field.key]" class="rounded-lg border-slate-300 text-sm shadow-sm">
                            <option v-for="opt in headerOptions" :key="`${field.key}-${opt.value}`" :value="opt.value || null">{{ opt.label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div v-else-if="step === 'preview'" class="space-y-4">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    <div class="rounded-lg bg-slate-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold tabular">{{ summary.total ?? 0 }}</p>
                        <p class="text-xs text-slate-500">Total</p>
                    </div>
                    <div class="rounded-lg bg-emerald-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold text-emerald-800 tabular">{{ summary.valid ?? 0 }}</p>
                        <p class="text-xs text-emerald-700">Valid</p>
                    </div>
                    <div class="rounded-lg bg-amber-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold text-amber-800 tabular">{{ summary.warnings ?? summary.skip ?? 0 }}</p>
                        <p class="text-xs text-amber-700">Warnings / skipped</p>
                    </div>
                    <div class="rounded-lg bg-red-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold text-red-800 tabular">{{ summary.invalid ?? 0 }}</p>
                        <p class="text-xs text-red-700">Failed</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold tabular">{{ Number(summary.boq_value ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }}</p>
                        <p class="text-xs text-slate-500">BOQ value</p>
                    </div>
                </div>

                <div v-if="priorImport" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-950">
                    <p class="font-medium">{{ priorImport.message || 'This file has already been imported into this BOQ.' }}</p>
                    <div class="flex flex-wrap gap-2">
                        <label class="inline-flex items-center gap-2">
                            <input v-model="reimportAction" type="radio" value="additional" class="border-slate-300" />
                            Import as additional rows
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="reimportAction" type="radio" value="replace" class="border-slate-300" />
                            Replace previous draft import
                        </label>
                    </div>
                </div>

                <div class="max-h-72 overflow-auto rounded-lg border border-line">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-3 py-2">Source row</th>
                                <th class="px-3 py-2">Head</th>
                                <th class="px-3 py-2">Code</th>
                                <th class="px-3 py-2">Description</th>
                                <th class="px-3 py-2">Unit</th>
                                <th class="px-3 py-2 text-right">Qty</th>
                                <th class="px-3 py-2 text-right">Rate</th>
                                <th class="px-3 py-2 text-right">GST</th>
                                <th class="px-3 py-2 text-right">Amount</th>
                                <th class="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview" :key="row.row" class="border-t border-line">
                                <td class="px-3 py-2 tabular">{{ row.row }}</td>
                                <td class="px-3 py-2">{{ row.preview?.head ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono">{{ row.preview?.code ?? '—' }}</td>
                                <td class="px-3 py-2">{{ row.preview?.name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ row.preview?.unit ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ row.preview?.quantity ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ row.preview?.rate ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ row.preview?.gst ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular">{{ row.preview?.amount ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded px-1.5 py-0.5 font-medium" :class="statusClass(row.status)">
                                        {{ row.preview?.status_label || row.status }}
                                    </span>
                                    <template v-if="row.errors?.length">
                                        <span v-for="(err, i) in row.errors" :key="i" class="mt-1 block text-red-700">{{ err.message }}</span>
                                    </template>
                                    <template v-else-if="row.preview?.warnings?.length">
                                        <span v-for="(warn, i) in row.preview.warnings" :key="i" class="mt-1 block text-amber-800">{{ warn }}</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <a
                        v-if="importJob?.has_error_report"
                        class="font-medium text-brand-700 hover:underline"
                        :href="route('imports.errors', importJob.id)"
                    >
                        Download error report
                    </a>
                    <p class="text-xs text-slate-500">Showing the first {{ preview.length }} rows. Nothing is saved until you confirm.</p>
                </div>
            </div>

            <div v-else-if="step === 'done'" class="space-y-2 text-sm text-slate-700">
                <p class="font-medium text-emerald-800">Import finished.</p>
                <p>
                    Imported: {{ importJob?.create_count ?? 0 }} ·
                    Skipped: {{ importJob?.skip_count ?? 0 }} ·
                    Failed: {{ importJob?.fail_count ?? 0 }}
                </p>
            </div>
        </div>

        <template #footer>
            <AppButton variant="secondary" :disabled="busy" @click="close">{{ step === 'done' ? 'Close' : 'Cancel' }}</AppButton>
            <AppButton v-if="step === 'upload'" :disabled="busy || !file" @click="upload">{{ busy ? 'Reading file…' : 'Continue' }}</AppButton>
            <AppButton v-else-if="step === 'sheet'" :disabled="busy" @click="chooseSheet">{{ busy ? 'Reading file…' : 'Continue' }}</AppButton>
            <AppButton v-else-if="step === 'map'" :disabled="busy" @click="validate">{{ busy ? 'Validating rows…' : 'Preview' }}</AppButton>
            <AppButton
                v-else-if="step === 'preview'"
                :disabled="busy || !(summary.valid > 0) || (priorImport && !reimportAction)"
                @click="confirm"
            >
                {{ busy ? 'Importing…' : `Confirm import (${summary.valid ?? 0})` }}
            </AppButton>
        </template>
    </AppModal>
</template>
