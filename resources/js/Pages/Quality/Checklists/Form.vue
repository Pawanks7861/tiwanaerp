<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    checklist: { type: Object, default: null },
    disciplines: { type: Array, required: true },
    can: { type: Object, required: true },
});

const editing = computed(() => !!props.checklist);
const readOnly = computed(() => !props.can.update);
const c = props.checklist;
let uid = 0;
const row = (item = {}) => ({ key: ++uid, id: item.id ?? null, checkpoint: item.checkpoint ?? '', acceptance_criteria: item.acceptance_criteria ?? '' });

const form = useForm({
    name: c?.name ?? '',
    discipline: c?.discipline ?? 'general',
    activity: c?.activity ?? '',
    is_active: c?.is_active ?? true,
    items: (c?.items ?? [{}]).map(row),
});

function addItem() {
    form.items.push(row());
}
function removeItem(index) {
    form.items.splice(index, 1);
}
function move(index, delta) {
    const target = index + delta;
    if (target < 0 || target >= form.items.length) {
        return;
    }
    const [item] = form.items.splice(index, 1);
    form.items.splice(target, 0, item);
}

function submit() {
    const transform = (d) => ({
        ...d,
        activity: d.activity || null,
        items: d.items.map(({ id, checkpoint, acceptance_criteria }) => ({ id, checkpoint, acceptance_criteria: acceptance_criteria || null })),
    });
    editing.value
        ? form.transform(transform).put(route('quality.checklists.update', props.checklist.id), { preserveScroll: true })
        : form.transform(transform).post(route('quality.checklists.store'));
}

const deleting = ref(false);
const processing = ref(false);
function destroy() {
    processing.value = true;
    router.delete(route('quality.checklists.destroy', props.checklist.id), { onFinish: () => ((processing.value = false), (deleting.value = false)) });
}
</script>

<template>
    <AppLayout :title="editing ? checklist.name : 'New checklist'">
        <PageHeader :title="editing ? checklist.name : 'New checklist'" :back="route('quality.checklists.index')" subtitle="Checkpoints are copied into each inspection when it is requested.">
            <template v-if="editing && can.delete" #actions>
                <AppButton variant="ghost" size="sm" icon="trash" @click="deleting = true">Delete</AppButton>
            </template>
        </PageHeader>
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <p v-if="form.errors.checklist" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.checklist }}</p>
            <p v-if="checklist?.in_use" class="rounded-lg border border-line bg-slate-50 px-4 py-2 text-xs text-slate-600">
                This checklist is used by inspections, so it cannot be deleted. Deactivate it to stop new inspections using it; past inspections keep their own copy.
            </p>
            <AppCard>
                <fieldset :disabled="readOnly" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormInput v-model="form.name" label="Name" required maxlength="150" class="sm:col-span-2" :error="form.errors.name" />
                    <FormSelect v-model="form.discipline" label="Discipline" required :options="disciplines" :error="form.errors.discipline" />
                    <FormInput v-model="form.activity" label="Activity" maxlength="150" placeholder="e.g. Slab concreting" :error="form.errors.activity" />
                    <FormSwitch v-model="form.is_active" label="Active" description="Inactive checklists cannot be used for new inspections." class="sm:col-span-2" />
                </fieldset>
            </AppCard>

            <AppCard title="Checkpoints" :subtitle="`${form.items.length} checkpoint${form.items.length === 1 ? '' : 's'}`" :padded="false">
                <template v-if="!readOnly" #actions>
                    <AppButton size="sm" variant="secondary" icon="plus" @click="addItem">Add checkpoint</AppButton>
                </template>
                <p v-if="form.errors.items" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items }}</p>
                <ol class="divide-y divide-line">
                    <li v-for="(item, index) in form.items" :key="item.key" class="grid gap-3 p-4 sm:grid-cols-[2rem_1fr_1fr_auto] sm:items-start">
                        <span class="pt-2 text-sm font-semibold text-slate-400 tabular">{{ index + 1 }}</span>
                        <FormInput v-model="item.checkpoint" :label="`Checkpoint ${index + 1}`" required maxlength="255" :disabled="readOnly" :error="form.errors[`items.${index}.checkpoint`]" />
                        <FormInput v-model="item.acceptance_criteria" label="Acceptance criteria" maxlength="500" :disabled="readOnly" :error="form.errors[`items.${index}.acceptance_criteria`]" />
                        <div v-if="!readOnly" class="flex gap-1 sm:pt-6">
                            <AppButton size="sm" variant="ghost" icon="chevron-down" class="rotate-180" aria-label="Move up" :disabled="index === 0" @click="move(index, -1)" />
                            <AppButton size="sm" variant="ghost" icon="chevron-down" aria-label="Move down" :disabled="index === form.items.length - 1" @click="move(index, 1)" />
                            <AppButton size="sm" variant="ghost" icon="trash" aria-label="Remove checkpoint" :disabled="form.items.length === 1" @click="removeItem(index)" />
                        </div>
                    </li>
                </ol>
            </AppCard>

            <div v-if="!readOnly" class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <AppButton variant="secondary" :href="route('quality.checklists.index')" class="flex-1 md:flex-none">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save checklist' : 'Create checklist' }}</AppButton>
            </div>
        </form>
        <ConfirmDialog
            :show="deleting"
            title="Delete this checklist?"
            message="It has never been used by an inspection, so it can be removed."
            confirm-label="Delete"
            :processing="processing"
            @close="deleting = false"
            @confirm="destroy"
        />
    </AppLayout>
</template>
