<script setup>
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, default: null },
    importBatch: { type: Object, default: null },
    detection: { type: Object, default: null },
    modes: { type: Array, default: () => [] },
});

const step = computed(() => {
    if (!props.importBatch) {
        return 1;
    }
    if (props.importBatch.status === 'completed') {
        return 7;
    }
    if (props.importBatch.summary?.preview) {
        return 5;
    }
    if (props.detection || props.importBatch.summary?.detection) {
        return 2;
    }

    return 2;
});

const uploadForm = useForm({
    file: null,
    mode: props.boq ? (props.boq.is_editable ? 'existing' : 'revision') : 'new',
    boq_id: props.boq?.id ?? null,
});

const optionsForm = useForm({
    import_schedule: false,
    replace_previous: false,
});

const fileInput = ref(null);
const detection = computed(() => props.detection || props.importBatch?.summary?.detection || null);
const preview = computed(() => props.importBatch?.summary?.preview || null);
const warnings = computed(() => props.importBatch?.summary?.warnings || detection.value?.warnings || []);
const errors = computed(() => props.importBatch?.summary?.errors || []);
const previewItems = computed(() => props.importBatch?.summary?.preview_items || []);
const result = computed(() => props.importBatch?.summary?.result || null);

function onFileChange(event) {
    uploadForm.file = event.target.files?.[0] ?? null;
}

function submitUpload() {
    uploadForm.post(route('projects.boqs.workbook-import.upload', props.project.id), {
        forceFormData: true,
    });
}

function runPreview() {
    optionsForm.post(route('projects.boqs.workbook-import.preview', [props.project.id, props.importBatch.id]), {
        preserveScroll: true,
    });
}

function confirmImport() {
    optionsForm.post(route('projects.boqs.workbook-import.confirm', [props.project.id, props.importBatch.id]));
}

const steps = [
    'Upload',
    'Detect Sheets',
    'Sheet Mapping',
    'Preview',
    'Validation',
    'Import',
    'Reconciliation',
];
</script>

