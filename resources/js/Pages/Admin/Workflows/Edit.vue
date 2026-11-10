<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    type: { type: String, required: true },
    label: { type: String, required: true },
    group: { type: String, required: true },
    workflow: { type: Object, required: true },
    users: { type: Array, required: true },
    roles: { type: Array, required: true },
    approverTypes: { type: Array, required: true },
    projectRoles: { type: Array, required: true },
    modes: { type: Array, required: true },
    can: { type: Object, required: true },
});

const form = useForm({
    name: props.workflow.name,
    is_active: !!props.workflow.is_active,
    min_amount: props.workflow.min_amount,
    max_amount: props.workflow.max_amount,
    steps: props.workflow.steps.map(cloneStep),
});

const previewAmount = ref('');
const previewResult = ref(null);
const previewLoading = ref(false);

async function runPreview() {
    previewLoading.value = true;
    previewResult.value = null;
    try {
        const res = await fetch(route('admin.workflows.preview', props.type), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ amount: previewAmount.value }),
        });
        previewResult.value = await res.json();
    } finally {
        previewLoading.value = false;
    }
}

function cloneStep(step = {}) {
    return {
        name: step.name ?? '',
        approver_type: step.approver_type ?? 'user',
        user_id: step.user_id ?? null,
        role_id: step.role_id ?? null,
        project_role: step.project_role ?? null,
        mode: step.mode ?? 'any',
    };
}

function addStep() {
    form.steps.push(cloneStep());
}

function removeStep(index) {
    if (form.steps.length === 1) {
        return;
    }
    form.steps.splice(index, 1);
}

function moveStep(index, delta) {
    const next = index + delta;
    if (next < 0 || next >= form.steps.length) {
        return;
    }
    const copy = form.steps.splice(index, 1)[0];
    form.steps.splice(next, 0, copy);
}

function onTypeChange(step) {
    if (step.approver_type !== 'user') {
        step.user_id = null;
    }
    if (step.approver_type !== 'role') {
        step.role_id = null;
    }
    if (step.approver_type !== 'project_role') {
        step.project_role = null;
    }
}

function fieldError(index, field) {
    return form.errors[`steps.${index}.${field}`] ?? form.errors[`steps.${index}`] ?? null;
}

function submit() {
    form.put(route('admin.workflows.update', props.type), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="`${label} approval`">
        <PageHeader
            :title="`${label} approval`"
            :subtitle="`${group}. Choose a specific person, a company role, or the document’s project role for each step.`"
            :back="route('admin.workflows.index')"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Workflow">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.name" label="Workflow name" required :disabled="!can.manage" :error="form.errors.name" />
                    <div class="flex items-end pb-1">
                        <FormSwitch
                            v-model="form.is_active"
                            label="Active"
                            description="When off, new documents of this type cannot be submitted until you turn it back on."
                            :disabled="!can.manage"
                        />
                    </div>
                </div>
                <div class="mt-4 grid gap-4 border-t border-line pt-4 sm:grid-cols-2">
                    <DecimalInput v-model="form.min_amount" label="Minimum amount (optional)" prefix="₹" :disabled="!can.manage" :error="form.errors.min_amount" />
                    <DecimalInput v-model="form.max_amount" label="Maximum amount (optional)" prefix="₹" :disabled="!can.manage" :error="form.errors.max_amount" />
                </div>
                <div class="mt-4 border-t border-line pt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Preview</p>
                    <div class="mt-2 flex flex-wrap items-end gap-2">
                        <DecimalInput v-model="previewAmount" label="Document amount" prefix="₹" class="min-w-40" />
                        <AppButton type="button" size="sm" variant="secondary" :loading="previewLoading" @click="runPreview">Resolve workflow</AppButton>
                    </div>
                    <p v-if="previewResult?.workflow_name" class="mt-2 text-sm text-slate-700">
                        {{ previewResult.workflow_name }}
                        <span v-if="previewResult.steps?.length" class="text-slate-500">· {{ previewResult.steps.map((s) => s.name).join(' → ') }}</span>
                    </p>
                </div>
                <p v-if="form.errors.steps" class="mt-3 text-sm text-red-600">{{ form.errors.steps }}</p>
            </AppCard>

            <AppCard title="Approval steps" subtitle="Steps run in order. A named user is checked against this company’s active members.">
                <template #actions>
                    <AppButton v-if="can.manage" variant="secondary" size="sm" icon="plus" :disabled="form.steps.length >= 10" @click="addStep">
                        Add step
                    </AppButton>
                </template>

                <div class="space-y-4">
                    <div v-for="(step, index) in form.steps" :key="index" class="rounded-lg border border-line p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-800">Step {{ index + 1 }}</p>
                            <div v-if="can.manage" class="flex items-center gap-1">
                                <AppButton variant="ghost" size="sm" :disabled="index === 0" @click="moveStep(index, -1)">
                                    <Icon name="chevron-down" class="rotate-180" :size="16" />
                                    <span class="sr-only">Move up</span>
                                </AppButton>
                                <AppButton variant="ghost" size="sm" :disabled="index === form.steps.length - 1" @click="moveStep(index, 1)">
                                    <Icon name="chevron-down" :size="16" />
                                    <span class="sr-only">Move down</span>
                                </AppButton>
                                <AppButton variant="ghost" size="sm" icon="trash" :disabled="form.steps.length === 1" @click="removeStep(index)">
                                    Remove
                                </AppButton>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormInput v-model="step.name" label="Step name" required :disabled="!can.manage" :error="fieldError(index, 'name')" />
                            <FormSelect
                                v-model="step.approver_type"
                                label="Who approves"
                                required
                                :options="approverTypes"
                                :disabled="!can.manage"
                                :error="fieldError(index, 'approver_type')"
                                @update:model-value="onTypeChange(step)"
                            />
                            <SearchSelect
                                v-if="step.approver_type === 'user'"
                                v-model="step.user_id"
                                label="Specific user"
                                required
                                :options="users"
                                placeholder="Search people in this company"
                                :disabled="!can.manage"
                                :error="fieldError(index, 'user_id')"
                            />
                            <FormSelect
                                v-else-if="step.approver_type === 'role'"
                                v-model="step.role_id"
                                label="Company role"
                                required
                                :options="roles"
                                :disabled="!can.manage"
                                :error="fieldError(index, 'role_id')"
                            />
                            <FormSelect
                                v-else-if="step.approver_type === 'project_role'"
                                v-model="step.project_role"
                                label="Project role"
                                required
                                :options="projectRoles"
                                :disabled="!can.manage"
                                :error="fieldError(index, 'project_role')"
                                help="Resolved on the document’s project (for example that project’s manager)."
                            />
                            <FormSelect
                                v-model="step.mode"
                                label="If several people match"
                                :options="modes"
                                :disabled="!can.manage"
                                :error="fieldError(index, 'mode')"
                            />
                        </div>
                    </div>
                </div>
            </AppCard>

            <div v-if="can.manage" class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <AppButton variant="secondary" :href="route('admin.workflows.index')">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing">Save steps</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
