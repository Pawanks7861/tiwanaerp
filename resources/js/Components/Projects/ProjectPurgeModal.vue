<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    preview: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const confirmationCode = ref('');
const acknowledge = ref(false);
const processing = ref(false);

watch(
    () => props.show,
    (open) => {
        if (open) {
            confirmationCode.value = '';
            acknowledge.value = false;
        }
    },
);

const codeMatches = computed(
    () => confirmationCode.value.trim() === (props.preview?.confirmation_code ?? props.preview?.project?.code ?? ''),
);

const canSubmit = computed(() => codeMatches.value && acknowledge.value && !processing.value);

const countRows = computed(() => {
    const counts = props.preview?.counts ?? {};
    return Object.entries(counts)
        .filter(([, n]) => Number(n) > 0)
        .map(([key, n]) => ({
            label: key.replaceAll('_', ' '),
            value: n,
        }));
});

function submit() {
    if (!canSubmit.value) {
        return;
    }
    processing.value = true;
    router.delete(route('projects.purge', props.preview.project.id), {
        data: {
            confirmation_code: confirmationCode.value.trim(),
            acknowledge: true,
        },
        onFinish: () => {
            processing.value = false;
        },
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <AppModal :show="show" title="Delete Project & All Related Data" max-width="lg" @close="emit('close')">
        <div class="space-y-4 text-sm">
            <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-800">
                This will permanently delete this project and all project-related transactional data. This action cannot be undone.
            </p>

            <dl class="grid grid-cols-2 gap-3">
                <div>
                    <dt class="text-xs font-medium tracking-wide text-slate-500 uppercase">Project</dt>
                    <dd class="mt-0.5 font-medium text-slate-900">{{ preview.project.name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium tracking-wide text-slate-500 uppercase">Code</dt>
                    <dd class="mt-0.5 font-mono font-medium text-slate-900">{{ preview.project.code }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium tracking-wide text-slate-500 uppercase">Status</dt>
                    <dd class="mt-0.5 text-slate-800">{{ preview.project.status_label }}</dd>
                </div>
            </dl>

            <div v-if="preview.financial_warning" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">
                This project contains financial and inventory transactions.
            </div>

            <div v-if="preview.tally_warning" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">
                This project contains records previously exported to Tally ({{ preview.tally_exports }}).
                Deleting the ERP project will not automatically delete external Tally records.
            </div>

            <div v-if="countRows.length" class="max-h-48 overflow-auto rounded-lg border border-line">
                <table class="min-w-full text-xs">
                    <thead class="bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">Module</th>
                            <th class="px-3 py-2 text-right">Count</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in countRows" :key="row.label">
                            <td class="px-3 py-1.5 capitalize text-slate-700">{{ row.label }}</td>
                            <td class="px-3 py-1.5 text-right tabular text-slate-900">{{ row.value }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600">
                    Type <span class="font-mono font-semibold text-slate-900">{{ preview.confirmation_code }}</span> to permanently delete this project.
                </label>
                <input
                    v-model="confirmationCode"
                    type="text"
                    class="mt-1 w-full rounded-md border-slate-300 font-mono text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                    autocomplete="off"
                    :placeholder="preview.confirmation_code"
                />
            </div>

            <label class="flex items-start gap-2 text-slate-700">
                <input v-model="acknowledge" type="checkbox" class="mt-0.5 rounded border-slate-300 text-red-600 focus:ring-red-500" />
                <span>I understand that this action permanently deletes all project data and cannot be undone.</span>
            </label>
        </div>

        <template #footer>
            <AppButton variant="secondary" @click="emit('close')">Cancel</AppButton>
            <AppButton variant="danger" :disabled="!canSubmit" :loading="processing" @click="submit">
                Delete Project & All Related Data
            </AppButton>
        </template>
    </AppModal>
</template>