<template>
    <ProjectLayout :project="project" title="Import Costing Workbook">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold text-slate-900">Import Costing Workbook</h1>
                    <p class="text-sm text-slate-600">
                        Multi-sheet Advanced BOQ import — ERP recalculates measurements, BBS, and costing.
                    </p>
                </div>
                <Link
                    :href="boq ? route('projects.boqs.show', [project.id, boq.id]) : route('projects.boqs.index', project.id)"
                    class="text-sm font-medium text-brand-700 hover:underline"
                >
                    Back to BOQ
                </Link>
            </div>

            <div class="flex flex-wrap gap-2">
                <span
                    v-for="(label, index) in steps"
                    :key="label"
                    class="rounded-full px-2.5 py-1 text-[11px] font-medium"
                    :class="step >= index + 1 ? 'bg-brand-50 text-brand-800' : 'bg-slate-100 text-slate-500'"
                >
                    {{ index + 1 }}. {{ label }}
                </span>
            </div>

            <AppCard v-if="!importBatch" title="Step 1 — Upload" subtitle="Accepts .xlsx Advanced Costing workbooks only.">
                <form class="space-y-4" @submit.prevent="submitUpload">
                    <FormSelect
                        v-model="uploadForm.mode"
                        label="Import mode"
                        :options="modes"
                        :error="uploadForm.errors.mode"
                    />
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Workbook (.xlsx)</label>
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            class="block w-full text-sm"
                            @change="onFileChange"
                        >
                        <p v-if="uploadForm.errors.file" class="mt-1 text-xs text-red-600">{{ uploadForm.errors.file }}</p>
                    </div>
                    <AppButton type="submit" :loading="uploadForm.processing" :disabled="!uploadForm.file">
                        Upload & detect sheets
                    </AppButton>
                </form>
            </AppCard>

            <template v-else>
                <AppCard title="Workbook" :subtitle="importBatch.original_filename">
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <StatusBadge :status="importBatch.status" />
                        <span class="text-slate-600">Batch #{{ importBatch.id }}</span>
                        <label class="inline-flex items-center gap-2 text-slate-700">
                            <input v-model="optionsForm.import_schedule" type="checkbox" class="rounded border-line">
                            Import Schedule to Planning (default OFF)
                        </label>
                        <label class="inline-flex items-center gap-2 text-slate-700">
                            <input v-model="optionsForm.replace_previous" type="checkbox" class="rounded border-line">
                            Replace Previous Import
                        </label>
                    </div>
                </AppCard>

                <AppCard v-if="detection" title="Step 2–3 — Detected sheets & mapping">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">Sheet</th>
                                    <th class="px-3 py-2 text-left">Detected type</th>
                                    <th class="px-3 py-2 text-right">Rows</th>
                                    <th class="px-3 py-2 text-left">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="sheet in detection.sheets" :key="sheet.name">
                                    <td class="px-3 py-2 font-medium text-slate-900">{{ sheet.name }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ sheet.label || 'Unmapped' }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ sheet.rows_detected ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ sheet.status }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">
                        Job Respons is ignored. Steel Summary / Material Consumption / Estimated labour are reconcile-only.
                        Schedule imports only when the checkbox above is enabled.
                    </p>
                </AppCard>

                <AppCard title="Step 4–5 — Preview & validation">
                    <div class="mb-3 flex flex-wrap gap-2">
                        <AppButton size="sm" :loading="optionsForm.processing" @click="runPreview">
                            Generate preview
                        </AppButton>
                        <AppButton
                            v-if="preview && (!errors || !errors.length)"
                            size="sm"
                            variant="primary"
                            :loading="optionsForm.processing"
                            @click="confirmImport"
                        >
                            Confirm import
                        </AppButton>
                        <a
                            v-if="importBatch.status === 'completed'"
                            :href="route('projects.boqs.workbook-import.report', [project.id, importBatch.id])"
                            class="inline-flex h-8 items-center rounded-lg border border-line bg-white px-3 text-xs font-medium text-slate-700"
                        >
                            Download report (JSON)
                        </a>
                    </div>

                    <div v-if="preview" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                        <div class="rounded-lg border border-line bg-slate-50 px-3 py-2">
                            <p class="text-[11px] uppercase text-slate-500">Sections</p>
                            <p class="font-semibold">{{ preview.sections }}</p>
                        </div>
                        <div class="rounded-lg border border-line bg-slate-50 px-3 py-2">
                            <p class="text-[11px] uppercase text-slate-500">Items</p>
                            <p class="font-semibold">{{ preview.items }}</p>
                        </div>
                        <div class="rounded-lg border border-line bg-slate-50 px-3 py-2">
                            <p class="text-[11px] uppercase text-slate-500">Materials / Labour</p>
                            <p class="font-semibold">{{ preview.material_components }} / {{ preview.labour_components }}</p>
                        </div>
                        <div class="rounded-lg border border-line bg-slate-50 px-3 py-2">
                            <p class="text-[11px] uppercase text-slate-500">BBS / RA / Quotes</p>
                            <p class="font-semibold">{{ preview.bbs_lines }} / {{ preview.rate_analyses }} / {{ preview.rate_comparison_quotes }}</p>
                        </div>
                    </div>

                    <div v-if="errors?.length" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        <p class="font-medium">Errors (block import)</p>
                        <ul class="mt-1 list-disc pl-5">
                            <li v-for="(err, i) in errors" :key="i">{{ err.message || err }}</li>
                        </ul>
                    </div>

                    <div v-if="warnings?.length" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <p class="font-medium">Warnings ({{ warnings.length }})</p>
                        <ul class="mt-1 max-h-40 list-disc overflow-y-auto pl-5">
                            <li v-for="(w, i) in warnings.slice(0, 40)" :key="i">{{ w }}</li>
                        </ul>
                    </div>

                    <div v-if="previewItems.length" class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">Section</th>
                                    <th class="px-3 py-2 text-left">Item</th>
                                    <th class="px-3 py-2 text-left">Unit</th>
                                    <th class="px-3 py-2 text-right">ERP Qty</th>
                                    <th class="px-3 py-2 text-right">Mats</th>
                                    <th class="px-3 py-2 text-left">Warning</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="(item, idx) in previewItems.slice(0, 80)" :key="idx">
                                    <td class="px-3 py-2 text-slate-700">{{ item.section }}{{ item.subsection ? ` / ${item.subsection}` : '' }}</td>
                                    <td class="px-3 py-2 text-slate-900">{{ item.name }}</td>
                                    <td class="px-3 py-2">{{ item.unit }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ item.erp_qty }}</td>
                                    <td class="px-3 py-2 text-right tabular">{{ item.materials?.length || 0 }}</td>
                                    <td class="px-3 py-2 text-amber-800">{{ item.warning || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </AppCard>

                <AppCard v-if="result" title="Step 6–7 — Import & reconciliation">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
                        <div><span class="text-slate-500">BOQ</span> <span class="font-medium">{{ result.boq_number }} v{{ result.boq_version }}</span></div>
                        <div><span class="text-slate-500">Items</span> <span class="font-medium">{{ result.items_created }}</span></div>
                        <div><span class="text-slate-500">Measurements</span> <span class="font-medium">{{ result.measurements_imported }}</span></div>
                        <div><span class="text-slate-500">Materials / Labour</span> <span class="font-medium">{{ result.material_components }} / {{ result.labour_components }}</span></div>
                        <div><span class="text-slate-500">BBS / RA</span> <span class="font-medium">{{ result.bbs_headers }} / {{ result.rate_analyses }}</span></div>
                        <div><span class="text-slate-500">Budget</span> <span class="font-medium text-amber-800">{{ result.budget_sync }}</span></div>
                    </div>
                    <p class="mt-3 text-sm text-slate-600">
                        No Budget / MR / PO / GRN / stock / finance / DPR side effects were created.
                    </p>
                    <Link
                        v-if="result.boq_id"
                        :href="route('projects.boqs.show', [project.id, result.boq_id])"
                        class="mt-3 inline-flex text-sm font-medium text-brand-700 hover:underline"
                    >
                        Open imported BOQ
                    </Link>
                </AppCard>
            </template>
        </div>
    </ProjectLayout>
</template>
