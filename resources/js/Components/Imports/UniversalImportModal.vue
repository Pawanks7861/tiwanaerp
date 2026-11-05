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
const error = ref('');
const meta = ref(null);
const file = ref(null);
const headers = ref([]);
const mapping = ref({});
const mode = ref('create');
const importJob = ref(null);
const preview = ref([]);
const summary = ref({});
const sheets = ref([]);
const selectedSheetIndex = ref(0);

const title = computed(() => meta.value?.label ? `Import ${meta.value.label}` : 'Import');

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
    error.value = '';
    file.value = null;
    headers.value = [];
    mapping.value = {};
    mode.value = 'create';
    importJob.value = null;
    preview.value = [];
    summary.value = {};
    sheets.value = [];
    selectedSheetIndex.value = 0;
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
        importJob.value = data.import;
        headers.value = data.headers;
        mapping.value = data.mapping;
        sheets.value = data.sheets ?? [];
        selectedSheetIndex.value = Number(data.import?.context?.sheet_index ?? 0);
        mode.value = data.modes?.[0]?.value ?? 'create';
        meta.value = {
            ...(meta.value ?? {}),
            fields: data.fields,
            modes: data.modes,
            instructions: data.instructions,
        };
        step.value = sheets.value.length > 1 ? 'sheet' : 'map';
    } catch (e) {
        error.value = firstError(e) ?? 'Upload failed.';
    } finally {
        busy.value = false;
    }
}

async function chooseSheet() {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(route('imports.sheet', importJob.value.id), {
            sheet_index: selectedSheetIndex.value,
        });
        importJob.value = data.import;
        headers.value = data.headers;
        mapping.value = data.mapping;
        sheets.value = data.sheets ?? sheets.value;
        step.value = 'map';
    } catch (e) {
        error.value = firstError(e) ?? 'Could not read that worksheet.';
    } finally {
        busy.value = false;
    }
}

async function validate() {
    busy.value = true;
    error.value = '';
    try {
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
    }
}

async function confirm() {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(route('imports.confirm', importJob.value.id));
        importJob.value = data.import;
        step.value = 'done';
        emit('imported', data.import);
    } catch (e) {
        error.value = firstError(e) ?? 'Import failed. Nothing was saved.';
        if (e.response?.data?.import) {
            importJob.value = e.response.data.import;
        }
    } finally {
        busy.value = false;
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

const headerOptions = computed(() => [
    { value: '', label: '— Not mapped —' },
    ...headers.value.map((h) => ({ value: h, label: h.replaceAll('_', ' ') })),
]);
</script>

<template>
    <AppModal :show="show" :title="title" max-width="3xl" @close="close">
        <div class="space-y-4">
            <p v-if="meta?.instructions" class="text-sm text-slate-600">{{ meta.instructions }}</p>
            <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</p>

            <div v-if="step === 'upload'" class="space-y-4">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <a
                        class="inline-flex items-center gap-2 font-medium text-brand-700 hover:underline"
                        :href="meta?.template_url || route('imports.template', { module, format: 'xlsx', ...(projectId ? { project_id: projectId } : {}) })"
                    >
                        <Icon name="document" :size="16" /> Download Excel template
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
                <p class="text-sm text-slate-600">This workbook has more than one sheet. Choose the tab that contains your data rows.</p>
                <FormSelect
                    v-model="selectedSheetIndex"
                    label="Worksheet"
                    :options="sheets.map((name, index) => ({ value: index, label: name || `Sheet ${index + 1}` }))"
                />
            </div>

            <div v-else-if="step === 'map'" class="space-y-4">
                <FormSelect v-model="mode" label="Import mode" :options="meta?.modes ?? []" />
                <div class="max-h-80 space-y-3 overflow-y-auto rounded-lg border border-line p-3">
                    <div v-for="field in meta?.fields ?? []" :key="field.key" class="grid gap-2 sm:grid-cols-2 sm:items-center">
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
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="rounded-lg bg-slate-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold tabular">{{ summary.total ?? 0 }}</p>
                        <p class="text-xs text-slate-500">Total</p>
                    </div>
                    <div class="rounded-lg bg-emerald-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold text-emerald-800 tabular">{{ summary.valid ?? 0 }}</p>
                        <p class="text-xs text-emerald-700">Valid</p>
                    </div>
                    <div class="rounded-lg bg-red-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold text-red-800 tabular">{{ summary.invalid ?? 0 }}</p>
                        <p class="text-xs text-red-700">Invalid</p>
                    </div>
                    <div class="rounded-lg bg-amber-50 px-3 py-2 text-center">
                        <p class="text-lg font-semibold text-amber-800 tabular">{{ summary.duplicate ?? 0 }}</p>
                        <p class="text-xs text-amber-700">Updates</p>
                    </div>
                </div>
                <a
                    v-if="importJob?.has_error_report"
                    class="inline-flex text-sm font-medium text-brand-700 hover:underline"
                    :href="route('imports.errors', importJob.id)"
                >
                    Download error report
                </a>
                <div class="max-h-64 overflow-auto rounded-lg border border-line">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-3 py-2">Row</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Action</th>
                                <th class="px-3 py-2">Preview / errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview" :key="row.row" class="border-t border-line">
                                <td class="px-3 py-2 tabular">{{ row.row }}</td>
                                <td class="px-3 py-2">{{ row.status }}</td>
                                <td class="px-3 py-2">{{ row.action ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <template v-if="row.errors?.length">
                                        <span v-for="(err, i) in row.errors" :key="i" class="block text-red-700">{{ err.field }}: {{ err.message }}</span>
                                    </template>
                                    <template v-else>
                                        {{ row.preview?.code ? `${row.preview.code} · ` : '' }}{{ row.preview?.name ?? '' }}
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-500">Showing the first {{ preview.length }} rows. Nothing is saved until you confirm.</p>
            </div>

            <div v-else-if="step === 'done'" class="space-y-2 text-sm text-slate-700">
                <p class="font-medium text-emerald-800">Import finished.</p>
                <p>
                    Created {{ importJob?.create_count ?? 0 }},
                    updated {{ importJob?.update_count ?? 0 }},
                    skipped {{ importJob?.skip_count ?? 0 }}.
                </p>
            </div>
        </div>

        <template #footer>
            <AppButton variant="secondary" :disabled="busy" @click="close">{{ step === 'done' ? 'Close' : 'Cancel' }}</AppButton>
            <AppButton v-if="step === 'upload'" :disabled="busy || !file" @click="upload">{{ busy ? 'Uploading…' : 'Continue' }}</AppButton>
            <AppButton v-else-if="step === 'sheet'" :disabled="busy" @click="chooseSheet">{{ busy ? 'Loading…' : 'Continue' }}</AppButton>
            <AppButton v-else-if="step === 'map'" :disabled="busy" @click="validate">{{ busy ? 'Validating…' : 'Validate' }}</AppButton>
            <AppButton v-else-if="step === 'preview'" :disabled="busy || !(summary.valid > 0)" @click="confirm">
                {{ busy ? 'Importing…' : `Confirm import (${summary.valid ?? 0})` }}
            </AppButton>
        </template>
    </AppModal>
</template>
